<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Adyen\Model;

/**
 * An Adyen standard webhook item's HMAC signature
 * (additionalData.hmacSignature): base64 of HMAC-SHA256, keyed with the
 * webhook's hex HMAC key, over
 * pspReference:originalReference:merchantAccountCode:merchantReference:value:currency:eventCode:success.
 */
class Notification
{
    public static function isValid(array $item, string $hexKey): bool
    {
        $signature = (string) ($item['additionalData']['hmacSignature'] ?? '');
        if ($signature === '' || $hexKey === '' || !ctype_xdigit($hexKey)) {
            return false;
        }

        return hash_equals(self::sign($item, $hexKey), $signature);
    }

    public static function sign(array $item, string $hexKey): string
    {
        $payload = implode(':', [
            $item['pspReference'] ?? '',
            $item['originalReference'] ?? '',
            $item['merchantAccountCode'] ?? '',
            $item['merchantReference'] ?? '',
            $item['amount']['value'] ?? '',
            $item['amount']['currency'] ?? '',
            $item['eventCode'] ?? '',
            $item['success'] ?? '',
        ]);

        return base64_encode(hash_hmac('sha256', $payload, (string) hex2bin($hexKey), true));
    }
}
