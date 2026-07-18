<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * deploy:check — the permanent fix for the 2026-07-18 class of incident.
 *
 * Force-loads EVERY class under app/ (PSR-4), which makes PHP itself verify what
 * no syntax checker can: imports resolve, interfaces are implemented, signatures
 * are compatible. Then boots the capability registry and counts routes.
 *
 * Wire it into the BUILD so a broken deploy NEVER goes live:
 *   EasyPanel → app → Build → add after composer install:
 *       php artisan deploy:check
 *   A failure fails the build → the OLD container keeps serving → customers
 *   never see a 500. The build log shows the exact file and error.
 *
 * No database required (DB checks are soft), so it runs in any build stage.
 */
class DeployCheckCommand extends Command
{
    protected $signature   = 'deploy:check {--fast : Class-load only, skip registry/routes}';
    protected $description = 'Build gate: load every app class, boot the registry, count routes — fail loudly BEFORE going live';

    public function handle(): int
    {
        $fail = 0;

        // 1) force-load every class — bad imports / contract mismatches die HERE, visibly.
        $this->line('1/3  loading every class under app/ …');
        $files = iterator_to_array((new Finder)->files()->in(app_path())->name('*.php'));
        $n = 0;
        $foreign = 0;
        foreach ($files as $f) {
            $rel = str_replace([app_path().DIRECTORY_SEPARATOR, '.php'], '', $f->getRealPath());
            $fqcn = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $rel);

            // v2 (2026-07-18 night incident): read the DECLARED namespace before touching the
            // file. Stray experiment files (e.g. namespace CloudBSS\App) inside app/ made v1
            // fatal on a duplicate declaration BEFORE it could report anything else. Foreign
            // namespaces are skipped-and-flagged — they should not be in this tree at all.
            $src = (string) @file_get_contents($f->getRealPath());
            if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $src, $nm) && ! str_starts_with($nm[1], 'App')) {
                $this->warn("   ⚠️  FOREIGN NAMESPACE skipped: {$rel}.php declares {$nm[1]} — this file does not belong in app/");
                $foreign++;
                continue;
            }
            try {
                if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) $n++;
                // (a compile-level incompatibility in APP code is uncatchable by design — it
                //  aborts with the exact file+line printed, which fails the build: mission accomplished)
            } catch (\Throwable $e) {
                $this->error("❌ {$fqcn}: ".get_class($e).' — '.$e->getMessage());
                $fail++;
            }
        }
        if ($foreign > 0) $this->warn("   {$foreign} foreign file(s) skipped — move them out of app/ (they also pollute composer's classmap)");
        $this->info("   ✅ {$n} classes loaded clean".($fail ? " · {$fail} FAILED" : ''));

        if (! $this->option('fast')) {
            // 2) the registry must boot (today's exact failure surface)
            $this->line('2/3  booting the capability registry …');
            try {
                $count = count(app(\App\Capability\Registry\CapabilityRegistry::class)->all());
                $this->info("   ✅ registry OK — {$count} capabilities");
                if ($count < 10) { $this->error('   ❌ suspiciously few capabilities — a provider died silently'); $fail++; }
            } catch (\Throwable $e) {
                $this->error('   ❌ registry boot: '.$e->getMessage());
                $fail++;
            }

            // 3) routes must compile
            $this->line('3/3  compiling routes …');
            try {
                $routes = count(app('router')->getRoutes());
                $this->info("   ✅ {$routes} routes");
            } catch (\Throwable $e) {
                $this->error('   ❌ routes: '.$e->getMessage());
                $fail++;
            }

            // soft: pending migrations (DB may not exist at build — never fail on it)
            try {
                $pending = collect(\Illuminate\Support\Facades\DB::select('select 1'))->count() >= 0
                    ? shell_exec('php artisan migrate:status 2>/dev/null | grep -ci "pending" 2>/dev/null') : null;
                if ($pending !== null && (int) trim((string) $pending) > 0) {
                    $this->warn('   ⚠️  pending migrations — run: php artisan migrate');
                }
            } catch (\Throwable $e) {
                $this->line('   (db not reachable at build — migration check skipped)');
            }
        }

        $this->line('');
        if ($fail === 0) {
            $this->info('DEPLOY CHECK PASSED — safe to serve.');
            return self::SUCCESS;
        }
        $this->error("DEPLOY CHECK FAILED ({$fail}) — DO NOT SERVE THIS BUILD.");

        return self::FAILURE;
    }
}
