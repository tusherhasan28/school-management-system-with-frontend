<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use App\Models\PlatformActivityLog;
use App\Models\PlatformUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Writes tenant and platform audit entries with sensitive values redacted.
 */
class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function tenant(string $action, ?Model $subject = null, array $old = [], array $new = []): ActivityLog
    {
        $this->tenant->require();

        $user = Auth::guard('web')->user();

        return ActivityLog::create([
            'user_id' => $user?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 500),
            'old_values' => $old === [] ? null : self::redact($old),
            'new_values' => $new === [] ? null : self::redact($new),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function platform(string $action, ?Model $subject = null, ?int $schoolId = null, array $metadata = []): PlatformActivityLog
    {
        /** @var PlatformUser|null $actor */
        $actor = Auth::guard('platform')->user();

        return PlatformActivityLog::create([
            'platform_user_id' => $actor?->getKey(),
            'school_id' => $schoolId,
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 500),
            'metadata' => $metadata === [] ? null : self::redact($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function redact(array $values): array
    {
        $redacted = array_map('strtolower', config('tenancy.redacted_keys'));

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $redacted, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
