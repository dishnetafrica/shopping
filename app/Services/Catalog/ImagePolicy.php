<?php declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * v4.74 — every uploaded photo becomes a fast one, without the owner knowing what WebP is.
 *
 * PURE. Numbers in, a plan out. No GD, no files — the decisions live here so they can be
 * tested to death; ImageOptimizer does the pixels.
 *
 * The owner's words that wrote this class: "very difficult for me … is there way system
 * automatically convert uploaded photo in webp or whatever format system needs so system
 * become fast". The storefront label used to DEMAND "WebP, under 250 KB" from a person who
 * should never have to learn what that means. Now the demand points at the machine.
 *
 * Rules, in order:
 *   · a GIF is never re-encoded (it may animate; conversion would freeze it)
 *   · a server that cannot encode WebP keeps the original (degrade honestly, never block)
 *   · an image that is already a small-enough WebP at target size is left alone
 *   · everything else: shrink to the slot's max edge (NEVER upscale) and encode WebP
 */
final class ImagePolicy
{
    /** Longest edge per slot, px. The hero design slots are 1600×600 / 750×750 (ADR-017). */
    public const MAX_EDGE = [
        'product'     => 1600,
        'hero'        => 1600,
        'hero_mobile' => 900,
        'logo'        => 1200,
        'branding'    => 1600,
    ];

    /** WebP quality per slot. Logos keep more (flat colour + text shows artifacts first). */
    public const QUALITY = [
        'product'     => 80,
        'hero'        => 82,
        'hero_mobile' => 82,
        'logo'        => 90,
        'branding'    => 82,
    ];

    /** A WebP already at target size and under this many bytes is not worth touching. */
    public const KEEP_UNDER_BYTES = 120000;

    /** Which slot a Settings image key belongs to (branding-upload passes its key through). */
    public static function kindForKey(string $key): string
    {
        if ($key === 'logo') {
            return 'logo';
        }
        if ($key === 'home_hero_image_mobile') {
            return 'hero_mobile';
        }
        if ($key === 'home_hero_image') {
            return 'hero';
        }

        return 'branding';
    }

    /**
     * The plan for one upload. $ext is the INCOMING extension (lowercase ok either way).
     *
     * @return array{action:'keep'|'convert', w:int, h:int, quality:int, reason:string}
     */
    public static function plan(string $kind, string $ext, int $w, int $h, int $bytes, bool $canWebp): array
    {
        $ext  = strtolower(trim($ext));
        $max  = self::MAX_EDGE[$kind] ?? self::MAX_EDGE['branding'];
        $q    = self::QUALITY[$kind] ?? self::QUALITY['branding'];
        $keep = fn (string $why): array => ['action' => 'keep', 'w' => $w, 'h' => $h, 'quality' => $q, 'reason' => $why];

        if ($w < 1 || $h < 1) {
            return $keep('not a decodable image');
        }
        if ($ext === 'gif') {
            return $keep('gif may animate; re-encoding would freeze it');
        }
        if (! $canWebp) {
            return $keep('server cannot encode webp');
        }

        [$tw, $th] = self::fit($w, $h, $max);
        if ($ext === 'webp' && $tw === $w && $th === $h && $bytes <= self::KEEP_UNDER_BYTES) {
            return $keep('already a small webp at target size');
        }

        return [
            'action' => 'convert', 'w' => $tw, 'h' => $th, 'quality' => $q,
            'reason' => sprintf('%dx%d %s (%d KB) → %dx%d webp q%d', $w, $h, $ext ?: '?', intdiv($bytes, 1024), $tw, $th, $q),
        ];
    }

    /** Fit inside a square of $maxEdge, preserving ratio. NEVER upscales. */
    public static function fit(int $w, int $h, int $maxEdge): array
    {
        $edge = max($w, $h);
        if ($edge <= $maxEdge) {
            return [$w, $h];
        }
        $r = $maxEdge / $edge;

        return [max(1, (int) round($w * $r)), max(1, (int) round($h * $r))];
    }
}
