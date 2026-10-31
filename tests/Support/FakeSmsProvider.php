<?php

namespace Tests\Support;

use App\Modules\Messaging\Models\SmsProviderAccount;
use App\Modules\Messaging\Providers\SmsProvider;
use App\Modules\Messaging\Providers\SmsSendFailed;

/** Test-only SMS adapter that records messages or fails on demand. */
class FakeSmsProvider implements SmsProvider
{
    /** @var list<array{to: string, text: string}> */
    public array $sent = [];

    public ?SmsSendFailed $failWith = null;

    public function key(): string
    {
        return 'fakesms';
    }

    public function label(): string
    {
        return 'FakeSMS';
    }

    public function credentialFields(): array
    {
        return [['key' => 'api_key', 'label' => 'API key', 'secret' => true]];
    }

    public function perMinute(): int
    {
        return 30;
    }

    public function send(string $to, string $text, SmsProviderAccount $account): string
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        $this->sent[] = ['to' => $to, 'text' => $text];

        return 'FAKE-'.count($this->sent);
    }
}
