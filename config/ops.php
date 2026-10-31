<?php

$production = env('APP_ENV') === 'production';

return [
    /*
    | Where health alerts go (comma-separated). Empty = alerts are only logged
    | at "critical" level.
    */
    'alert_email' => env('OPS_ALERT_EMAIL'),

    // One alert per failing check per this many minutes; a recovery notice is
    // sent once the check passes again.
    'alert_cooldown_minutes' => (int) env('OPS_ALERT_COOLDOWN_MINUTES', 60),

    /*
    | Token for GET https://admin.APP_DOMAIN/ops/health, for an external uptime
    | monitor. Empty = endpoint disabled (404).
    */
    'health_token' => env('OPS_HEALTH_TOKEN'),

    'checks' => [
        // Maximum waiting jobs per queue before alerting.
        'queues' => [
            'default' => (int) env('OPS_QUEUE_MAX_DEFAULT', 500),
            'messages' => (int) env('OPS_QUEUE_MAX_MESSAGES', 5000),
        ],

        // Any job failing within this window raises an alert.
        'failed_jobs_window_minutes' => (int) env('OPS_FAILED_JOBS_WINDOW_MINUTES', 15),
        'failed_jobs_max' => (int) env('OPS_FAILED_JOBS_MAX', 0),

        // Database queue (shared hosting, worker started by cron): alert when a
        // job has been waiting longer than this, i.e. the worker cron stopped.
        'queue_worker_max_wait_minutes' => (int) env('OPS_QUEUE_MAX_WAIT_MINUTES', 10),

        // Horizon must be running when the queue driver is redis.
        'horizon' => (bool) env('OPS_CHECK_HORIZON', $production),

        // The scheduler writes a heartbeat every minute.
        'scheduler' => (bool) env('OPS_CHECK_SCHEDULER', $production),
        'scheduler_max_age_minutes' => 10,

        // scripts/backup.sh writes this file after a fully successful backup.
        'backup' => (bool) env('OPS_CHECK_BACKUP', $production),
        'backup_status_file' => env('OPS_BACKUP_STATUS_FILE', storage_path('app/ops/last-backup')),
        'backup_max_age_hours' => (int) env('OPS_BACKUP_MAX_AGE_HOURS', 26),

        // Minimum free disk space on the storage volume.
        'disk_min_free_percent' => (int) env('OPS_DISK_MIN_FREE_PERCENT', 10),
    ],
];
