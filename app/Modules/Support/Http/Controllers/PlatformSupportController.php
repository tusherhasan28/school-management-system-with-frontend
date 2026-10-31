<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Modules\Support\Models\SupportAccessGrant;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Modules\Support\Services\SupportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Platform side of support: every school's tickets, replies, access requests and the audited snapshot. */
class PlatformSupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in([...SupportTicket::STATUSES, 'active'])], 'mine' => ['nullable', 'boolean']]);
        $status = $filters['status'] ?? 'active';

        return Inertia::render('platform/support/index', [
            'tickets' => SupportTicket::query()->with(['school:id,name', 'assignee:id,name'])
                ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'waiting']), fn ($q) => $q->where('status', $status))
                ->when($filters['mine'] ?? false, fn ($q) => $q->where('assigned_platform_user_id', $request->user('platform')->id))
                ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")->latest('last_activity_at')
                ->paginate(25)->withQueryString()
                ->through(fn (SupportTicket $t) => $t->only(['id', 'reference', 'subject', 'category', 'priority', 'status'])
                    + ['school' => $t->school?->name, 'assignee' => $t->assignee?->name, 'last_activity_at' => $t->last_activity_at?->toIso8601String()]),
            'grants' => SupportAccessGrant::query()->with('school:id,name')->where('platform_user_id', $request->user('platform')->id)->latest('id')->limit(10)->get()
                ->map(fn (SupportAccessGrant $g) => $this->grant($g)),
            'filters' => ['status' => $status, 'mine' => (bool) ($filters['mine'] ?? false)],
        ]);
    }

    public function show(Request $request, SupportTicket $ticket): Response
    {
        $ticket->load(['school:id,name,slug', 'assignee:id,name']);

        return Inertia::render('platform/support/show', [
            'ticket' => $ticket->only(['id', 'reference', 'subject', 'category', 'priority', 'status']) + [
                'school' => $ticket->school->only(['id', 'name', 'slug']), 'assignee' => $ticket->assignee?->name, 'created_at' => $ticket->created_at?->toIso8601String(),
            ],
            'messages' => $ticket->messages->map(fn (SupportTicketMessage $m) => [
                'id' => $m->id, 'from' => $m->sender_type, 'name' => $m->sender_name, 'message' => $m->message, 'internal' => $m->is_internal, 'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'grants' => SupportAccessGrant::query()->where('school_id', $ticket->school_id)->where('platform_user_id', $request->user('platform')->id)->latest('id')->limit(5)->get()
                ->map(fn (SupportAccessGrant $g) => $this->grant($g)),
            'maxHours' => (int) config('billing.support_access_max_hours', 24),
            'can' => ['manage' => $request->user('platform')->can('support.manage')],
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:10000'], 'internal' => ['boolean']]);
        $this->support->replyFromPlatform($ticket, $request->user('platform'), $data['message'], (bool) ($data['internal'] ?? false));

        return back();
    }

    public function status(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(SupportTicket::STATUSES)], 'assign_to_me' => ['boolean']]);
        $this->support->setStatus($ticket, $data['status'], ($data['assign_to_me'] ?? false) ? $request->user('platform') : null);

        return back();
    }

    public function requestAccess(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'duration_hours' => ['required', 'integer'],
            'support_ticket_id' => ['nullable', 'integer'],
        ]);
        $ticket = isset($data['support_ticket_id']) ? SupportTicket::query()->findOrFail($data['support_ticket_id']) : null;
        $this->support->requestAccess($request->user('platform'), $school, $data['reason'], (int) $data['duration_hours'], $ticket);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('support.access_requested')]);

        return back();
    }

    public function snapshot(Request $request, SupportAccessGrant $grant): Response
    {
        $data = $this->support->snapshot($grant, $request->user('platform'));
        $grant->load('school:id,name,slug,status');

        return Inertia::render('platform/support/snapshot', ['grant' => $this->grant($grant)] + $data);
    }

    /** @return array<string, mixed> */
    private function grant(SupportAccessGrant $g): array
    {
        return [
            'id' => $g->id, 'school' => $g->relationLoaded('school') ? $g->school?->name : null, 'school_id' => $g->school_id, 'reason' => $g->reason,
            'duration_hours' => $g->duration_hours, 'status' => $g->effectiveStatus(), 'expires_at' => $g->expires_at?->toIso8601String(),
            'created_at' => $g->created_at?->toIso8601String(), 'view_count' => $g->view_count,
        ];
    }
}
