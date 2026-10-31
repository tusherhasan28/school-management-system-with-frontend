<?php

namespace App\Console\Commands;

use App\Support\Ops\DatabaseManifest;
use Illuminate\Console\Command;

/**
 * Compares a restored database with the manifest taken at backup time.
 * Exit code 0 only when every table has the same row count and checksum.
 */
class OpsVerifyRestore extends Command
{
    protected $signature = 'ops:verify-restore
        {manifest : manifest.json from the backup}
        {--database= : The restored database (same server and credentials as the app)}
        {--ignore=* : Tables allowed to differ (e.g. sessions, cache)}';

    protected $description = 'Prove a restored database matches its backup manifest';

    public function handle(): int
    {
        $path = (string) $this->argument('manifest');
        if (! is_file($path)) {
            $this->error("Manifest not found: {$path}");

            return self::FAILURE;
        }

        $expected = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $actual = DatabaseManifest::build(DatabaseManifest::connection($this->option('database')));

        $problems = DatabaseManifest::compare($expected, $actual, $this->option('ignore'));
        $rows = array_sum(array_column($actual['tables'], 'rows'));

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }
            $this->error(count($problems).' table(s) differ from the backup taken '.$expected['generated_at']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'OK: %d tables, %s rows match the backup of "%s" taken %s.',
            count($actual['tables']), number_format($rows), $expected['database'], $expected['generated_at'],
        ));

        return self::SUCCESS;
    }
}
