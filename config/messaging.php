<?php

return [
    /*
    | Installed SMS provider adapters (classes implementing
    | App\Modules\Messaging\Providers\SmsProvider). No Bangladeshi gateway
    | (SSL Wireless, BulkSMSBD, Alpha SMS, ...) ships yet: each needs the
    | provider's current official API documentation and a sandbox account
    | before an adapter is written. The "log" provider only records that a
    | message would have been sent and is for development and demos.
    */
    'sms_providers' => array_values(array_filter([
        env('MESSAGING_ALLOW_LOG_SMS', in_array(env('APP_ENV'), ['local', 'testing'], true)) ? App\Modules\Messaging\Providers\LogSmsProvider::class : null,
    ])),

    // Retries for a message that failed to send (seconds between attempts).
    'retry_backoff' => [60, 300, 900],

    // Default channels per automatic message when a school has not chosen.
    'defaults' => [
        'attendance.absent' => ['mail'],
        'results.published' => ['mail'],
    ],
];
