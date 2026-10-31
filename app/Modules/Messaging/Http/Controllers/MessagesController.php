<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Billing\Services\Entitlements;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Messaging\Models\NotificationLog;
use App\Modules\Messaging\Models\NotificationTemplate;
use App\Modules\Messaging\Models\SmsProviderAccount;
use App\Modules\Messaging\Providers\SmsProviderRegistry;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessageTemplates;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\StaffMember;
use App\Modules\People\Models\Student;
use App\Modules\Setup\Services\SchoolSettings;
use App\Services\Audit\AuditLogger;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Messages screen: delivery log, sending to guardians or staff (bulk, queued),
 * fee reminders, and the school's SMS/template settings.
 */
class MessagesController extends Controller
{
    public const AUDIENCES = ['guardians_all', 'guardians_class', 'guardians_overdue', 'staff'];

    public function __construct(
        private readonly MessageService $messages,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Entitlements $entitlements, AcademicOptions $options): Response
    {
        $this->authorize('messages.send');
        $filters = $request->validate([
            'channel' => ['nullable', Rule::in(['sms', 'mail'])],
            'status' => ['nullable', Rule::in(['queued', 'sent', 'failed', 'skipped'])],
            'batch' => ['nullable', 'uuid'],
        ]);

        $logs = NotificationLog::query()
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['batch'] ?? null, fn ($q, $b) => $q->where('batch_id', $b))
            ->latest('id')->paginate(30)->withQueryString()
            ->through(fn (NotificationLog $l) => [
                'id' => $l->id, 'channel' => $l->channel, 'recipient' => $l->maskedRecipient(), 'name' => $l->recipient_name,
                'template' => $l->template_key, 'body' => $l->body, 'segments' => $l->segments, 'status' => $l->status,
                'attempts' => $l->attempts, 'error' => $l->error_message, 'sent_at' => $l->sent_at?->toIso8601String(),
                'created_at' => $l->created_at?->toIso8601String(), 'batch_id' => $l->batch_id,
            ]);

        $month = NotificationLog::query()->where('created_at', '>=', now()->startOfMonth());

