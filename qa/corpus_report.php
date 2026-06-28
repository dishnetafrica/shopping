<?php
// Golden-corpus analytics + baseline. Routes every customer message, replays ORDER messages through
// the Draft Order extractor, and reports the vocabulary the resolver must support. PII-safe output
// (aggregates + masked samples). Run: php qa/corpus_report.php [path-to-csv]
require __DIR__.'/../app/Services/Bot/CatalogueMatcher.php';
require __DIR__.'/../app/Services/Bot/ShoppingParser.php';
require __DIR__.'/../app/Services/Bot/LocationDictionary.php';
require __DIR__.'/../app/Services/Bot/ProductAlias.php';
require __DIR__.'/../app/Apps/Orders/MessageRouter.php';
foreach (['Dto/CartLine','Dto/DraftOrder','Dto/EntityBundle','Extract/AddressRecognizer','Extract/TimeParser',
          'Extract/PaymentDetector','Extract/PhoneDetector','Extract/EntityExtractor'] as $f) require __DIR__."/../app/Apps/Orders/$f.php";
use App\Apps\Orders\MessageRouter;
use App\Apps\Orders\Extract\EntityExtractor;

$path = $argv[1] ?? '/tmp/pals.csv';
$fh = fopen($path, 'r'); $h = fgetcsv($fh); $ci = array_flip($h);
$router = new MessageRouter(); $ex = new EntityExtractor();

$in = 0; $intents = []; $orderMsgs = []; $gujlish = 0; $botClarify = 0; $botOut = 0;
$STOP = array_flip(['','che','che?','price','aaje','banawana','cho','to','ane','ma','nu','no','na','ni','che.','please','pls','ok','ha','haa','etle','thi','for','the','and','is','a','of','me','i','u','plz','kr','karo','also']);
$tokens = []; $msgFreq = [];
function mask($s){ return preg_replace('/\d{6,}/', '######', (string)$s); }

while (($r = fgetcsv($fh)) !== false) {
    $dir = $r[$ci['dir']] ?? ''; $body = trim((string)($r[$ci['body']] ?? ''));
    if ($dir === 'out') { $botOut++; if (preg_match('/\b(clarify|did you mean|which one|couldn.?t find|not sure|do you mean)\b/i', $body)) $botClarify++; continue; }
    if ($dir !== 'in' || $body === '') continue;
    $in++;
    $msgFreq[mb_strtolower($body)] = ($msgFreq[mb_strtolower($body)] ?? 0) + 1;
    if (preg_match('/[\x{0A80}-\x{0AFF}]/u', $body) || preg_match('/\b(cho|che|nathi|joiye|moklu|lavanu|kem|aaje|kitla|ketla|aapo|banawana)\b/i', mb_strtolower($body))) $gujlish++;
    $intent = $router->route($body);
    $intents[$intent] = ($intents[$intent] ?? 0) + 1;
    if ($intent === MessageRouter::ORDER || $intent === MessageRouter::ORDER_UPDATE) {
        $orderMsgs[] = $body;
        foreach ($ex->extract($body)->products as $p) {
            $n = mb_strtolower($p['name']); if (!isset($STOP[$n]) && mb_strlen($n) >= 2) $tokens[$n] = ($tokens[$n] ?? 0) + 1;
        }
    }
}
fclose($fh);

// order extraction coverage
$withProduct = 0; $weights = 0;
foreach ($orderMsgs as $m) { $b = $ex->extract($m); if ($b->products) { $withProduct++; foreach ($b->products as $p) if ($p['weight_grams']) $weights++; } }

echo "================= PAL'S CORPUS REPORT =================\n";
echo "Incoming customer messages: $in\n";
echo "Gujlish/Gujarati messages : $gujlish (".round(100*$gujlish/$in)."%)\n\n";
echo "--- Intent distribution (router) ---\n";
arsort($intents); foreach ($intents as $k=>$v) printf("  %-13s %5d  %3.0f%%\n",$k,$v,100*$v/$in);
echo "\n--- Order parsing coverage ---\n";
printf("  Routed to ORDER/UPDATE      : %d (%.0f%% of incoming)\n", count($orderMsgs), 100*count($orderMsgs)/$in);
printf("  …yielding >=1 product line  : %d (%.0f%% of order msgs)\n", $withProduct, count($orderMsgs)?100*$withProduct/count($orderMsgs):0);
printf("  …product lines with weight  : %d\n", $weights);
echo "\n--- Bot baseline (from OUT messages) ---\n";
printf("  Bot messages: %d ; clarification-style: %d (%.0f%%)\n", $botOut, $botClarify, $botOut?100*$botClarify/$botOut:0);
echo "\n--- Top product tokens seen in ORDER messages (vocabulary to support) ---\n";
arsort($tokens); $i=0; foreach ($tokens as $t=>$c){ if($i++>=25)break; printf("  %-22s %d\n", mask($t), $c); }
echo "\n--- Top 15 most-frequent incoming messages (routing sanity) ---\n";
arsort($msgFreq); $i=0; foreach ($msgFreq as $m=>$c){ if($i++>=15)break; printf("  %4d  %s\n",$c, mb_substr(mask($m),0,40)); }
echo "\n--- Sample ORDER messages (masked) ---\n";
foreach (array_slice($orderMsgs,0,12) as $m) echo "   • ".mb_substr(mask($m),0,60)."\n";
