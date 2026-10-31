<?php

namespace App\Console\Commands;

use App\Support\Ops\HealthChecks;
use App\Support\Ops\OpsAlertMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Runs the health checks. With --alert (as scheduled) it emails OPS_ALERT_EMAIL
 * about newly failing checks (at most once per cooldown per check) and once
 * when they recover, and logs failures at critical level.
 */
class OpsHealth extends Command
{
    protected $signature = 'ops:health {--alert : Email and log failing checks}';

    protected $description = 'Check database, cache, queues, failed jobs, Horizon, scheduler, backups and disk';

    public function handle(HealthChecks $checks): int
    {
        $results = $checks->run();

        $this->table(['Check', 'Status', 'Detail'], collect($results)->map(
            fn ($r, $name) => [$name, $r['ok'] ? 'ok' : 'FAIL', $r['detail']]
        )->values()->all());

        $failing = array_filter($results, fn ($r) => ! $r['ok']);

        if ($this->option('alert')) {
            $this->sendAlerts($results, $failing);
        }

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, array{ok: bool, detail: string}>  $results
     * @param  array<string, array{ok: bool, detail: string}>  $failing
     */
    private function sendAlerts(array $results, array $failing): void
    {
        $cooldown = now()->addMinutes(max(1, (int) config('ops.alert_cooldown_minutes')));
        $toSend = [];
        $recovered = [];

        foreach ($results as $name => $result) {
            $key = "ops:alerting:{$name}";
            if (! $result['ok']) {
                Log::critical("ops health check failed: {$name}", ['detail' => $result['detail']]);
                if (Cache::add("ops:alert-sent:{$name}", true, $cooldown)) {
                    $toSend[$name] = $result;
                }
                Cache::forever($key, true);
            } elseif (Cache::pull($key)) {
                Cache::forget("ops:alert-sent:{$name}");
                $recovered[] = $name;
            }
        }

        $to = array_values(array_filter(array_map('trim', explode(',', (string) config('ops.alert_email')))));
        if ($to === [] || ($toSend === [] && $recovered === [])) {
            return;
        }

        try {
            Mail::to($to)->send(new OpsAlertMail($toSend, $toSend === [] ? $recovered : []));
            if ($toSend !== [] && $recovered !== []) {
                Mail::to($to)->send(new OpsAlertMail([], $recovered));
            }
        } catch (Throwable $e) {
            Log::critical('ops alert email could not be sent', ['error' => class_basename($e)]);
            $this->error('Alert email failed: '.class_basename($e));
        }
    }
}
