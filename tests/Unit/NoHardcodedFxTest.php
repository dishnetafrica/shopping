<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Roadmap E3.5 — the grep-guard. Fails if a hardcoded FX rate constant or a legacy rate-setting read
 * reappears anywhere in app/ EXCEPT the one allowed home, App\Services\Money\Fx. This is what stops
 * the audit's "scattered divergent rates" from creeping back (Constitution R8, R2).
 *
 * It targets FX-specific CODE patterns (not bare numbers), so unrelated constants and comments don't
 * trip it.
 */
class NoHardcodedFxTest extends TestCase
{
    public function test_no_hardcoded_fx_rate_outside_fx_service(): void
    {
        $root = dirname(__DIR__, 2) . '/app';
        $allowed = 'Services/Money/Fx.php'; // the ONE place default rate constants may live

        $patterns = [
            // legacy accounting-family reads (must all be routed through Fx now)
            "/setting\\(['\"]rate_[a-z]+_per_usd['\"]/",
            // hardcoded client-family numeric defaults: setting('usdUgx', 3750) or ['usdUgx'] ?? 3750
            "/['\"]usd(Ugx|Ssp)['\"]\\s*(,|\\?\\?)\\s*[0-9]{3,}/",
            // inline ternary rate default: ? 3655 : / ?3700: / ? 3750 :
            "/\\?\\s*3(655|700|750)\\s*:/",
        ];

        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, $allowed)) continue;

            // strip // and # line comments and /* */ blocks so comments mentioning a rate don't trip it
            $src = (string) file_get_contents($path);
            $src = preg_replace('!/\*.*?\*/!s', '', $src);
            $src = preg_replace('!//.*$!m', '', $src);

            foreach ($patterns as $re) {
                if (preg_match($re, $src)) {
                    $offenders[] = basename($path) . ' :: ' . $re;
                }
            }
        }

        $this->assertSame([], $offenders, "hardcoded FX constant/read found outside Fx.php:\n" . implode("\n", $offenders));
    }
}
