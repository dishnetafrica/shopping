<?php
/** LeadScorer rule-based scoring. Pure logic. */
require __DIR__ . '/../app/Services/Bot/LeadScorer.php';

use App\Services\Bot\LeadScorer;

$s = new LeadScorer();
$pass = 0; $fail = 0;
function eq(int $got, int $want, string $label): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL: $label → got $got, want $want\n"; }
}
function ge(int $got, int $min, string $label): void {
    global $pass, $fail;
    if ($got >= $min) { $pass++; }
    else { $fail++; echo "  FAIL: $label → got $got, want >= $min\n"; }
}

// Examples from the spec
eq($s->score('lead', 'Need internet'),        30, 'plain lead base');
eq($s->score('lead', 'Need Starlink today'),  90, 'starlink + urgency');
eq($s->score('lead', 'Call me urgently'),     90, 'call + urgency');

// Component checks
eq($s->score('lead', 'I want a quotation'),   50, 'high-value only');
eq($s->score('lead', 'please call me'),       50, 'call only');
eq($s->score('lead', 'urgent installation'),  90, 'urgency + high');
ge($s->score('ticket', 'internet down'),      45, 'ticket base floor');
eq($s->score('lead', 'how are you'),          30, 'no signals → base');

// Banding
$ok = ($s->band(90) === '🔥 Hot') && ($s->band(50) === 'Warm') && ($s->band(20) === 'Cold');
if ($ok) { $pass++; } else { $fail++; echo "  FAIL: banding\n"; }

echo "lead_score: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
