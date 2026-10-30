<?php

namespace App\Support\Tenancy;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Private file storage partitioned by school: schools/{id}/{path}.
 * Paths are validated so a caller cannot escape the school's directory.
 */
class TenantStorage
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.tenant_disk', 'local'));
    }

    public function root(): string
    {
        return 'schools/'.$this->tenant->require()->getKey();
    }

    public function path(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || preg_match('#(^|/)\.\.?(/|$)#', $relative)) {
            throw new InvalidArgumentException('Invalid tenant file path.');
        }

        return $this->root().'/'.$relative;
    }

    /**
     * True when a stored path belongs to the current school. Use before any
     * download built from a database value.
     */
    public function owns(string $storedPath): bool
    {
        return str_starts_with($storedPath, $this->root().'/')
            && ! preg_match('#(^|/)\.\.?(/|$)#', $storedPath);
    }
}
