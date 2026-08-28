<?php

declare(strict_types=1);

/**
 * Sandbox proof for the "first photo not showing" fix — pure PHP, runs without the framework.
 *
 * Product is an Eloquent model, so it can't be instantiated in the overlay sandbox (no vendor/).
 * This mirrors Product::firstImage() EXACTLY so the precedence (hero → gallery_1 → 2 → 3, empties
 * skipped) is proven here; the real method is bound to the model in tests/Unit/Models/ProductPrimaryImageTest.php.
 *
 * Run:  php tests/product_primary_image_harness.php
 */

// --- mirror of App\Models\Product::firstImage() ---
function firstImage(?string ...$candidates): ?string
{
    foreach ($candidates as $c) {
        $c = trim((string) $c);
        if ($c !== '') {
            return $c;
        }
    }

    return null;
}

// --- mirror of App\Models\Product::isUsableImageRef() ---
function isUsableImageRef(?string $v): bool
{
    $v = trim((string) $v);
    if ($v === '') {
        return false;
    }

    return preg_match('#^https?://#i', $v) === 1 || str_contains($v, '/');
}

$pass = 0;
$fail = 0;
function ok(string $n, bool $c): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m $n\n"; }
    else    { $fail++; echo "  \033[31m✗ $n\033[0m\n"; }
}

echo "\033[1mPrimary image = first non-empty of hero → gallery_1..3\033[0m\n";

// hero present → hero wins even with a full gallery
ok('hero set → hero is primary',              firstImage('hero.jpg', 'g1.jpg', 'g2.jpg', 'g3.jpg') === 'hero.jpg');

// THE bug: photos only in the gallery, hero empty → first gallery photo becomes primary
ok('hero empty → gallery_1 is primary',       firstImage('', 'g1.jpg', 'g2.jpg', 'g3.jpg') === 'g1.jpg');
ok('hero+g1 empty → gallery_2 is primary',    firstImage('', '', 'g2.jpg', 'g3.jpg') === 'g2.jpg');
ok('only gallery_3 → gallery_3 is primary',   firstImage('', '', '', 'g3.jpg') === 'g3.jpg');

// whitespace-only counts as empty (matches trim() in the model)
ok('whitespace hero skipped',                 firstImage('   ', 'g1.jpg') === 'g1.jpg');
ok('all empty/whitespace → null',             firstImage('', null, '   ') === null);
ok('no candidates → null',                    firstImage() === null);

// the exact reported scenario: 3 photos uploaded to the gallery, no hero
$img = firstImage('', 'photo1.jpg', 'photo2.jpg', 'photo3.jpg');
ok('Photo 1 is the primary (not skipped)',    $img === 'photo1.jpg');

echo "\n\033[1mUsable image reference (broken bare filename must not count as a real photo)\033[0m\n";
ok('https URL is usable',                     isUsableImageRef('https://cdn.example.com/x.jpg') === true);
ok('root-relative path is usable',            isUsableImageRef('/storage/products/19/imp_x.jpg') === true);
ok('storage path with slash is usable',       isUsableImageRef('products/19/imp_x.jpg') === true);
ok('bare filename (GSL case) is NOT usable',  isUsableImageRef('Diamond Solar Lawn Light.jpg') === false);
ok('bare filename photo.png is NOT usable',   isUsableImageRef('photo.png') === false);
ok('empty / whitespace is NOT usable',        isUsableImageRef('  ') === false && isUsableImageRef('') === false);
ok('null is NOT usable',                      isUsableImageRef(null) === false);

echo "\n" . str_repeat('─', 56) . "\n";
echo $fail === 0
    ? "\033[32mALL {$pass} CHECKS PASSED.\033[0m\n"
    : "\033[31m{$fail} FAILED\033[0m, {$pass} passed.\n";
exit($fail === 0 ? 0 : 1);
