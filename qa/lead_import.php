<?php
/** LeadImport: phone normalisation + row parsing. Pure logic. */
require __DIR__ . '/../app/Services/Bot/LeadImport.php';

use App\Services\Bot\LeadImport;

$pass = 0; $fail = 0;
function eq($got, $want, string $l): void { global $pass,$fail; if($got===$want)$pass++; else {$fail++; echo "  FAIL $l → got ".var_export($got,true)." want ".var_export($want,true)."\n";} }

// --- normalizePhone (default cc 211, South Sudan) ---
eq(LeadImport::normalizePhone('+211912345678'), '211912345678', 'plus cc');
eq(LeadImport::normalizePhone('211912345678'),  '211912345678', 'bare cc');
eq(LeadImport::normalizePhone('0912345678'),    '211912345678', 'local trunk 0');
eq(LeadImport::normalizePhone('912345678'),     '211912345678', 'national 9-digit');
eq(LeadImport::normalizePhone('00211912345678'),'211912345678', '00 intl prefix');
eq(LeadImport::normalizePhone('+211 912 345 678'), '211912345678', 'spaces stripped');
eq(LeadImport::normalizePhone('(211)912-345-678'), '211912345678', 'punctuation stripped');
eq(LeadImport::normalizePhone('abc'),           null,           'non-numeric → null');
eq(LeadImport::normalizePhone('123'),           null,           'too short → null');

// --- normalizePhone with cc 256 (Uganda) ---
eq(LeadImport::normalizePhone('0772123456', '256'), '256772123456', 'UG local 0');
eq(LeadImport::normalizePhone('772123456', '256'),  '256772123456', 'UG national');
eq(LeadImport::normalizePhone('+256772123456', '256'), '256772123456', 'UG plus');
// already-prefixed SS number should survive even under a UG default cc
eq(LeadImport::normalizePhone('211912345678', '256'), '211912345678', 'keep existing SS cc');

// --- parseRows ---
$r = LeadImport::parseRows("+211912345678\n+211923456789\n0912000000");
eq(count($r), 3, 'paste 3 numbers');
eq($r[0]['phone'], '+211912345678', 'first phone raw');

$r2 = LeadImport::parseRows("Name,Phone,Source,Tag\nJohn,+211912345678,WhatsApp Export,Starlink\nMary,+211923456789,Referral,Fiber");
eq(count($r2), 2, 'header CSV → 2 rows');
eq($r2[0]['name'], 'John', 'header name');
eq($r2[0]['tag'], 'Starlink', 'header tag');
eq($r2[1]['source'], 'Referral', 'header source');

$r3 = LeadImport::parseRows("John,+211912345678\nMary,+211923456789");
eq($r3[0]['name'], 'John', 'positional name');
eq($r3[0]['phone'], '+211912345678', 'positional phone');

// phone-first positional
$r4 = LeadImport::parseRows("+211912345678,John");
eq($r4[0]['phone'], '+211912345678', 'phone-first detected');
eq($r4[0]['name'], 'John', 'name after phone');

// blank lines ignored; row with no phone dropped
$r5 = LeadImport::parseRows("\n\nJustAName\n+211912345678\n");
eq(count($r5), 1, 'blank + nameless dropped');

echo "lead_import: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
