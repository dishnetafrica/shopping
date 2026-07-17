<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Module Boundary Audit as executable guards (docs/architecture/MODULE-MAP.md).
 * Pure static file scans — no DB, no network, runs with the normal suite.
 *
 * Designed to pass BOTH before and after scripts/boundary-cleanup.sh:
 * legacy duplicates are pinned (not banned) until archived; once the directories
 * are gone the pins are vacuously satisfied.
 */
class ArchitectureBoundariesTest extends TestCase
{
    private function base(): string
    {
        return base_path();
    }

    /** @return array<string> php file paths under $dir (relative to base) */
    private function phpFiles(string $dir): array
    {
        $abs = $this->base().'/'.$dir;
        if (! is_dir($abs)) return [];
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[] = substr($f->getPathname(), strlen($this->base()) + 1);
            }
        }
        sort($out);
        return $out;
    }

    // ------------------------------------------------------- Rule 1: kernel purity

    public function test_kernel_never_depends_on_the_application(): void
    {
        $bad = [];
        foreach ($this->phpFiles('cloudbss-kernel') as $rel) {
            $code = file_get_contents($this->base().'/'.$rel);
            // (?<!CloudBSS) — the kernel's own CloudBSS\App namespace must not false-positive.
            if (preg_match('/^use App\\\\/m', $code) || preg_match('/(?<!CloudBSS)\\\\App\\\\[A-Z]/', $code)) {
                $bad[] = $rel;
            }
        }
        $this->assertSame([], $bad,
            "Kernel purity broken — cloudbss-kernel must never reference App\\*:\n".implode("\n", $bad));
    }

    // ------------------------------------------------- Rule 2: exactly one home

    /** Root twins: pinned legacy list. Anything NEW here fails. Empty/absent dirs pass. */
    public function test_no_new_files_in_legacy_root_homes(): void
    {
        $pinned = [
            'Capability/Object/DemandPlanCapability.php',
            'Capability/Process/ProcurementDashboardCapability.php',
            'Console/Commands/ProcessScheduled.php',
            'Http/Controllers/Panel/PanelApiController.php',
            'Http/Controllers/Panel/TrackController.php',
            'Jobs/SendCampaign.php',
            'Services/Bot/BotBrain.php',
        ];
        $found = array_merge(
            $this->phpFiles('Capability'), $this->phpFiles('Console'),
            $this->phpFiles('Http'), $this->phpFiles('Jobs'), $this->phpFiles('Services'),
        );
        $new = array_values(array_diff($found, $pinned));
        $this->assertSame([], $new,
            "New PHP files in legacy root directories — every class lives in app/ or cloudbss-kernel/ "
            ."(see MODULE-BOUNDARY-AUDIT.md F2):\n".implode("\n", $new));
    }

    /** While the root kernel copy still exists it must be BYTE-IDENTICAL to the real one. */
    public function test_root_kernel_copies_have_not_drifted(): void
    {
        foreach ([['src', 'cloudbss-kernel/src'], ['modules', 'cloudbss-kernel/modules']] as [$dupe, $canon]) {
            if (! is_dir($this->base().'/'.$dupe)) continue;   // archived — nothing to drift

            $dupeFiles  = array_map(fn ($p) => substr($p, strlen($dupe) + 1), $this->phpFiles($dupe));
            $canonFiles = array_map(fn ($p) => substr($p, strlen($canon) + 1), $this->phpFiles($canon));
            $this->assertSame($canonFiles, $dupeFiles,
                "'{$dupe}/' and '{$canon}/' hold different file sets — the duplicate kernel has drifted (F1). Run scripts/boundary-cleanup.sh.");

            foreach ($dupeFiles as $rel) {
                $this->assertSame(
                    sha1_file($this->base()."/{$canon}/{$rel}"),
                    sha1_file($this->base()."/{$dupe}/{$rel}"),
                    "'{$dupe}/{$rel}' differs from '{$canon}/{$rel}' — duplicate kernel drift (F1). "
                    ."tests/run.php would be testing a DIFFERENT kernel than production autoloads.");
            }
        }
        $this->addToAssertionCount(1);   // both dirs archived is also a pass
    }

    // --------------------------------- Rule 3: Communication module encapsulation

    public function test_comms_internals_are_private_outside_the_module(): void
    {
        $private = '(Providers\\\\|Contracts\\\\|EmailDispatcher|WhatsAppDispatcher|TemplateRenderer|ProviderResult)';
        $pattern = '/App\\\\Services\\\\Comms\\\\'.$private.'/';

        $insideModule = function (string $rel): bool {
            return str_starts_with($rel, 'app/Services/Comms/')
                || str_starts_with($rel, 'app/Http/Controllers/Comms/')
                || str_starts_with($rel, 'app/Models/Comm')            // the module owns its models
                || $rel === 'app/Http/Controllers/Panel/EmailCenterController.php'
                || $rel === 'app/Support/Comms.php'
                || str_starts_with($rel, 'app/Jobs/SendComm')
                || str_starts_with($rel, 'app/Console/Commands/Comms');
        };

        $bad = [];
        foreach ($this->phpFiles('app') as $rel) {
            if ($insideModule($rel)) continue;
            if (preg_match($pattern, file_get_contents($this->base().'/'.$rel))) {
                $bad[] = $rel;
            }
        }
        $this->assertSame([], $bad,
            "Communication internals referenced outside the module — use App\\Support\\Comms / CommCenter / "
            ."EmailService / WhatsAppService / CampaignService instead (MODULE-MAP Rule 3):\n".implode("\n", $bad));
    }

    // ---------------------------------- Rule 4: closed-world cross-domain edges

    public function test_cross_domain_edges_match_the_baseline(): void
    {
        // Layer-1 platform services: legitimate targets for every domain.
        $platform = ['WhatsApp', 'Money', 'Comms'];

        // Baseline domain→domain edges, measured 2026-07-18 (MODULE-MAP). SHRINK, don't grow.
        $baseline = [
            'Sales→Bot', 'Sales→Storefront', 'Sales→Inventory',
            'Bot→Catalogue',
            'Finance→Money',            // Money is platform; kept for completeness
            'Money→Invoicing',          // REVERSED edge — queued for inversion (F5)
            'Analytics→Bot',
            'Procurement→Inventory',
        ];

        $servicesDir = $this->base().'/app/Services';
        $domains = array_values(array_filter(scandir($servicesDir), fn ($d) => $d[0] !== '.' && is_dir($servicesDir.'/'.$d)));

        $edges = [];
        foreach ($domains as $d) {
            foreach ($this->phpFiles('app/Services/'.$d) as $rel) {
                $code = file_get_contents($this->base().'/'.$rel);
                if (preg_match_all('/use App\\\\Services\\\\([A-Za-z]+)\\\\/', $code, $m)) {
                    foreach ($m[1] as $target) {
                        if ($target === $d || ! in_array($target, $domains, true)) continue;
                        if (in_array($target, $platform, true)) continue;   // platform edges are open by rule
                        $edges[$d.'→'.$target] = true;
                    }
                }
            }
        }

        $new = array_values(array_diff(array_keys($edges), $baseline));
        sort($new);
        $this->assertSame([], $new,
            "NEW cross-domain dependency — route it through the target module's public API, or add it to the "
            ."baseline in this test AND MODULE-MAP.md with a reason (Rule 4):\n".implode("\n", $new));
    }
}
