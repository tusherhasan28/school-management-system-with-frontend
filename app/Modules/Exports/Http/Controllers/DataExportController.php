<?php

namespace App\Modules\Exports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exports\Jobs\ExportSchoolData;
use App\Modules\Exports\Models\DataExport;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** School owners/admins export all of the school's records (settings.manage). */
class DataExportController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        if (DataExport::query()->whereIn('status', ['queued', 'processing'])->exists()) {
            throw ValidationException::withMessages(['export' => __('exports.already_running')]);
        }

        $export = DataExport::create(['requested_by' => $request->user()->id]);
        $this->audit->tenant('data.export_requested', $export);
        ExportSchoolData::dispatch($export->id)->afterCommit();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exports.queued')]);

        return back();
    }

    public function download(Request $request, DataExport $export, TenantStorage $storage): StreamedResponse
    {
        $this->authorize('settings.manage');
        abort_unless($export->isDownloadable() && $storage->owns($export->path) && $storage->disk()->exists($export->path), 404);
        $this->audit->tenant('data.export_downloaded', $export);

        return $storage->disk()->download($export->path, 'school-data-'.$export->created_at?->format('Y-m-d').'.zip');
    }
}
