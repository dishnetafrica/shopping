<?php declare(strict_types=1);
// Pure test: the EuroPearl brand defaults apply ONLY to a tenant that opts in via the
// `mfr_brand_defaults` setting — never to a manufacturer tenant at large. Run: php qa/brand_defaults_gate.php
require __DIR__ . '/../app/Support/BrandDefaults.php';
use App\Support\BrandDefaults;

// Minimal stub mirroring Tenant::setting($key, $default).
class FakeTenant {
    public function __construct(private array $s) {}
    public function setting(string $k, $d = null) { return $this->s[$k] ?? $d; }
}

$pass = 0; $fail = 0;
function check(string $l, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
}

check('opted-in tenant (flag true) → defaults apply',  BrandDefaults::enabledFor(new FakeTenant(['mfr_brand_defaults' => true])),  true);
check('flag false → no defaults',                      BrandDefaults::enabledFor(new FakeTenant(['mfr_brand_defaults' => false])), false);
check('flag absent (other manufacturer) → no defaults', BrandDefaults::enabledFor(new FakeTenant([])),                              false);
check('null tenant → no defaults',                     BrandDefaults::enabledFor(null),                                            false);
check('flag "1" string is truthy → applies',           BrandDefaults::enabledFor(new FakeTenant(['mfr_brand_defaults' => '1'])),   true);

echo "\n" . ($fail === 0 ? "ALL PASS: $pass passed, 0 failed\n" : "FAILURES: $fail failed, $pass passed\n");
exit($fail === 0 ? 0 : 1);
