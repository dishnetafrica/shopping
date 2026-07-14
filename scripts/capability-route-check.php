<?php

/**
 * Guards against a capability whose key the workspace route regex would reject (e.g. a camelCase
 * key like `salesOrders` against a lowercase-only `[a-z.:]+` constraint), which yields a hard
 * "route could not be found" 404 that mocked front-end tests never catch.
 *
 * Extracts every registered key from CoreCapabilityProvider and the {type} constraint from
 * CapabilityServiceProvider by static text scan (no Laravel bootstrap needed), then asserts each
 * key satisfies the constraint.
 */

$root = dirname(__DIR__);
$provider = file_get_contents($root . '/app/Capability/Registry/CoreCapabilityProvider.php');
$routes = file_get_contents($root . '/app/Providers/CapabilityServiceProvider.php');

// keys: every  key: 'foo'  in a CapabilityManifest registration
preg_match_all("/key:\s*'([^']+)'/", $provider, $m);
// Keys beginning with '_' are internal helpers resolved via the registry, never hit by URL.
$keys = array_values(array_filter(array_unique($m[1]), fn ($k) => $k[0] !== '_'));

// constraint: the {type} route constraint, e.g. ->where('type', '[a-zA-Z.:]+')
if (! preg_match("/where\('type',\s*'([^']+)'\)/", $routes, $c)) {
    fwrite(STDERR, "FAIL: could not find the {type} route constraint\n");
    exit(1);
}
$constraint = $c[1];
$re = '/^(?:' . $constraint . ')$/';

$bad = array_values(array_filter($keys, fn ($k) => ! preg_match($re, $k)));

echo 'Constraint: ' . $constraint . "\n";
echo 'Keys checked: ' . count($keys) . "\n";
if ($bad) {
    echo "FAIL: these capability keys are unroutable under the constraint:\n  - " . implode("\n  - ", $bad) . "\n";
    exit(1);
}
echo "ROUTE-CHECK PASS: every capability key is routable.\n";
