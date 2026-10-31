<?php

namespace App\Modules\Exports\Jobs;

use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Services\SchoolDataExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Builds a school's data export in the background (tenant restored by QueueTenancy). */
class ExportSchoolData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $exportId) {}

    public function handle(SchoolDataExporter $exporter): void
    {
        $export = DataExport::query()->find($this->exportId);

        if ($export !== null && $export->status === 'queued') {
            $exporter->run($export);
        }
    }

    public function failed(?Throwable $e): void
    {
        DataExport::query()->whereKey($this->exportId)->update(['status' => 'failed', 'error_message' => $e ? class_basename($e) : 'failed']);
    }
}
