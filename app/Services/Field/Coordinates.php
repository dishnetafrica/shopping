<?php declare(strict_types=1);

namespace App\Services\Field;

/**
 * v4.63 — a place on the earth, or nothing.
 *
 * PURE. Two numbers in, a location or null out.
 *
 * Small enough to look unnecessary, and it is not. Latitude runs to 90 and longitude to 180, so a
 * single shared range check — the obvious way to write this — accepts a latitude of 150 and plots a
 * shop somewhere that does not exist. And a reading with one half missing is worse still: stored as
 * (4.85, null) it renders at (4.85, 0), which is open water in the Gulf of Guinea, roughly 1,700 km
 * from Juba. On a map that is not an error message, it is a dot, and somebody drives towards it.
 *
 * So: both halves or neither, and each half checked against its own range.
 */
final class Coordinates
{
    public const LAT_MAX = 90.0;
    public const LNG_MAX = 180.0;

    public static function lat($v): ?float
    {
        return self::within($v, self::LAT_MAX);
    }

    public static function lng($v): ?float
    {
        return self::within($v, self::LNG_MAX);
    }

    /**
     * Both, or nothing at all.
     *
     * @return array{0: ?float, 1: ?float}
     */
    public static function pair($lat, $lng): array
    {
        $la = self::lat($lat);
        $ln = self::lng($lng);

        return ($la === null || $ln === null) ? [null, null] : [$la, $ln];
    }

    /** Is this a location we could put on a map? */
    public static function has($lat, $lng): bool
    {
        return self::pair($lat, $lng)[0] !== null;
    }

    /**
     * Null Island, and the region around it.
     *
     * (0, 0) is what a broken GPS, an uninitialised variable and an empty form field all produce,
     * and it is a real point in the Atlantic. Nothing this shop sells is within 500 km of it, so a
     * reading that lands there is a fault rather than a place.
     */
    public static function isNullIsland($lat, $lng): bool
    {
        [$la, $ln] = self::pair($lat, $lng);

        return $la !== null && abs($la) < 0.01 && abs($ln) < 0.01;
    }

    private static function within($v, float $max): ?float
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }
        $f = (float) $v;

        return ($f >= -$max && $f <= $max) ? $f : null;
    }
}
