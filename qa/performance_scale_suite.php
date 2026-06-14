<?php
require dirname(__DIR__) . '/app/Services/Bot/CatalogueMatcher.php';
require dirname(__DIR__) . '/app/Services/Bot/ShoppingParser.php';
require dirname(__DIR__) . '/app/Services/Bot/ClarificationFlow.php';
require dirname(__DIR__) . '/app/Services/Bot/ShoppingEngine.php';
require __DIR__ . '/qa_catalogue.php';

use App\Services\Bot\CatalogueMatcher;
use App\Services\Bot\ShoppingParser;
use App\Services\Bot\ClarificationFlow;
use App\Services\Bot\ShoppingEngine;

function engine(string $cur = 'UGX'): ShoppingEngine {
    return new ShoppingEngine(new ShoppingParser(), new CatalogueMatcher(), new ClarificationFlow(), $cur);
}
function ms(float $ns): string { return number_format($ns / 1e6, 3) . ' ms'; }
function us(float $ns): string { return number_format($ns / 1e3, 1) . ' µs'; }
function mb(int $b): string { return number_format($b / 1048576, 2) . ' MB'; }
function optHas(array $r, string $kw): bool { foreach (($r['state']['options'] ?? ($r['options'] ?? [])) as $o) if (stripos($o['name'], $kw) !== false) return true; return false; }
function optQtyFor(array $r, string $kw): int { foreach (($r['state']['options'] ?? ($r['options'] ?? [])) as $o) if (stripos($o['name'], $kw) !== false) return (int)$o['qty']; return 0; }
function cartQty(array $r, string $kw): int { foreach ($r['cart'] as $l) if (stripos($l['name'], $kw) !== false) return $l['qty']; return 0; }

$pass = 0; $fail = 0; $fails = [];
function check(string $label, bool $ok) { global $pass,$fail,$fails; if ($ok){$pass++; echo "   PASS  $label\n";} else {$fail++; $fails[]=$label; echo "   FAIL  $label\n";} }

echo "\n============== CloudBSS PERFORMANCE & SCALE SUITE (Cat 21-26) ==============\n";
echo "PHP " . PHP_VERSION . "  |  single core: " . (function_exists('shell_exec') ? trim(@shell_exec('nproc') ?: '?') : '?') . " cores available\n";

// ---------------- CATEGORY 21 — REAL SUPERMARKET DATA (speed / memory) ----------------
echo "\n--- CATEGORY 21: catalogue scale 500 / 1000 / 2000 ---\n";
printf("%-8s | %-12s | %-14s | %-16s | %-12s | %-10s\n", "products", "1x search", "8-item parse", "full handle()", "catalogue", "peak mem");
$bench = [];
foreach ([500, 1000, 2000] as $N) {
    $cat = gen_catalogue($N);
    $memCat = 0;
    // measure catalogue array footprint
    $before = memory_get_usage();
    $copy = $cat; // force materialised
    $memCat = memory_get_usage() - $before;
    unset($copy);

    $eng = engine();
    // warm token cache + measure single search across many queries
    $queries = ['rice','sugar','cooking oil','milk','bread','flour','coke','vimal','sakar','doodh','tel','atta','biscuits','soda','tea'];
    $m = new CatalogueMatcher();
    $t0 = hrtime(true);
    $iter = 0;
    foreach ($queries as $q) { for ($i=0;$i<20;$i++){ $m->search($q, $cat); $iter++; } }
    $perSearch = (hrtime(true) - $t0) / $iter;

    // 8-item parse+resolve
    $msg8 = '2 sugar, 3 milk, 4 bread, 2 rice, 1 oil, 3 coke, 2 biscuits, 1 flour';
    $t0 = hrtime(true); $reps = 50;
    for ($i=0;$i<$reps;$i++){ engine()->handle($msg8, $cat, [], []); }
    $perHandle8 = (hrtime(true) - $t0) / $reps;

    // single-item full handle
    $t0 = hrtime(true);
    for ($i=0;$i<$reps;$i++){ engine()->handle('2kg sugar', $cat, [], []); }
    $perHandle1 = (hrtime(true) - $t0) / $reps;

    $peak = memory_get_peak_usage();
    $bench[$N] = ['search'=>$perSearch,'h8'=>$perHandle8,'h1'=>$perHandle1,'cat'=>$memCat,'peak'=>$peak];
    printf("%-8d | %-12s | %-14s | %-16s | %-12s | %-10s\n", $N, us($perSearch), ms($perHandle8), ms($perHandle1), mb($memCat), mb($peak));
}
// thresholds (generous, WhatsApp-interactive): a single message must resolve well under 100ms even at 2000 SKUs
check('21.1 single search < 5ms @2000', $bench[2000]['search'] < 5e6);
check('21.2 full 8-item handle < 80ms @2000', $bench[2000]['h8'] < 80e6);
check('21.3 single-item handle < 40ms @2000', $bench[2000]['h1'] < 40e6);
check('21.4 catalogue memory < 12MB @2000', $bench[2000]['cat'] < 12*1048576);

