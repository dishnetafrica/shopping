<?php

namespace App\Services\Customs;

use App\Models\Tenant;

/**
 * Small shared formatting helpers for the customs document renderers (Commercial Invoice,
 * Packing List) so the two stay visually consistent. Pure — no state, no I/O.
 */
final class CustomsDocStyle
{
    private const INK = '#0f172a';

    /** [accent, accentDark, ink] — accent follows the tenant brand colour (theme_accent). */
    public static function palette(Tenant $t): array
    {
        $accent = self::hex((string) $t->setting('theme_accent', ''), '#0f766e');

        return [$accent, self::darken($accent, 0.78), self::INK];
    }

    /** Currencies carried without minor units here (East-Africa shilling family). */
    public static function decimals(string $cur): int
    {
        return in_array(strtoupper($cur), ['UGX', 'SSP', 'RWF', 'TZS'], true) ? 0 : 2;
    }

    /** Grams → "8.67 kg" (or "—" when unknown). */
    public static function kg(int $grams): string
    {
        return $grams > 0 ? number_format($grams / 1000, 2) . ' kg' : '—';
    }

    private static function hex(string $v, string $fallback): string
    {
        $v = trim($v);

        return preg_match('/^#?[0-9a-fA-F]{6}$/', $v) ? (str_starts_with($v, '#') ? $v : '#' . $v) : $fallback;
    }

    private static function darken(string $hex, float $factor): string
    {
        $hex = ltrim($hex, '#');
        $r = (int) round(hexdec(substr($hex, 0, 2)) * $factor);
        $g = (int) round(hexdec(substr($hex, 2, 2)) * $factor);
        $b = (int) round(hexdec(substr($hex, 4, 2)) * $factor);

        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}
