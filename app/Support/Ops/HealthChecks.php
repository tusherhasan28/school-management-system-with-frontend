<?php

namespace App\Support\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Operational health checks used by `ops:health` (scheduled, emails alerts)
 * and by the token-protected /ops/health endpoint for uptime monitors.
 *
 * Results never contain school data: only counts, ages and check names.
 */
class HealthChecks
{
    public const HEARTBEAT_KEY = 'ops:scheduler-heartbeat';

    /**
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function run(): array
    {
        $checks = [
            'database' => fn () => $this->database(),
            'cache' => fn () => $this->cache(),
            'queues' => fn () => $this->queues(),
            'failed_jobs' => fn () => $this->failedJobs(),
            'disk' => fn () => $this->disk(),
        ];

        if (config('ops.checks.horizon') && config('queue.default') === 'redis') {
            $checks['horizon'] = fn () => $this->horizon();
        }
        if (config('ops.checks.scheduler')) {
            $checks['scheduler'] = fn () => $this->scheduler();
        }
        if (config('ops.checks.backup')) {
            $checks['backup'] = fn () => $this->backup();
        }

        $results = [];
        foreach ($checks as $name => $check) {
            try {
                $results[$name] = $check();
            } catch (Throwable $e) {
                // Class name only: exception messages can contain DSNs or hosts.
                $results[$name] = ['ok' => false, 'detail' => 'error: '.class_basename($e)];
            }
        }

        return $results;
    }

    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp());
    }

    private function database(): array
    {
        DB::select('select 1');

        return ['ok' => true, 'detail' => 'reachable'];
    }

    private function cache(): array
    {
        $key = 'ops:health-probe';
        $value = bin2hex(random_bytes(4));
        Cache::put($key, $value, 60);

        return Cache::get($key) === $value
            ? ['ok' => true, 'detail' => 'read/write ok']
            : ['ok' => false, 'detail' => 'value not read back'];
    }

    private function queues(): array
    {
        if (config('queue.default') === 'sync') {
            return ['ok' => true, 'detail' => 'sync driver'];
        }

        $over = [];
        $sizes = [];
        foreach (config('ops.checks.queues', []) as $queue => $max) {
            $size = Queue::size($queue);
            $sizes[] = "{$queue}={$size}";
            if ($size > $max) {
                $over[] = "{$queue} {$size} > {$max}";
            }
        }

        return $over === []
            ? ['ok' => true, 'detail' => implode(', ', $sizes)]
            : ['ok' => false, 'detail' => 'backlog: '.implode('; ', $over)];
    }

    private function failedJobs(): array
    {
        $window = (int) config('ops.checks.failed_jobs_window_minutes');
        $count = DB::table(config('queue.failed.table', 'failed_jobs'))
            ->where('failed_at', '>=', now()->subMinutes($window))
            ->count();
        $max = (int) config('ops.checks.failed_jobs_max');

        return [
            'ok' => $count <= $max,
            'detail' => "{$count} failed in the last {$window} min",
        ];
    }

    private function horizon(): array
    {
        $masters = app(MasterSupervisorRepository::class)->all();

        if ($masters === []) {
            return ['ok' => false, 'detail' => 'not running'];
        }

        $paused = collect($masters)->every(fn ($m) => ($m->status ?? null) === 'paused');

        return $paused
            ? ['ok' => false, 'detail' => 'paused']
            : ['ok' => true, 'detail' => count($masters).' master supervisor(s)'];
    }

    private function scheduler(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);
        if (! $last) {
            return ['ok' => false, 'detail' => 'no heartbeat yet'];
        }

        $age = (int) floor((now()->getTimestamp() - (int) $last) / 60);
        $max = (int) config('ops.checks.scheduler_max_age_minutes');

        return ['ok' => $age <= $max, 'detail' => "last heartbeat {$age} min ago"];
    }

    private function backup(): array
    {
        $file = (string) config('ops.checks.backup_status_file');
        clearstatcache(true, $file);
        if (! is_file($file)) {
            return ['ok' => false, 'detail' => 'no successful backup recorded'];
        }

        $hours = CarbonImmutable::createFromTimestamp((int) filemtime($file))->diffInHours(now(), true);
        $max = (int) config('ops.checks.backup_max_age_hours');

        return ['ok' => $hours <= $max, 'detail' => sprintf('last backup %.1f h ago', $hours)];
    }

    private function disk(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if (! $free || ! $total) {
            return ['ok' => true, 'detail' => 'unknown'];
        }

        $percent = $free / $total * 100;
        $min = (int) config('ops.checks.disk_min_free_percent');

        return ['ok' => $percent >= $min, 'detail' => sprintf('%.0f%% free', $percent)];
    }
}
