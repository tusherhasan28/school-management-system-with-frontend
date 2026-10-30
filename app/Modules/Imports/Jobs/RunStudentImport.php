<?php

namespace App\Modules\Imports\Jobs;

use App\Jobs\Middleware\EnsureTenantIsAccessible;
use App\Modules\Imports\Models\DataImportJob;
use App\Modules\Imports\Services\StudentImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Carries only the import id; the school comes from the payload (QueueTenancy)
 * and the lookup below is tenant scoped, so a forged id from another school
 * finds nothing.
 */
class RunStudentImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $importId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new EnsureTenantIsAccessible];
    }

    public function handle(StudentImportService $imports): void
    {
        $job = DataImportJob::query()->find($this->importId);

        if ($job === null || $job->status !== 'queued') {
            return;
        }

        $imports->run($job);
    }
}
