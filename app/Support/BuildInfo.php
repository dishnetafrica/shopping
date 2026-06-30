<?php

namespace App\Support;

/**
 * Build identity + deploy/runtime state. The opcache section is the one that matters:
 * with opcache.validate_timestamps=Off the running web process keeps serving the build
 * it booted with, even after files change on disk — exactly the trap that makes a deploy
 * "not take". This surfaces it instead of leaving you to guess.
 */
class BuildInfo
{
    /** A short build id. Prefers an explicit env/file stamp; falls back to code mtime. */
    public static function id(): string
    {
        $env = (string) (env('APP_BUILD', '') ?: env('APP_VERSION', ''));
        if ($env !== '') return substr($env, 0, 24);

        $file = base_path('public/build-id.txt');
        if (is_file($file)) {
            $v = trim((string) @file_get_contents($file));
            if ($v !== '') return substr($v, 0, 24);
        }
        // Cheap fallback: mtime of the routes file (changes on every code deploy).
        $rt = base_path('routes/web.php');
        return is_file($rt) ? ('m' . dechex((int) @filemtime($rt))) : 'unknown';
    }

    /** Light, secret-free snapshot safe for a public-ish health probe. */
    public static function light(): array
    {
        return [
            'ok'      => true,
            'build'   => self::id(),
            'time'    => now()->toIso8601String(),
            'php'     => PHP_VERSION,
            'laravel' => app()->version(),
            'env'     => (string) config('app.env'),
        ];
    }

    /** Opcache state — the deploy-staleness detector. */
    public static function opcache(): array
    {
        if (! function_exists('opcache_get_configuration')) {
            return ['available' => false];
        }
        $out = ['available' => true];
        try {
            $cfg = @opcache_get_configuration();
            $d   = $cfg['directives'] ?? [];
            $out['enabled']             = (bool) ($d['opcache.enable'] ?? false);
            $out['enable_cli']          = (bool) ($d['opcache.enable_cli'] ?? false);
            $out['validate_timestamps'] = (bool) ($d['opcache.validate_timestamps'] ?? true);
            $out['revalidate_freq']     = (int) ($d['opcache.revalidate_freq'] ?? 0);
        } catch (\Throwable $e) {}

        // Is a key code file stale in opcache vs on disk? (file changed but process pinned)
        $stale = [];
        try {
            $status = @opcache_get_status(true);
            $scripts = $status['scripts'] ?? [];
            foreach (self::watchedFiles() as $label => $path) {
                if (! is_file($path)) continue;
                $disk = (int) @filemtime($path);
                $info = $scripts[$path] ?? ($scripts[realpath($path)] ?? null);
                $cached = $info ? (int) ($info['timestamp'] ?? 0) : 0;
                $stale[$label] = [
                    'cached_in_opcache' => (bool) $info,
                    'disk_mtime'        => $disk ? date('c', $disk) : null,
                    'opcache_mtime'     => $cached ? date('c', $cached) : null,
                    'is_stale'          => ($info && $cached > 0 && $disk > $cached),
                ];
            }
            $out['num_cached_scripts'] = (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0);
        } catch (\Throwable $e) {}
        $out['files'] = $stale;
        $out['any_stale'] = (bool) array_filter($stale, fn ($f) => ! empty($f['is_stale']));
        return $out;
    }

    /** Files worth watching for deploy staleness. */
    public static function watchedFiles(): array
    {
        return [
            'routes/web.php' => base_path('routes/web.php'),
            'bootstrap/app.php' => base_path('bootstrap/app.php'),
        ];
    }
}
