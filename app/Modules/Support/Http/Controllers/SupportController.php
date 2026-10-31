<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\SupportAccessGrant;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Modules\Support\Services\SupportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * School side of support: the school's own tickets and the access requests
 * platform staff have made. Ticket and grant ids are always looked up with
 * the school id from the host, so another school's ids are 404.
 */
class SupportController extends Controller
{
    public function __construct(private readonly SupportService $support, private readonly TenantContext $tenant) {}

    public function index(Request $request): Response
    {
        $this->authorize('support.manage');
        $schoolId = $this->tenant->require()->id;

        return Inertia::render('app/support/index', [
            'tickets' => SupportTicket::query()->where('school_id', $schoolId)->orderByRaw("status IN ('resolved', 'closed')")->latest('last_activity_at')->limit(100)->get()
                ->map(fn (SupportTicket $t) => $t->only(['id', 'reference', 'subject', 'category', 'priority', 'status']) + ['last_activity_at' => $t->last_activity_at?->toIso8601String()]),
            'grants' => SupportAccessGrant::query()->with('platformUser:id,name')->where('school_id', $schoolId)->latest('id')->limit(30)->get()
                ->map(fn (SupportAccessGrant $g) => [
                    'id' => $g->id, 'agent' => $g->platformUser?->name, 'reason' => $g->reason, 'duration_hours' => $g->duration_hours,
                    'status' => $g->effectiveStatus(), 'expires_at' => $g->expires_at?->toIso8601String(), 'created_at' => $g->created_at?->toIso8601String(),
                    'view_count' => $g->view_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('support.manage');
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'message' => ['required', 'string', 'max:10000'],
        ]);
        $ticket = $this->support->open($this->tenant->require(), $request->user(), $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('support.opened', ['reference' => $ticket->reference])]);

        return to_route('support.show', $ticket->id);
    }

    public function show(Request $request, int $ticket): Response
    {
        $this->authorize('support.manage');
        $item = $this->ticket($ticket);

        return Inertia::render('app/support/show', [
            'ticket' => $item->only(['id', 'reference', 'subject', 'category', 'priority', 'status']) + ['created_at' => $item->created_at?->toIso8601String()],
            // Internal platform notes never reach the school.
            'messages' => $item->messages()->where('is_internal', false)->get()->map(fn (SupportTicketMessage $m) => [
                'id' => $m->id, 'from' => $m->sender_type, 'name' => $m->sender_type === 'platform' ? __('support.platform_team').' ('.$m->sender_name.')' : $m->sender_name,
                'message' => $m->message, 'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function reply(Request $request, int $ticket): RedirectResponse
    {
        $this->authorize('support.manage');
        $data = $request->validate(['message' => ['required', 'string', 'max:10000']]);
        $this->support->replyFromSchool($this->ticket($ticket), $request->user(), $data['message']);

        return back();
    }

    public function close(int $ticket): RedirectResponse
    {
        $this->authorize('support.manage');
        $this->support->setStatus($this->ticket($ticket), 'closed');

        return back();
    }

    public function decide(Request $request, int $grant): RedirectResponse
    {
        $this->authorize('support.manage');
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $this->support->decide($this->grant($grant), $request->user(), (bool) $data['approve']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('support.decided')]);

        return back();
    }

    public function revoke(Request $request, int $grant): RedirectResponse
    {
        $this->authorize('support.manage');
        $this->support->revoke($this->grant($grant), $request->user());

        return back();
    }

    private function ticket(int $id): SupportTicket
    {
        return SupportTicket::query()->where('school_id', $this->tenant->require()->id)->findOrFail($id);
    }

    private function grant(int $id): SupportAccessGrant
    {
        return SupportAccessGrant::query()->where('school_id', $this->tenant->require()->id)->findOrFail($id);
    }
}
