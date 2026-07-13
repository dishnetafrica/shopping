<?php
/**
 * Enforces Capability Platform rules without booting Laravel. Run: php scripts/capability-arch-check.php
 *  1) The Http transport must never reference App\Models or App\Services.
 *  2) The registration dependency graph must be closed (every dependsOn is a registered key).
 */
$root = dirname(__DIR__);
$violations = [];

foreach (glob($root . '/app/Capability/Http/*.php') as $file) {
    $src = file_get_contents($file);
    if (preg_match('/use\s+App\\\\Models\\\\/', $src)) {
        $violations[] = basename($file) . ' imports App\\Models\\* (forbidden in Http layer)';
    }
    if (preg_match('/use\s+App\\\\Services\\\\/', $src)) {
        $violations[] = basename($file) . ' imports App\\Services\\* (forbidden in Http layer)';
    }
    if (preg_match('/App\\\\Models\\\\\w+::/', $src)) {
        $violations[] = basename($file) . ' calls a Model statically (forbidden in Http layer)';
    }
    if (! str_contains($src, 'CapabilityRegistry')) {
        $violations[] = basename($file) . ' does not resolve through the CapabilityRegistry';
    }
}

$provider = file_get_contents($root . '/app/Capability/Registry/CoreCapabilityProvider.php');
preg_match_all("/key:\s*'([a-z0-9.]+)'/i", $provider, $km);
$keys = array_values(array_unique($km[1]));
preg_match_all("/dependsOn:\s*\[([^\]]*)\]/", $provider, $dm);
foreach ($dm[1] as $depList) {
    preg_match_all("/'([a-z0-9.]+)'/i", $depList, $d);
    foreach ($d[1] as $dep) {
        if (! in_array($dep, $keys, true)) {
            $violations[] = "dependency graph: '{$dep}' is depended on but never registered";
        }
    }
}

if ($violations) {
    fwrite(STDERR, "ARCHITECTURE VIOLATIONS:\n - " . implode("\n - ", $violations) . "\n");
    exit(1);
}
echo "OK (1/2): Http transport references no App\\Models / App\\Services; resolves via the registry.\n";
echo 'OK (2/2): ' . count($keys) . " capabilities registered; dependency graph closed.\n";
echo '  keys: ' . implode(', ', $keys) . "\n";
exit(0);
