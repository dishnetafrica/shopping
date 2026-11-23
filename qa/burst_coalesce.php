<?php declare(strict_types=1);
// Pure test: burst coalescing never leaves a message unanswered (App\Support\BurstCoalesce). The cases are the
// live ones of 23 Nov 2026. Each job runs alone (one chat's jobs never overlap), in the order given.
//   php qa/burst_coalesce.php
require __DIR__ . '/../app/Support/BurstCoalesce.php';

use App\Support\BurstCoalesce as B;

$pass = 0; $fail = 0;
function chk(string $l, $g, $w): void {
    global $pass, $fail;
    if ($g === $w) { $pass++; echo "  [PASS] $l\n"; }
    else { $fail++; echo "  [FAIL] $l — got " . json_encode($g) . " want " . json_encode($w) . "\n"; }
}

/** Run jobs in $order (their numbers) after $latest numbers were handed out; which ones reply? */
function run(array $order, int $latest, int $started = 0): array
{
    $replied = [];
    foreach ($order as $seq) {
        if (B::silent($seq, $latest, $started)) {
            continue;
        }
        $started = B::started($seq, $started, $latest);
        $replied[] = $seq;
    }

    return $replied;
}

echo "== a burst still gets ONE answer when the newest runs last\n";
chk('two lines, in order: only the newest replies (it reads both)', run([1, 2], 2), [2]);
chk('three lines, in order (live 16:09: "So we can coordinate" / "I am headed home now" / "0921 887784")', run([1, 2, 3], 3), [3]);
chk('one message: it replies', run([1], 1), [1]);

echo "== the newest ran first: the older one is answered, never dropped\n";
chk('live 11:59: "But how much…" (2) ran before "Around Juba" (1) — both reply', run([2, 1], 2), [2, 1]);
chk('three, newest first: the two older were never seen — they reply', run([3, 1, 2], 3), [3, 1, 2]);
chk('three, middle first: the newest has not run yet, so it answers all three', run([2, 1, 3], 3), [3]);
chk('every message in every order is either answered or covered by a later reply', (function () {
    foreach ([[1, 2, 3], [1, 3, 2], [2, 1, 3], [2, 3, 1], [3, 1, 2], [3, 2, 1]] as $o) {
        $r = run($o, 3);
        foreach ([1, 2, 3] as $m) {
            $pos = array_search($m, $o, true);
            $coveredLater = false;
            foreach ($r as $x) {
                if (array_search($x, $o, true) >= $pos) { $coveredLater = true; }   // a reply that ran after this message was logged
            }
            if (! $coveredLater) { return json_encode($o) . " drops {$m}"; }
        }
    }
    return 'ok';
})(), 'ok');

echo "== a mark left from an earlier burst\n";
chk('the numbering restarted (stale mark 7): the burst behaves normally', run([1, 2], 2, 7), [2]);
chk('the stale mark is replaced, not kept', B::started(1, 7, 2), 1);
chk('not coalesced (number 0): never silent', B::silent(0, 5, 0), false);

echo "== the count restarts (Codex on #178): one combined answer, never two\n";
// The webhook clears the mark when it starts a new count, so a new burst starts from 0 …
chk('new burst after a reset (mark cleared): only the newest replies', run([1, 2], 2, 0), [2]);
// … and without that clearing, an inherited mark equal to the new count WOULD let both reply — the case fixed:
chk('(why the webhook clears it: an inherited mark of 2 would make 1 reply too)', run([1, 2], 2, 2), [1, 2]);
chk('a job left from the earlier burst (number 5, count now 2) leaves the mark alone', B::started(5, 1, 2), 1);
chk('…and it is not silenced by the new count', B::silent(5, 2, 0), false);

echo "\n" . ($fail ? "FAILURES" : "ALL PASS") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
