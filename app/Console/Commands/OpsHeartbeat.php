<?php

namespace App\Console\Commands;

use App\Support\Ops\HealthChecks;
use Illuminate\Console\Command;

/** Scheduler heartbeat for hosts where cron runs each task directly (no schedule:run). */
class OpsHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Record that scheduled tasks are running';

    public function handle(): int
    {
        HealthChecks::beat();

        return self::SUCCESS;
    }
}
