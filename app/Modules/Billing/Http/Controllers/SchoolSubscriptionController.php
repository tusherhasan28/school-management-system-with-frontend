<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\PlatformInvoice;
use App\Modules\Billing\Services\BillingPresenter;
use App\Modules\Billing\Services\Entitlements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * School side: read-only view of its plan, limits, usage and invoices.
 * There is deliberately no endpoint here that changes any of it.
 */
class SchoolSubscriptionController extends Controller
{
    public function __construct(private readonly BillingPresenter $present, private readonly TenantContext $tenant) {}

    public function show(Request $request, Entitlements $entitlements): Response
    {
        $this->authorize('subscription.view');
        $subscription = $entitlements->subscription();

        return Inertia::render('app/subscription/show', [
            'subscription' => $this->present->subscription($subscription),
            'features' => $entitlements->overview(),
            'invoices' => PlatformInvoice::query()->where('school_id', $this->tenant->require()->id)->where('status', '!=', 'void')
                ->orderByDesc('issued_at')->orderByDesc('id')->limit(36)->get()->map(fn ($i) => $this->present->invoice($i)),
            'instructions' => config('billing.payment_instructions'),
            'exports' => $request->user()->can('settings.manage') ? \App\Modules\Exports\Models\DataExport::query()->latest('id')->limit(5)->get()->map(fn ($e) => [
                'id' => $e->id, 'status' => $e->isDownloadable() || $e->status !== 'ready' ? $e->status : 'expired', 'size_bytes' => $e->size_bytes,
                'counts' => $e->counts, 'created_at' => $e->created_at?->toIso8601String(), 'expires_at' => $e->expires_at?->toIso8601String(),
            ]) : null,
        ]);
    }

    public function invoice(Request $request, int $invoice): Response
    {
        $this->authorize('subscription.view');
        $school = $this->tenant->require();
        $item = PlatformInvoice::query()->with('payments')->where('school_id', $school->id)->findOrFail($invoice);

        return Inertia::render('app/subscription/invoice', [
            'invoice' => $this->present->invoice($item) + ['subtotal' => $item->subtotal, 'discount' => $item->discount, 'tax' => $item->tax],
            'payments' => $item->payments->map(fn ($p) => $this->present->payment($p)),
            'school' => $school->only(['name', 'name_bn', 'address', 'phone', 'email']),
            'platform' => config('app.name'),
            'instructions' => $item->status === 'unpaid' ? config('billing.payment_instructions') : null,
        ]);
    }
}
