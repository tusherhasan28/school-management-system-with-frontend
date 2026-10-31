<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Models\SmsProviderAccount;

/**
 * An SMS gateway adapter. Implementations must be written against the
 * provider's official documentation; none are invented here.
 */
interface SmsProvider
{
    public function key(): string;

    public function label(): string;

    /**
     * Credential fields the school fills in (stored encrypted).
     *
     * @return list<array{key: string, label: string, secret: bool}>
     */
    public function credentialFields(): array;

    /** Messages per minute this provider accepts from one school. */
    public function perMinute(): int;

    /**
     * Send one message to one Bangladeshi number (8801XXXXXXXXX).
     *
     * @return string the provider's message reference
     *
     * @throws SmsSendFailed
     */
    public function send(string $to, string $text, SmsProviderAccount $account): string;
}
