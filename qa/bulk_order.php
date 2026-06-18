<?php
/** BulkOrderParser — multi-line order detection + line parsing. Pure logic. */
require __DIR__ . '/../app/Services/Bot/BulkOrderParser.php';

use App\Services\Bot\BulkOrderParser;

$pass = 0; $fail = 0;
function ok($cond, string $l): void { global $pass,$fail; if($cond)$pass++; else {$fail++; echo "  FAIL $l\n";} }
function eq($got,$want,string $l): void { global $pass,$fail; if($got===$want)$pass++; else {$fail++; echo "  FAIL $l → ".var_export($got,true)." != ".var_export($want,true)."\n";} }

// The real reported message
$msg = "Jsk \n2 packet panipuri \n2 packet kachori \n1 packet ratlami sev \n1 packet masala sing \n1 plain boondi";
ok(BulkOrderParser::looksLikeBulkOrder($msg), 'real message is bulk order');
$rows = BulkOrderParser::parseAll($msg);
eq(count($rows), 5, 'greeting dropped, 5 item lines');
eq($rows[0], ['qty'=>2,'query'=>'panipuri'], 'packet unit stripped');
eq($rows[1], ['qty'=>2,'query'=>'kachori'], 'kachori');
eq($rows[2], ['qty'=>1,'query'=>'ratlami sev'], 'multiword product kept');
eq($rows[3], ['qty'=>1,'query'=>'masala sing'], 'masala not stripped as unit');
eq($rows[4], ['qty'=>1,'query'=>'plain boondi'], 'flavour word plain kept');

// Quantity formats
eq(BulkOrderParser::parseLine('2x kachori'), ['qty'=>2,'query'=>'kachori'], '2x form');
eq(BulkOrderParser::parseLine('3 - sev'),    ['qty'=>3,'query'=>'sev'],     'dash form');
eq(BulkOrderParser::parseLine('10 pcs samosa'), ['qty'=>10,'query'=>'samosa'], 'pcs stripped');

// Must NOT treat ordinary messages as bulk orders
ok(! BulkOrderParser::looksLikeBulkOrder('hi'), 'greeting only → not bulk');
ok(! BulkOrderParser::looksLikeBulkOrder('do you have rice?'), 'question → not bulk');
ok(! BulkOrderParser::looksLikeBulkOrder('2 panipuri'), 'single line → not bulk (normal flow handles)');
ok(! BulkOrderParser::looksLikeBulkOrder("hi\n2 panipuri"), 'greeting + 1 item → not bulk');
ok(! BulkOrderParser::looksLikeBulkOrder("I need some snacks\nfor a party"), 'prose lines → not bulk');
eq(BulkOrderParser::parseLine('just looking'), null, 'no qty → null');
eq(BulkOrderParser::parseLine('2 packet'), null, 'qty + unit only, no product → null');

echo "bulk_order: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
