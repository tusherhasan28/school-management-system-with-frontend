<?php

namespace App\Console\Commands;

use App\Support\Ops\DatabaseManifest;
use Illuminate\Console\Command;

/** Used by scripts/backup.sh: writes per-table row counts and checksums. */
class OpsDatabaseManifest extends Command
{
    protected $signature = 'ops:db-manifest {--database= : Database on the same server (default: the app database)} {--output= : File to write (default: stdout)}';

    protected $description = 'Write row counts and checksums of every table as JSON';

    public function handle(): int
    {
        $manifest = DatabaseManifest::build(DatabaseManifest::connection($this->option('database')));
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($path = $this->option('output')) {
            file_put_contents($path, $json."\n");
            $this->info(count($manifest['tables']).' tables written to '.$path);
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
