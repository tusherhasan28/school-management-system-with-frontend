<?php

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Billing\Services\Entitlements;
use App\Modules\Messaging\Jobs\DeliverMessage;
use App\Modules\Messaging\Models\NotificationLog;
use App\Modules\Messaging\Models\SmsProviderAccount;
use App\Modules\Messaging\Providers\SmsProviderRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends email and SMS for the current school: renders the template in the
 * recipient's language, writes one delivery log per recipient and channel,
 * and queues the delivery (rate-limited, retried, see DeliverMessage).
 */
class MessageService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MessageTemplates $templates,
        private readonly SmsProviderRegistry $providers,
        private readonly Entitlements $entitlements,
    ) {}

    /** The school's enabled SMS account whose provider is installed, if any. */
    public function smsAccount(): ?SmsProviderAccount
    {
        return SmsProviderAccount::query()->where('is_enabled', true)->get()->first(fn (SmsProviderAccount $a) => $this->providers->get($a->provider) !== null);
    }

    /**
     * Channels the school chose for an automatic message (Messages → Settings).
     *
     * @return list<string>
     */
    public function channelsFor(string $templateKey): array
    {
        $chosen = app(\App\Modules\Setup\Services\SchoolSettings::class)->get('messaging.channels.'.$templateKey);

        return array_values(array_intersect(['sms', 'mail'], is_array($chosen) ? $chosen : config('messaging.defaults.'.$templateKey, ['mail'])));
    }

    /**
     * The primary guardian of a student as a message recipient.
     *
     * @param  array<string, string>  $data
     * @return array<string, mixed>|null
     */
    public function guardianRecipient(\App\Modules\People\Models\Student $student, array $data): ?array
    {
        $guardian = $student->relationLoaded('guardians') ? $student->guardians->first() : $student->guardians()->wherePivot('is_primary', true)->first();

        if ($guardian === null) {
            return null;
        }

        $bn = ($this->tenant->require()->locale ?? 'bn') !== 'en';

        return [
            'name' => $guardian->full_name, 'phone' => $guardian->phone, 'email' => $guardian->email, 'user_id' => $guardian->user_id,
            'data' => ['student' => $bn && $student->name_bn ? $student->name_bn : $student->full_name] + $data,
        ];
    }

    public function smsAvailable(): bool
    {
        return $this->entitlements->enabled('sms') && $this->smsAccount() !== null;
    }

    /**
     * @param  list<array{name?: string|null, phone?: string|null, email?: string|null, user_id?: int|null, locale?: string|null, data?: array<string, string>}>  $recipients
     * @param  list<string>  $channels  'sms' and/or 'mail'
     * @return array{batch_id: string, queued: int, skipped: int}
     */
    public function send(string $templateKey, array $recipients, array $channels, ?User $by = null): array
    {
        $school = $this->tenant->require();
        $batch = (string) Str::uuid();
        $channels = array_values(array_intersect(['sms', 'mail'], $channels));
        $sms = in_array('sms', $channels, true) ? $this->smsAccount() : null;
        $smsAllowed = $sms !== null && $this->entitlements->enabled('sms');
        $quota = $this->entitlements->remaining('sms_per_month'); // null = unlimited
        $counts = ['queued' => 0, 'skipped' => 0];
        $created = [];

        DB::transaction(function () use ($templateKey, $recipients, $channels, $by, $school, $batch, $sms, $smsAllowed, &$quota, &$counts, &$created) {
            foreach ($recipients as $r) {
                $locale = in_array($r['locale'] ?? null, ['bn', 'en'], true) ? $r['locale'] : ($school->locale === 'en' ? 'en' : 'bn');
                $data = ($r['data'] ?? []) + ['school' => $locale === 'bn' && $school->name_bn ? $school->name_bn : $school->name];

                foreach ($channels as $channel) {
                    $address = $channel === 'sms' ? PhoneNumber::normalize($r['phone'] ?? null) : (filter_var($r['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null);

                    if ($address === null) {
                        $counts['skipped']++; // no usable number/address: nothing to log or send

                        continue;
                    }

                    $text = $this->templates->render($templateKey, $channel, $locale, $data);
                    $log = new NotificationLog([
                        'batch_id' => $batch, 'user_id' => $r['user_id'] ?? null, 'channel' => $channel, 'recipient' => Str::lower($address),
                        'recipient_name' => $r['name'] ?? null, 'template_key' => $templateKey, 'locale' => $locale,
                        'subject' => $text['subject'], 'body' => $text['body'],
                        'segments' => $channel === 'sms' ? MessageTemplates::segments($text['body']) : 1,
                        'provider' => $channel === 'sms' ? $sms?->provider : 'mail', 'created_by' => $by?->id,
                    ]);

                    if ($channel === 'sms') {
                        $reason = match (true) {
                            ! $smsAllowed => 'SMS is not set up for this school.',
                            $quota !== null && $quota < $log->segments => 'Monthly SMS limit of the plan reached.',
                            default => null,
                        };

                        if ($reason !== null) {
                            $log->status = 'skipped';
                            $log->error_message = $reason;
                            $log->save();
                            $counts['skipped']++;

                            continue;
                        }

                        if ($quota !== null) {
                            $quota -= $log->segments;
                        }
                    }

                    $log->save();
                    $created[] = $log->id;
                    $counts['queued']++;
                }
            }
        });

        $perMinute = $sms ? ($this->providers->get($sms->provider)?->perMinute() ?? 60) : 120;

        foreach ($created as $id) {
            DeliverMessage::dispatch($id, $perMinute)->afterCommit();
        }

        return ['batch_id' => $batch] + $counts;
    }

    /** Put a failed message back in the queue (same text, same recipient). */
    public function retry(NotificationLog $log): void
    {
        if ($log->status !== 'failed') {
            return;
        }

        $log->forceFill(['status' => 'queued', 'error_message' => null])->save();
        DeliverMessage::dispatch($log->id, $log->channel === 'sms' ? ($this->providers->get((string) $log->provider)?->perMinute() ?? 60) : 120)->afterCommit();
    }
}
