<?php
/** LeadDedupe content-hash keying. Pure logic. */
require __DIR__ . '/../app/Services/Bot/LeadDedupe.php';

use App\Services\Bot\LeadDedupe;

$pass = 0; $fail = 0;
function same(string $a, string $b, string $l): void { global $pass,$fail; if($a===$b)$pass++; else {$fail++; echo "  FAIL (want SAME): $l\n";} }
function diff(string $a, string $b, string $l): void { global $pass,$fail; if($a!==$b)$pass++; else {$fail++; echo "  FAIL (want DIFFERENT): $l\n";} }

$p = '256772123456';

// Different opportunities from the same person must NOT collapse.
diff(LeadDedupe::key($p,'lead','Need Starlink'), LeadDedupe::key($p,'lead','Need Fiber'), 'starlink vs fiber');
diff(LeadDedupe::key($p,'lead','Need Starlink'), LeadDedupe::key($p,'lead','Need Dedicated Internet'), 'starlink vs dedicated');

// Literal repeats (case / punctuation / spacing) collapse to one.
same(LeadDedupe::key($p,'lead','Need Starlink'),  LeadDedupe::key($p,'lead','need starlink!'), 'case+punct repeat');
same(LeadDedupe::key($p,'lead','Need  Starlink'), LeadDedupe::key($p,'lead','Need Starlink'),  'extra spaces');

// Scope: phone + intent must matter.
diff(LeadDedupe::key($p,'lead','Need Starlink'), LeadDedupe::key('256700000000','lead','Need Starlink'), 'different phone');
diff(LeadDedupe::key($p,'lead','Internet down'), LeadDedupe::key($p,'ticket','Internet down'), 'lead vs ticket');

// Unicode safety: two DIFFERENT Gujarati messages must not both normalise to empty → collide.
diff(LeadDedupe::key($p,'lead','સ્ટારલિંક જોઈએ'), LeadDedupe::key($p,'lead','ફાઈબર જોઈએ'), 'distinct gujarati');

// norm() behaviour
same(LeadDedupe::norm('Need  STARLINK!!!'), 'need starlink', 'norm lowercases + strips');

echo "lead_dedupe: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
