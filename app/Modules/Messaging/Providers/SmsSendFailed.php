<?php

namespace App\Modules\Messaging\Providers;

use RuntimeException;

/** A provider refused or could not take a message. `retryable` says whether trying again may help. */
class SmsSendFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
