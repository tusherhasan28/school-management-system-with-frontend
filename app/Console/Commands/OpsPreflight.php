<?php

namespace App\Console\Commands;

use App\Models\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Installation check for a new server (VPS or shared hosting): PHP, extensions,
 * folders, .env settings, database version, cache locks, queue, mail and
 * built assets. Run after every install or move; exits non-zero on FAIL.
 */
class OpsPreflight extends Command
{
    protected $signature = 'ops:preflight';

    protected $description = 'Check that this server and .env are ready for production';

    /** @var list<array{string, string, string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->rows = [];
        $production = app()->environment('production');

        // PHP
        $this->add(version_compare(PHP_VERSION, '8.3.0', '>='), 'PHP version', PHP_VERSION.' (8.3+ required)');
        $required = ['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'ctype', 'fileinfo', 'xml', 'dom', 'curl', 'zip', 'gd', 'intl'];
        $missing = array_values(array_filter($required, fn ($e) => ! extension_loaded($e)));
        $this->add($missing === [], 'PHP extensions', $missing === [] ? 'all present' : 'missing: '.implode(', ', $missing));
        $this->add(extension_loaded('exif'), 'PHP exif (photo orientation)', extension_loaded('exif') ? 'yes' : 'no', warn: true);
        $memory = $this->bytes((string) ini_get('memory_limit'));
        $this->add($memory < 0 || $memory >= 256 * 1024 * 1024, 'memory_limit', (string) ini_get('memory_limit').' (256M+ recommended)', warn: true);
        $upload = min($this->bytes((string) ini_get('upload_max_filesize')), $this->bytes((string) ini_get('post_max_size')));
        $this->add($upload >= 10 * 1024 * 1024, 'Upload limit', ini_get('upload_max_filesize').' / post '.ini_get('post_max_size').' (10M+ needed)', warn: true);

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $procOpen = function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
        $this->add($procOpen, 'proc_open (scheduler runs commands)', $procOpen ? 'available' : 'disabled — use the one-line-per-task cron list in docs/install-cpanel.md', warn: true);

        // Folders
        foreach (['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            $ok = is_dir($path) && is_writable($path);
            $this->add($ok, "Writable: {$dir}", $ok ? 'yes' : 'not writable');
        }
        $this->add(is_file(public_path('build/manifest.json')), 'Built front-end (public/build)', is_file(public_path('build/manifest.json')) ? 'present' : 'missing — upload the release package or run npm run build');

        // .env
        $this->add(filled(config('app.key')), 'APP_KEY', filled(config('app.key')) ? 'set' : 'missing — php artisan key:generate');
        $this->add($production, 'APP_ENV', (string) config('app.env'), warn: ! $production);
        $this->add(! config('app.debug'), 'APP_DEBUG', config('app.debug') ? 'true — must be false on a live server' : 'false');
        $url = (string) config('app.url');
        $this->add(Str::startsWith($url, 'https://'), 'APP_URL uses https', $url);
        $domain = (string) config('tenancy.domain');
        $this->add($domain !== '' && ! Str::endsWith($domain, ['.test', '.local', 'localhost']), 'APP_DOMAIN', $domain);
        $this->add((bool) config('session.secure'), 'SESSION_SECURE_COOKIE', config('session.secure') ? 'true' : 'false — set true with HTTPS');
        $this->add(blank(config('session.domain')), 'SESSION_DOMAIN', blank(config('session.domain')) ? 'null (host-only cookies)' : 'must be null');
        $logSms = in_array(\App\Modules\Messaging\Providers\LogSmsProvider::class, config('messaging.sms_providers', []), true);
        $this->add(! ($logSms && $production), 'Demo "log" SMS provider', $logSms ? 'enabled'.($production ? ' — remove MESSAGING_ALLOW_LOG_SMS' : ' (local only)') : 'off');
        $proxies = (string) config('security.trusted_proxies');
        $this->add($proxies !== '', 'TRUSTED_PROXIES', $proxies !== '' ? $proxies : 'empty — set "cloudflare" when the site is behind Cloudflare', warn: true);

        // Database
        try {
            $version = (string) DB::selectOne('select version() as v')->v;
            $maria = stripos($version, 'mariadb') !== false;
            preg_match('/^(\d+\.\d+)/', $version, $m);
            $ok = $maria ? version_compare($m[1] ?? '0', '10.6', '>=') : version_compare($m[1] ?? '0', '8.0', '>=');
            $this->add($ok, 'Database server', $version.($maria ? ' (MariaDB 10.6+)' : ' (MySQL 8.0+)'));
            // Tables are created as utf8mb4 explicitly; a different database
            // default only matters for tables created outside migrations.
            $charset = (string) DB::selectOne('select @@character_set_database as c')->c;
            $nonUtf8 = (int) DB::selectOne(
                "select count(*) as c from information_schema.tables where table_schema = database()
                 and table_type = 'BASE TABLE' and table_collation not like 'utf8mb4%'"
            )->c;
            $this->add($nonUtf8 === 0, 'Tables use utf8mb4 (Bangla)', $nonUtf8 === 0 ? 'yes' : "{$nonUtf8} table(s) are not utf8mb4");
            $this->add($charset === 'utf8mb4', 'Database default character set', $charset.($charset === 'utf8mb4' ? '' : ' — fine for this app; utf8mb4 recommended'), warn: true);

            $pending = $this->pendingMigrations();
            $this->add($pending === 0, 'Migrations', $pending === 0 ? 'up to date' : "{$pending} pending — php artisan migrate --force");
        } catch (Throwable $e) {
            $this->add(false, 'Database connection', 'failed: '.class_basename($e).' — check DB_* in .env');
        }

        // Cache, locks, queue, mail
        try {
            Cache::put('preflight', 'ok', 30);
            $lock = Cache::lock('preflight-lock', 5);
            $locked = $lock->get();
            $lock->release();
            $this->add(Cache::get('preflight') === 'ok' && $locked, 'Cache and locks ('.config('cache.default').')', 'working');
        } catch (Throwable $e) {
            $this->add(false, 'Cache ('.config('cache.default').')', 'failed: '.class_basename($e));
        }

        $queue = (string) config('queue.default');
        $this->add($queue !== 'sync', 'Queue driver', $queue.($queue === 'sync' ? ' — imports/SMS would run inside page requests' : ''), warn: true);
        if ($queue === 'redis' || config('cache.default') === 'redis' || config('session.driver') === 'redis') {
            try {
                \Illuminate\Support\Facades\Redis::connection()->ping();
                $this->add(true, 'Redis', 'reachable');
            } catch (Throwable $e) {
                $this->add(false, 'Redis', 'unreachable — on shared hosting use the database drivers');
            }
        }

        $mailer = (string) config('mail.default');
        $this->add(! in_array($mailer, ['log', 'array'], true), 'Mail', $mailer.(in_array($mailer, ['log', 'array'], true) ? ' — emails are not sent' : ''), warn: true);
        $this->add(filled(config('ops.alert_email')), 'OPS_ALERT_EMAIL', filled(config('ops.alert_email')) ? 'set' : 'empty — nobody gets alerts', warn: true);

        try {
            $admins = PlatformUser::query()->count();
            $this->add($admins > 0, 'Platform admin account', $admins > 0 ? "{$admins}" : 'none — php artisan platform:create-admin', warn: true);
        } catch (Throwable) {
            // Reported by the database checks above.
        }

        $this->table(['', 'Check', 'Detail'], $this->rows);
        $fails = count(array_filter($this->rows, fn ($r) => $r[0] === 'FAIL'));
        $warns = count(array_filter($this->rows, fn ($r) => $r[0] === 'WARN'));
        $this->line("{$fails} problem(s), {$warns} warning(s).");

        return $fails === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function add(bool $ok, string $label, string $detail, bool $warn = false): void
    {
        $this->rows[] = [$ok ? 'ok' : ($warn ? 'WARN' : 'FAIL'), $label, $detail];
    }

    private function pendingMigrations(): int
    {
        Artisan::call('migrate:status', ['--pending' => true]);

        return substr_count(Artisan::output(), 'Pending');
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