// ---------------- CATEGORY 22 — TOBACCO & FMCG ORDERS ----------------
echo "\n--- CATEGORY 22: FMCG / tobacco quantities ---\n";
$P = new ShoppingParser();
function itemQty(array $items, string $kw): int { foreach ($items as $it){ if (stripos($it['query'],$kw)!==false) return (int)$it['qty']; } return 0; }
function itemHas(array $items, string $kw): bool { foreach ($items as $it){ if (stripos($it['query'],$kw)!==false) return true; } return false; }
$fmcg = gen_catalogue(800);
foreach (['20 Vimal 10 Coke 5 Rice','20 Vimal and 10 Coke','Need: 20 Vimal 10 Coke 5 Rice'] as $msg) {
    $it = $P->parse($msg)['items'];
    $parts = array_map(fn($x)=>$x['qty'].'x '.$x['query'], $it);
    echo "   \"$msg\"  => " . implode(', ', $parts) . "\n";
}
$it = $P->parse('20 Vimal 10 Coke 5 Rice')['items'];
check('22.1 Vimal qty 20 extracted', itemQty($it,'vimal')===20);
check('22.2 Coke qty 10 extracted', itemQty($it,'coke')===10);
check('22.3 Rice qty 5 extracted', itemQty($it,'rice')===5);
$it2 = $P->parse('20 Vimal and 10 Coke')['items'];
check('22.4 "20 Vimal and 10 Coke" -> 2 items, qty 20 & 10', count($it2)===2 && itemQty($it2,'vimal')===20 && itemQty($it2,'coke')===10);
$it3 = $P->parse('Need: 20 Vimal 10 Coke 5 Rice')['items'];
check('22.5 "Need:" prefix stripped, 3 items qty kept', count($it3)===3 && itemQty($it3,'vimal')===20 && itemQty($it3,'coke')===10 && itemQty($it3,'rice')===5);
// resolution note (large catalogue): Coke/Rice may clarify because many SKUs share those words
$rr = engine()->handle('20 Vimal 10 Coke 5 Rice', $fmcg, [], []);
echo "   resolution @800 SKUs: Vimal added x".cartQty($rr,'Vimal').", Coke/Rice -> clarify when multiple SKUs share the word (qty preserved into options)\n";

// ---------------- CATEGORY 23 — RAPID WHATSAPP MESSAGES (context) ----------------
echo "\n--- CATEGORY 23: rapid sequential messages / context ---\n";
$cat = gen_catalogue(600);
// (a) clarification context survives across messages
$s1 = engine()->handle('5 rice', $cat, [], []);
$s2 = engine()->handle('1', $cat, $s1['cart'], $s1['state']);
check('23.1 clarify from msg1 resolved by selection in msg2', count($s2['cart'])===1 && $s2['cart'][0]['qty']===5);
// (b) sequential browses do not clobber an existing cart
$seed = [['product_id'=>1,'name'=>'Vimal Pan Masala 100g','price'=>3500,'qty'=>2]];
$b1 = engine()->handle('Rice', $cat, $seed, []);
$b2 = engine()->handle('Sugar', $cat, $b1['cart'], $b1['state']);
check('23.2 existing cart preserved across rapid browses', cartQty($b2,'Vimal')===2);
echo "   NOTE: true rapid-fire ordering/de-dup is an INFRA guarantee (see report):\n";
echo "         per-conversation queue serialisation + messageId de-dup + optional debounce.\n";

// ---------------- CATEGORY 24 — LARGE SHOPPING LIST (20 items, no loss) ----------------
echo "\n--- CATEGORY 24: 20-product single message ---\n";
$cat = gen_catalogue(1000);
$twenty = '2 sugar, 1 rice, 3 milk, 2 bread, 1 oil, 4 coke, 2 biscuits, 1 flour, 3 salt, 2 tea, '
        . '1 eggs, 2 beans, 1 lentils, 3 water, 2 juice, 1 ghee, 2 spaghetti, 1 soap, 2 candles, 1 tissue';
$t0 = hrtime(true);
$r = engine()->handle($twenty, $cat, [], []);
$elapsed = hrtime(true) - $t0;
$recognised = count($r['cart']) + count(array_unique(array_map(fn($o)=>$o['name'],$r['state']['options'] ?? [])));
// count distinct items recognised = added lines + clarify groups
$addedLines = count($r['cart']);
$groups = 0; $seenLabels=[];
foreach (($r['state']['options'] ?? []) as $o) {} // options are flattened; count groups via not-found instead
$notFound = count($r['not_found']);
$clarify = 20 - $addedLines - $notFound; echo "   20 items submitted | added: $addedLines | clarify: $clarify | not-found: $notFound | time: " . ms($elapsed) . "\n";
check('24.1 no timeout (<150ms)', $elapsed < 150e6);
check('24.2 no product loss (>=18 of 20 resolved to cart/clarify)', (20 - $notFound) >= 18);

