<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Modules\Finance\Jobs\ReconcilePendingPayments;
use App\Modules\Finance\Services\BillingService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled finance work for every accessible school, each inside its own
 * tenant context: late fees (daily) and reconciliation of pending online
 * payments (frequently).
 */
class RunFinanceTasks extends Command
{
    protected $signature = 'finance:run {task : late-fees|reconcile}';

    protected $description = 'Apply late fees or reconcile pending online payments for every school';

    public function handle(TenantContext $tenant): int
    {
        $task = $this->argument('task');

        if (! in_array($task, ['late-fees', 'reconcile'], true)) {
            $this->error('Unknown task.');

            return self::INVALID;
        }

        School::query()->orderBy('id')->each(function (School $school) use ($tenant, $task) {
            if (! $school->isAccessible()) {
                return;
            }

            $tenant->run($school, function () use ($school, $task) {
                if ($task === 'late-fees') {
                    $count = app(BillingService::class)->applyLateFees(CarbonImmutable::now($school->timezone)->startOfDay());
                    $this->line("{$school->slug}: {$count} late fees");
                } else {
                    ReconcilePendingPayments::dispatch();
                }
            });
        });

        return self::SUCCESS;
    }
}
