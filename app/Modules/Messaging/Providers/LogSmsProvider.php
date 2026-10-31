<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Models\SmsProviderAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development/demo provider: nothing leaves the server. Records a masked
 * number and the length only (never the text, which may name a student).
 * Not installed in production unless MESSAGING_ALLOW_LOG_SMS is set.
 */
class LogSmsProvider implements SmsProvider
{
    public function key(): string
    {
        return 'log';
    }

    public function label(): string
    {
        return 'Test mode (no SMS is sent)';
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function perMinute(): int
    {
        return 600;
    }

    public function send(string $to, string $text, SmsProviderAccount $account): string
    {
        $reference = 'LOG-'.Str::upper(Str::random(10));
        Log::info('sms.log_provider', ['to' => substr($to, 0, 5).'•••'.substr($to, -3), 'chars' => mb_strlen($text), 'reference' => $reference]);

        return $reference;
    }
}
