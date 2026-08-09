<?php declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * v4.74 — the pixels half of ImagePolicy. GD in, GD out, and one iron rule:
 *
 *     ON ANY DOUBT, RETURN THE ORIGINAL.
 *
 * An upload must never fail because optimisation failed — storing the photo is the job,
 * making it fast is the bonus. Every exit path below either returns a strictly better file
 * or the exact bytes that came in, with a note saying which and why.
 *
 * Also fixes the sideways-phone-photo problem while it is here: JPEGs carry an EXIF
 * orientation flag that browsers honour but GD ignores; without the rotate, "convert to
 * webp" would silently strip the flag and every portrait shot would lie on its side.
 */
final class ImageOptimizer
{
    /** @return array{bin:string, ext:string, note:string} */
    public static function optimize(string $bin, string $ext, string $kind): array
    {
        $ext = strtolower(trim($ext)) ?: 'jpg';
        try {
            $canWebp = function_exists('imagecreatefromstring') && function_exists('imagewebp');
            if (! $canWebp || $bin === '') {
                return ['bin' => $bin, 'ext' => $ext, 'note' => 'server cannot encode webp'];
            }

            $im = @imagecreatefromstring($bin);
            if ($im === false) {
                return ['bin' => $bin, 'ext' => $ext, 'note' => 'gd could not decode'];
            }

            // Phone photos arrive sideways: honour EXIF orientation before measuring anything.
            if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
                $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bin));
                $o = (int) (($exif['Orientation'] ?? 1));
                $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($deg !== 0) {
                    $rot = @imagerotate($im, $deg, 0);
                    if ($rot !== false) {
                        $im = $rot;
                    }
                }
            }

            $w = imagesx($im);
            $h = imagesy($im);
            $plan = ImagePolicy::plan($kind, $ext, $w, $h, strlen($bin), true);
            if ($plan['action'] !== 'convert') {
                return ['bin' => $bin, 'ext' => $ext, 'note' => $plan['reason']];
            }

            // Resample onto a fresh truecolor canvas, transparency preserved (logos!).
            $dst = imagecreatetruecolor($plan['w'], $plan['h']);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            if (! imageistruecolor($im)) {
                imagepalettetotruecolor($im);
            }
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $plan['w'], $plan['h'], $w, $h);

            ob_start();
            $ok = imagewebp($dst, null, $plan['quality']);
            $webp = (string) ob_get_clean();

            if (! $ok || $webp === '' || strlen($webp) >= strlen($bin)) {
                // A 2 KB icon can round-trip BIGGER as webp. Never ship a worse file.
                return ['bin' => $bin, 'ext' => $ext, 'note' => 'webp would not be smaller; original kept'];
            }

            return ['bin' => $webp, 'ext' => 'webp', 'note' => $plan['reason']
                . sprintf(' — %d → %d KB', intdiv(strlen($bin), 1024), intdiv(strlen($webp), 1024))];
        } catch (\Throwable $e) {
            return ['bin' => $bin, 'ext' => $ext, 'note' => 'optimizer failed, original kept: ' . $e->getMessage()];
        }
    }
}