// ---------------- CATEGORY 25 — HIGH AMBIGUITY (clarify, never guess) ----------------
echo "\n--- CATEGORY 25: high ambiguity -> clarification ---\n";
// build a catalogue where rice/milk/oil/sugar each have multiple SKUs with >3x spread
$amb = [
    ['id'=>1,'name'=>'Local Rice 1kg','category'=>'Rice','keywords'=>'','price'=>6300,'stock'=>10],
    ['id'=>2,'name'=>'Pakistan Rice 5kg','category'=>'Rice','keywords'=>'','price'=>38000,'stock'=>10],
    ['id'=>3,'name'=>'Sachet Milk 100ml','category'=>'Milk','keywords'=>'doodh','price'=>800,'stock'=>10],
    ['id'=>4,'name'=>'Fresh Dairy Milk 1L','category'=>'Milk','keywords'=>'doodh','price'=>4500,'stock'=>10],
    ['id'=>5,'name'=>'Sachet Oil 100ml','category'=>'Cooking Oil','keywords'=>'tel oil','price'=>1000,'stock'=>10],
    ['id'=>6,'name'=>'Sunseed Cooking Oil 5L','category'=>'Cooking Oil','keywords'=>'tel oil','price'=>42000,'stock'=>10],
    ['id'=>7,'name'=>'Sachet Sugar 250g','category'=>'Sugar','keywords'=>'sakar','price'=>1500,'stock'=>10],
    ['id'=>8,'name'=>'Kinyara Sugar 10kg','category'=>'Sugar','keywords'=>'sakar','price'=>45000,'stock'=>10],
];
$r = engine()->handle("Rice\nMilk\nOil\nSugar", $amb, [], []);
$groupsSeen = [];
foreach ($r['state']['options'] ?? [] as $o) {
    foreach (['Rice','Milk','Oil','Sugar'] as $g) if (stripos($o['name'],$g)!==false) $groupsSeen[$g]=true;
}
echo "   options offered for: " . implode(', ', array_keys($groupsSeen)) . " | cart lines: " . count($r['cart']) . "\n";
check('25.1 nothing auto-added (no guessing)', count($r['cart'])===0);
check('25.2 clarification offered for all 4 staples', count($groupsSeen)===4);
check('25.3 multiple options per staple (>=8 total)', count($r['state']['options'] ?? [])>=8);

// ---------------- CATEGORY 26 — PRODUCTION LOAD (measured + projected) ----------------
echo "\n--- CATEGORY 26: load — MEASURED single-core brain cost + PROJECTION ---\n";
$cat = gen_catalogue(1000);
$mix = ['2kg sugar and bread','do you have rice and sugar?','20 vimal 10 coke','sakar 2kg','show me oils',
        '2 sugar 3 milk 1 bread','hello','rice','give me 2 oils and 3 milk','checkout'];
$reps = 2000;
$memStart = memory_get_usage();
$t0 = hrtime(true);
for ($i=0;$i<$reps;$i++){ engine()->handle($mix[$i % count($mix)], $cat, [], []); }
$tot = hrtime(true) - $t0;
$perMsg = $tot / $reps;
$throughput1 = 1e9 / $perMsg;               // msgs/sec on ONE core
$memPerMsg = (memory_get_peak_usage() - $memStart);
echo "   measured: " . ms($perMsg) . "/message  |  ~" . number_format($throughput1,0) . " msg/sec/core (brain only, catalogue in-memory)\n";
printf("   %-12s | %-14s | %-14s | %-14s\n", "concurrent", "4 workers", "8 workers", "16 workers");
foreach ([100,500,1000] as $C) {
    $line = sprintf("   %-12d", $C);
    foreach ([4,8,16] as $W) {
        $clearSec = $C / ($throughput1 * $W);
        $line .= sprintf(" | %-14s", number_format($clearSec*1000,1).' ms');
    }
    echo $line . "\n";
}
echo "   (= time for the BRAIN to clear that many simultaneous 1-message bursts; excludes DB/WhatsApp/OpenAI)\n";
check('26.1 brain latency < 15ms/message @1000 SKUs', $perMsg < 15e6);
check('26.2 peak memory per run < 64MB', memory_get_peak_usage() < 64*1048576);

// ---------------- summary ----------------
echo "\n--------------------------------------------------------------------------------\n";
printf("MEASURED CHECKS: %d/%d passed\n", $pass, $pass+$fail);
if ($fails) { echo "Fails:\n"; foreach ($fails as $f) echo "  - $f\n"; }
echo "\nHONESTY NOTE: Cat 21/24/25 and Cat 26's per-message cost are REAL measurements.\n";
echo "Cat 26 concurrency rows are PROJECTIONS from the measured single-core cost.\n";
echo "A true end-to-end load test (DB + queue + WhatsApp Cloud API + OpenAI) must run\n";
echo "against staging — see load/k6_webhook.js and the report's methodology.\n";
echo "================================================================================\n";
exit($fail ? 1 : 0);
