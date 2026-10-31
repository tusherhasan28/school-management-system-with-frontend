<?php

namespace App\Console\Commands;

use App\Modules\Billing\Services\SubscriptionService;
use Illuminate\Console\Command;

/** Daily subscription run: renewal invoices, period ends, grace periods, suspensions. */
class RunBilling extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Issue renewal invoices and move subscriptions through trial, grace and suspension';

    public function handle(SubscriptionService $subscriptions): int
    {
        $counts = $subscriptions->tick();

        foreach ($counts as $step => $n) {
            $this->line(sprintf('%-10s %d', $step, $n));
        }

        return self::SUCCESS;
    }
}