        return Inertia::render('app/messages/index', [
            'logs' => $logs,
            'filters' => ['channel' => $filters['channel'] ?? '', 'status' => $filters['status'] ?? '', 'batch' => $filters['batch'] ?? ''],
            'stats' => [
                'sms_used' => (int) (clone $month)->where('channel', 'sms')->whereIn('status', ['queued', 'sent'])->sum('segments'),
                'sms_limit' => $entitlements->limit('sms_per_month'),
                'sent' => (clone $month)->where('status', 'sent')->count(),
                'failed' => (clone $month)->where('status', 'failed')->count(),
            ],
            'smsAvailable' => $this->messages->smsAvailable(),
            'classes' => $options->classes(),
            'can' => ['manage' => $request->user()->can('messages.manage')],
        ]);
    }

    /** Send one message to a group (queued; returns at once). */
    public function send(Request $request): RedirectResponse
    {
        $this->authorize('messages.send');
        $data = $request->validate([
            'audience' => ['required', Rule::in(self::AUDIENCES)],
            'class_id' => ['nullable', 'required_if:audience,guardians_class', 'integer', TenantRule::exists('classes')],
            'section_id' => ['nullable', 'integer', TenantRule::exists('sections')->where('class_id', $request->integer('class_id'))],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['sms', 'mail'])],
            'message' => ['nullable', 'required_unless:audience,guardians_overdue', 'string', 'max:480'],
        ]);

        if (in_array('sms', $data['channels'], true) && ! $this->messages->smsAvailable()) {
            throw ValidationException::withMessages(['channels' => __('messaging.sms_not_set_up')]);
        }

        [$template, $recipients] = $data['audience'] === 'guardians_overdue'
            ? ['fees.reminder', $this->overdueRecipients()]
            : ['general', $this->recipients($data)->map(fn ($r) => $r + ['data' => ['message' => $data['message']]])->all()];

        if ($recipients === []) {
            throw ValidationException::withMessages(['audience' => __('messaging.no_recipients')]);
        }

        $result = $this->messages->send($template, $recipients, $data['channels'], $request->user());
        $this->audit->tenant('messages.sent', null, [], ['audience' => $data['audience'], 'channels' => $data['channels'], 'template' => $template, 'queued' => $result['queued'], 'skipped' => $result['skipped']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('messaging.queued', ['queued' => $result['queued'], 'skipped' => $result['skipped']])]);

        return to_route('messages.index', ['batch' => $result['batch_id']]);
    }

    public function retry(int $log): RedirectResponse
    {
        $this->authorize('messages.send');
        $this->messages->retry(NotificationLog::query()->findOrFail($log));

        return back();
    }

    public function settings(Request $request, SmsProviderRegistry $providers, SchoolSettings $settings): Response
    {
        $this->authorize('messages.manage');
        $accounts = SmsProviderAccount::query()->get()->keyBy('provider');

        return Inertia::render('app/messages/settings', [
            'providers' => collect($providers->all())->map(fn ($p, $key) => [
                'key' => $key, 'label' => $p->label(), 'fields' => $p->credentialFields(), 'per_minute' => $p->perMinute(),
                'enabled' => (bool) $accounts->get($key)?->is_enabled, 'sender_id' => $accounts->get($key)?->sender_id,
                'has_credentials' => $accounts->get($key)?->credentials !== null,
            ])->values(),
            'smsInPlan' => app(Entitlements::class)->enabled('sms'),
            'automatic' => collect(['attendance.absent', 'results.published'])->map(fn ($k) => ['key' => $k, 'channels' => $this->messages->channelsFor($k)])->values(),
            'templates' => collect(MessageTemplates::DEFAULTS)->map(function ($def, $key) {
                $custom = NotificationTemplate::query()->where('template_key', $key)->get()->keyBy('channel');

                return [
                    'key' => $key, 'placeholders' => $def['placeholders'],
                    'sms' => ['default_en' => $def['sms']['en'], 'default_bn' => $def['sms']['bn'], 'body' => $custom->get('sms')?->body, 'body_bn' => $custom->get('sms')?->body_bn],
                    'mail' => ['default_en' => $def['mail']['en'], 'default_bn' => $def['mail']['bn'], 'default_subject_en' => $def['mail']['subject_en'], 'default_subject_bn' => $def['mail']['subject_bn'],
                        'subject' => $custom->get('mail')?->subject, 'subject_bn' => $custom->get('mail')?->subject_bn, 'body' => $custom->get('mail')?->body, 'body_bn' => $custom->get('mail')?->body_bn],
                ];
            })->values(),
        ]);
    }

    public function saveProvider(Request $request, SmsProviderRegistry $providers): RedirectResponse
    {
        $this->authorize('messages.manage');
        $provider = $providers->get((string) $request->input('provider')) ?? throw ValidationException::withMessages(['provider' => __('messaging.unknown_provider')]);
        $rules = ['provider' => ['required'], 'sender_id' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 .\-]+$/'], 'is_enabled' => ['boolean'], 'credentials' => ['array']];

        foreach ($provider->credentialFields() as $field) {
            $rules['credentials.'.$field['key']] = ['nullable', 'string', 'max:500'];
        }

        $data = $request->validate($rules);
        $account = SmsProviderAccount::query()->firstOrNew(['provider' => $provider->key()]);
        // Secrets are write-only: an empty field keeps the stored value.
        $credentials = array_filter($data['credentials'] ?? [], fn ($v) => $v !== null && $v !== '') + ($account->credentials ?? []);
        $account->fill(['sender_id' => $data['sender_id'] ?? null, 'is_enabled' => (bool) ($data['is_enabled'] ?? false), 'credentials' => $credentials ?: null])->save();

        if ($account->is_enabled) {
            SmsProviderAccount::query()->where('id', '!=', $account->id)->update(['is_enabled' => false]); // one active provider
        }

        $this->audit->tenant('messages.provider_saved', $account, [], ['provider' => $account->provider, 'enabled' => $account->is_enabled, 'credential_keys' => array_keys($credentials)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('messaging.saved')]);

        return back();
    }

    public function saveAutomatic(Request $request, SchoolSettings $settings): RedirectResponse
    {
        $this->authorize('messages.manage');
        $data = $request->validate(['key' => ['required', Rule::in(['attendance.absent', 'results.published'])], 'channels' => ['array'], 'channels.*' => [Rule::in(['sms', 'mail'])]]);
        $settings->put('messaging.channels.'.$data['key'], array_values(array_unique($data['channels'] ?? [])));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('messaging.saved')]);

        return back();
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        $this->authorize('messages.manage');
        $data = $request->validate([
            'key' => ['required', Rule::in(array_keys(MessageTemplates::DEFAULTS))],
            'channel' => ['required', Rule::in(['sms', 'mail'])],
            'subject' => ['nullable', 'string', 'max:190'],
            'subject_bn' => ['nullable', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:2000'],
            'body_bn' => ['nullable', 'string', 'max:2000'],
        ]);

        // Empty text everywhere = back to the built-in wording.
        if (blank($data['body'] ?? null) && blank($data['body_bn'] ?? null)) {
            NotificationTemplate::query()->where('template_key', $data['key'])->where('channel', $data['channel'])->delete();
        } else {
            NotificationTemplate::query()->updateOrCreate(['template_key' => $data['key'], 'channel' => $data['channel']], [
                'subject' => $data['subject'] ?? null, 'subject_bn' => $data['subject_bn'] ?? null,
                'body' => $data['body'] ?: ($data['body_bn'] ?? ''), 'body_bn' => $data['body_bn'] ?? null, 'is_active' => true,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('messaging.saved')]);

        return back();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recipients(array $data): Collection
    {
        if ($data['audience'] === 'staff') {
            return StaffMember::query()->where('status', 'active')->get()
                ->map(fn (StaffMember $s) => ['name' => $s->full_name, 'phone' => $s->phone, 'email' => $s->email, 'user_id' => $s->user_id]);
        }

        $students = Student::query()->where('status', 'active')
            ->when($data['audience'] === 'guardians_class', fn ($q) => $q->whereHas('enrollments', fn ($q) => $q->where('class_id', $data['class_id'])->where('status', 'enrolled')
                ->when($data['section_id'] ?? null, fn ($q, $s) => $q->where('section_id', $s))))
            ->select('id');

        // One message per guardian, even with several children in the group.
        return Guardian::query()->whereHas('students', fn ($q) => $q->whereIn('students.id', $students))->get()
            ->map(fn (Guardian $g) => ['name' => $g->full_name, 'phone' => $g->phone, 'email' => $g->email, 'user_id' => $g->user_id]);
    }

    /** @return list<array<string, mixed>> */
    private function overdueRecipients(): array
    {
        $today = now(app(\App\Support\Tenancy\TenantContext::class)->require()->timezone)->toDateString();

        return FeeInvoice::query()->open()->where('due_date', '<', $today)
            ->with(['student' => fn ($q) => $q->select(['id', 'school_id', 'full_name', 'name_bn', 'status'])->with(['guardians' => fn ($q) => $q->wherePivot('is_primary', true)])])
            ->get()->groupBy('student_id')
            ->map(function (Collection $invoices) {
                $student = $invoices->first()->student;
                $due = $invoices->sum(fn (FeeInvoice $i) => $i->balance());

                return $student && $student->status === 'active' && $due > 0
                    ? $this->messages->guardianRecipient($student, ['amount' => Money::format((int) $due).' BDT', 'due_date' => CarbonImmutable::parse($invoices->min('due_date'))->toDateString()])
                    : null;
            })->filter()->values()->all();
    }
}
