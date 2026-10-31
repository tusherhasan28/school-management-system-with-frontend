<?php

namespace App\Modules\Finance\Gateways;

/**
 * Strips secrets from provider payloads before they are stored: keys that look
 * like credentials, signatures, card data or tokens are dropped, and values are
 * truncated.
 */
final class PayloadSanitizer
{
    private const SECRET = '/(secret|passw|token|sign|hash|key|card|pan|cvv|cvc|pin|otp|auth)/i';

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function clean(array $payload, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }

        $out = [];

        foreach (array_slice($payload, 0, 60, true) as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET, $key)) {
                continue;
            }

            $out[$key] = match (true) {
                is_array($value) => self::clean($value, $depth + 1),
                is_scalar($value) || $value === null => is_string($value) ? mb_substr($value, 0, 255) : $value,
                default => null,
            };
        }

        return $out;
    }
}
