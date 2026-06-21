<?php
/**
 * qa/product_image_responder.php — pure-logic QA mirroring ProductImageResponder's
 * decision rules (gating, category match, abs-URL, caption, confidence, 5-cap).
 * Run: php qa/product_image_responder.php
 */
$pass=0;$fail=0;
function ok($l,$g,$w){global $pass,$fail;$a=json_encode($g);$b=json_encode($w);
  if($a===$b){$pass++;echo "  ok  $l\n";}else{$fail++;echo "FAIL  $l\n        got : $a\n        want: $b\n";}}

/* ---- mirrors of the responder's pure helpers ---- */
function is_control($t){ $t=mb_strtolower(trim($t));
  if(preg_match('/\b(checkout|check out|cart|basket|total|pay|payment|confirm|cancel|remove|delete|order now|done|finish)\b/u',$t)) return true;
  if(preg_match('/^(hi+|hello|hey+|ok(ay)?|yes|no|y|n|thanks|thank you|thx|menu|help|start)\W*$/u',$t)) return true;
  return false; }
function is_order_shaped($t){ $t=mb_strtolower(trim($t));
  if(substr_count($t,',')>=1) return true;
  if(preg_match('/\b\d+\s*(kg|g|gm|gram|grams|pcs|pc|piece|pieces|packet|pkt|dozen)\b/u',$t)) return true;
  if(preg_match('/^\d+\s+\S/u',$t)) return true;
  return false; }
function match_category(array $cats, array $extra, $t){ $t=mb_strtolower(trim($t));
  $body=trim((string)preg_replace('/^(show me|show|see|browse|view|list)\s+/u','',$t));
  if($body==='') return null;
  foreach(array_merge($cats,$extra) as $c){ $cl=mb_strtolower(trim((string)$c)); if($cl==='') continue;
    if($cl===$body || rtrim($cl,'s')===rtrim($body,'s')) return (string)$c; }
  return null; }
function abs_url($dom,$path){ $path=trim($path); if($path==='') return '';
  if(preg_match('#^https?://#i',$path)) return $path;
  $base=$dom!==''?'https://'.$dom:'https://mycloudbss.com';
  return rtrim($base,'/').'/'.ltrim($path,'/'); }
function card($name,$price,$weight,$unit,$cur){ $u=$weight?('/'.($unit?:'kg')):''; $l=$name;
  if($price>0) $l.=' — '.$cur.' '.number_format($price).$u; return $l; }
function confident($hits,$name,$t){ $name=mb_strtolower($name);
  $ask=trim((string)preg_replace('/\b(show me|show|need|want|i want|i need|price of|price|get me|do you have|have|the|some|any)\b/u','',mb_strtolower($t)));
  return $hits===1 || ($ask!=='' && mb_strpos($name,$ask)!==false); }

echo "== discovery asks are NOT gated out ==\n";
foreach(['Kaju Katri','Show me Jalebi','I want fafda','price of kaju','need samosa'] as $q)
  ok('passes gate: '.$q, (is_control($q)||is_order_shaped($q)), false);

echo "== control / cart / order-shaped ARE gated out ==\n";
foreach(['checkout','cart','please confirm','thanks','hi','menu'] as $q) ok('control: '.$q, is_control($q), true);
foreach(['2 thali, sev 250g','500g kaju','3 pcs samosa','2 fafda'] as $q) ok('order-shaped: '.$q, is_order_shaped($q), true);

echo "== category match (singular/plural tolerant) ==\n";
$cats=['Sweets','Snacks','Dry Fruits & Nuts']; $extra=['Sunday Special'];
ok('show sweets -> Sweets', match_category($cats,$extra,'show sweets'), 'Sweets');
ok('show me snacks -> Snacks', match_category($cats,$extra,'show me snacks'), 'Snacks');
ok('bare "sweets" -> Sweets', match_category($cats,$extra,'sweets'), 'Sweets');
ok('singular "sweet" -> Sweets', match_category($cats,$extra,'sweet'), 'Sweets');
ok('empty extra category browsable', match_category($cats,$extra,'show sunday special'), 'Sunday Special');
ok('unknown category -> null', match_category($cats,$extra,'show rockets'), null);

echo "== absolute URL conversion ==\n";
ok('relative -> custom domain', abs_url('palssnack.com','/storage/products/2/p.png'), 'https://palssnack.com/storage/products/2/p.png');
ok('already absolute kept', abs_url('palssnack.com','https://cdn.x/y.jpg'), 'https://cdn.x/y.jpg');
ok('no domain -> mycloudbss', abs_url('','/storage/a.png'), 'https://mycloudbss.com/storage/a.png');
ok('empty path -> empty', abs_url('palssnack.com',''), '');

echo "== caption card ==\n";
ok('weight product card', card('Kaju Katli 1 Kg',52000,true,'kg','UGX'), 'Kaju Katli 1 Kg — UGX 52,000/kg');
ok('unit product card', card('Samosa',1500,false,'','UGX'), 'Samosa — UGX 1,500');
ok('no price -> name only', card('Fafda',0,true,'kg','UGX'), 'Fafda');

echo "== confidence ==\n";
ok('single hit is confident', confident(1,'Anything','blah'), true);
ok('name contains ask', confident(3,'Kaju Katli 1 Kg','i want kaju'), true);
ok('fuzzy multi-hit not confident', confident(3,'Masala Peanuts','show me dryfruit'), false);

echo "== 5-image cap ==\n";
$six=range(1,6); ok('caps to 5', array_slice($six,0,5), [1,2,3,4,5]);

echo "\n--------------------------------------------------\n";
echo "product_image_responder: {$pass} passed, {$fail} failed\n";
exit($fail===0?0:1);
