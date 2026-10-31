<?php

namespace App\Modules\Messaging\Services;

/** Bangladeshi mobile numbers in the 8801XXXXXXXXX form SMS gateways expect. */
final class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (preg_match('/^(?:00)?880(1[3-9]\d{8})$/', $digits, $m) || preg_match('/^0?(1[3-9]\d{8})$/', $digits, $m)) {
            return '880'.$m[1];
        }

        return null;
    }
}
