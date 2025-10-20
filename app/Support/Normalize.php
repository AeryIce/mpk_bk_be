<?php

namespace App\Support;

class Normalize
{
    public static function text(?string $v): string
    {
        if ($v === null) return '';
        $v = mb_strtolower(trim($v), 'UTF-8');
        // rapihin spasi & karakter umum
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = preg_replace('/[^\p{L}\p{Nd}\s\-\.,]/u', '', $v); // buang simbol liar
        return trim($v ?? '');
    }

    public static function phone(?string $v): string
    {
        if ($v === null) return '';
        // ambil digit saja
        $digits = preg_replace('/\D+/', '', $v);
        // normalisasi indonesia: 0xxxx → +62xxxx
        if (str_starts_with($digits, '0')) {
            return '+62' . substr($digits, 1);
        }
        if (str_starts_with($digits, '62')) {
            return '+' . $digits;
        }
        if (str_starts_with($v, '+')) {
            return $v; // sudah E.164-ish
        }
        return $digits; // fallback
    }

    public static function substringOverlapScore(string $a, string $b): int
    {
        $a = self::text($a);
        $b = self::text($b);
        if ($a === '' || $b === '') return 0;

        // ambil potongan 12 char dari alamat untuk overlap sederhana
        $sub = mb_substr($a, 0, 12, 'UTF-8');
        if ($sub !== '' && mb_strpos($b, $sub, 0, 'UTF-8') !== false) {
            return 10;
        }
        return 0;
    }
}
