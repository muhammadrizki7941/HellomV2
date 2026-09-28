<?php

namespace App\Support;

/**
 * Indonesian phone numbers in one canonical form (628…), so 08123…, 628123… and
 * +62 812-3… are the same person. Used before every member save and lookup.
 */
final class PhoneNumber
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '62')) {
            return $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '62' . substr($digits, 1);
        }
        if (str_starts_with($digits, '8')) {
            return '62' . $digits;
        }

        return $digits;
    }
}
