<?php

namespace App\Enums;

/**
 * Platform roles are a fixed, small set, so they live in code rather than in
 * Spatie (whose team-scoped pivots require a school id).
 */
enum PlatformRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case BillingSupport = 'billing_support';
    case SupportAgent = 'support_agent';
    case Auditor = 'auditor';

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Owner, self::Administrator => ['*'],
            self::BillingSupport => ['platform.view', 'schools.view', 'billing.view', 'billing.manage'],
            self::SupportAgent => ['platform.view', 'schools.view', 'support.view', 'support.manage'],
            self::Auditor => ['platform.view', 'schools.view', 'billing.view', 'support.view', 'audit.view'],
        };
    }

    public function allows(string $ability): bool
    {
        $abilities = $this->abilities();

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}
