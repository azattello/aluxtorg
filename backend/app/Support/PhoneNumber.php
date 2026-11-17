<?php

namespace App\Support;

final class PhoneNumber
{
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';

        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }

        return '+'.$digits;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^\+[1-9]\d{9,14}$/', $value) === 1;
    }
}
