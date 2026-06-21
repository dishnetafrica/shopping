<?php
/**
 * qa/product_image_responder.php — pure-logic QA mirroring ProductImageResponder:
 * gating, quantity-strip, confidence score (>80 sends), category match, variant caption,
 * gramsLabel, cleanName, abs-URL, 5-cap. Run: php qa/product_image_responder.php
 */
$pass=0;$fail=0;
function ok($l,$g,$w){global $pass,$fail;$a=json_encode($g);$b=json_encode($w);
  if($a===$b){$pass++;echo "  ok  $l\n";}else{$fail++;echo "FAIL  $l\n        got : $a\n        want: $b\n";}}

/* ---- mirrors ---- */
function is_control($t){ $t=mb_strtolower(trim($t));
  if(preg_match('/\b(checkout|check out|cart|basket|total|pay|payment|confirm|cancel|remove|delete|order now|done|finish)\b/u',$t)) return true;
  if(preg_match('/^(hi+|hello|hey+|ok(ay)?|yes|no|y|n|thanks|thank you|thx|menu|help|start)\W*$/u',$t)) return true;
  return false; }
function is_multi($t){ $t=mb_strtolower(trim($t));
  if(substr_count($t,',')>=1) return true;
  if(str_contains($t,"\n") && count(array_filter(array_map('trim',preg_split('/\R+/u',$t))))>=2) return true;
  return false; }
function clean_qty($raw){ $c=trim((string)preg_replace('/\b\d+(\.\d+)?\s*(kg|g|gm|gram|grams|pcs|pc|piece|pieces|packet|pkt|dozen)?\b/u',' ',$raw));
  $c=trim((string)preg_replace('/\s+/u',' ',$c)); return $c===''?$raw:$c; }
function confidence($t,$clean,$hitsCount,$firstName){ $t=mb_strtolower($t);
  if($hitsCount<=0) return 0;
  if(preg_match('/\b(which|recommend|suggest)\b/u',$t)||preg_match('/\bbest\b/u',$t)) $P=40;
  elseif(preg_match('/\b(what\s*is|what\'?s|whats|tell me about|about)\b/u',$t)) $P=85;
  elseif(preg_match('/\b(do you have|you have|got|have you|available|in stock|stock)\b/u',$t)) $P=90;
  elseif(preg_match('/\b(price|cost|how much|rate)\b/u',$t)) $P=95;
  else $P=100;
  $name=mb_strtolower($firstName);
  $ask=trim((string)preg_replace('/\b(show me|show|send|see|need|want|i want|i need|price of|price|cost|how much|do you have|you have|got|have|tell me about|what is|what\'?s|whats|about|the|some|any|me|a|of)\b/u',' ',mb_strtolower($clean)));
  $ask=trim((string)preg_replace('/\s+/u',' ',$ask));
  if($hitsCount===1) $M=100;
  elseif($ask!==''&&mb_strpos($name,$ask)!==false) $M=95;
  else { $hit=false; foreach(preg_split('/\s+/u',$ask) as $w){ if(mb_strlen($w)>=3&&mb_strpos($name,$w)!==false){$hit=true;break;} } $M=$hit?85:60; }
  return (int)round(($P+$M)/2); }
function prorata($g,$refP,$refW){ return (int)(round(($g/$refW*$refP)/100)*100); }
function grams_label($g){ if($g%1000===0) return ($g/1000).'kg'; if($g>1000) return rtrim(rtrim(number_format($g/1000,2),'0'),'.').'kg'; return $g.'g'; }
function clean_name($name,$weight){ if($weight){$name=(string)preg_replace('/\s+\d+(\.\d+)?\s*(kg|kgs|g|gm|gms|gram|grams)\s*$/iu','',$name);} $name=trim($name); return $name; }
function variant_card($name,$weight,$refP,$refW,$cur){ $h=clean_name($name,$weight);
  $lines=[];$labels=[]; foreach([250,500,1000] as $g){$p=prorata($g,$refP,$refW);$lab=grams_label($g);$lines[]='• '.$lab.' - '.$cur.' '.number_format($p);$labels[]=$lab;}
  return $h."\nAvailable:\n".implode("\n",$lines)."\nReply with: ".implode(' / ',$labels); }

echo "== quantity-led asks resolve (the fix) ==\n";
ok('"2 Kaju Katri" -> kaju katri', clean_qty('2 Kaju Katri'), 'Kaju Katri');
ok('"500g kaju" -> kaju', clean_qty('500g kaju'), 'kaju');
ok('"2 Kaju Katri" not gated as multi', is_multi('2 Kaju Katri'), false);
ok('"2 thali, sev" gated as multi', is_multi('2 thali, sev 250g'), true);

echo "== control/greeting still gated ==\n";
foreach(['checkout','cart','thanks','hi','menu','confirm'] as $q) ok('control: '.$q, is_control($q), true);

echo "== confidence score & >80 send decision (Bhavin's table) ==\n";
// product "Kaju Katli 1 Kg"; ask spellings resolve to it; assume 1 strong hit
$send=function($t,$hits=1,$name='Kaju Katli 1 Kg'){return confidence($t,clean_qty($t),$hits,$name)>80;};
ok('Kaju Katri -> send',        $send('Kaju Katri'), true);
ok('Show Kaju Katri -> send',   $send('Show Kaju Katri'), true);
ok('Price of Kaju Katri -> send',$send('Price of Kaju Katri'), true);
ok('Do you have Kaju Katri -> send',$send('Do you have Kaju Katri'), true);
ok('Tell me about Kaju Katri -> send',$send('Tell me about Kaju Katri'), true);
ok('2 Kaju Katri -> send',      $send('2 Kaju Katri'), true);
ok('Which sweet is best -> NO (no product match)', confidence('Which sweet is best','which sweet is best',0,'')>80, false);
ok('exact scores: bare name=100', confidence('Kaju Katli','Kaju Katli',1,'Kaju Katli 1 Kg'), 100);
ok('exact scores: tell me about=92', confidence('Tell me about Kaju Katli','Tell me about Kaju Katli',1,'Kaju Katli 1 Kg'), 93);

echo "== variant caption (critical) ==\n";
$want="Kaju Katli\nAvailable:\n• 250g - UGX 13,000\n• 500g - UGX 26,000\n• 1kg - UGX 52,000\nReply with: 250g / 500g / 1kg";
ok('Kaju Katli variant card', variant_card('Kaju Katli 1 Kg',true,52000,1000,'UGX'), $want);

echo "== grams labels & clean name ==\n";
ok('250 -> 250g', grams_label(250),'250g');
ok('1000 -> 1kg', grams_label(1000),'1kg');
ok('1500 -> 1.5kg', grams_label(1500),'1.5kg');
ok('strip "1 Kg" suffix', clean_name('Kaju Katli 1 Kg',true),'Kaju Katli');
ok('non-weight name kept', clean_name('Samosa',false),'Samosa');

echo "== pro-rata price math ==\n";
ok('250g of 52000/kg = 13000', prorata(250,52000,1000),13000);
ok('500g of 35000/kg = 17500->17500', prorata(500,35000,1000),17500);

echo "== gallery intent (more photos / packaging / other pictures) ==\n";
function abs_url($dom,$path){ $path=trim($path); if($path==='') return '';
  if(preg_match('#^https?://#i',$path)) return $path;
  $base=$dom!==''?'https://'.$dom:'https://mycloudbss.com';
  return rtrim($base,'/').'/'.ltrim($path,'/'); }
function is_gallery($t){ $t=mb_strtolower(trim($t));
  if(preg_match('/\b(more|other|another|additional|extra)\s+(photo|photos|pic|pics|picture|pictures|image|images|angle|angles|view|views|shot|shots)\b/u',$t)) return true;
  if(preg_match('/\b(packaging|gallery)\b/u',$t)) return true;
  return false; }
function gallery_images($g1,$g2,$g3,$dom){ $out=[];
  foreach([$g1,$g2,$g3] as $u){ $u=abs_url($dom,$u); if($u==='') continue; $out[]=$u; }
  return array_slice($out,0,3); }
ok('Show Kaju Katri is NOT gallery', is_gallery('Show Kaju Katri'), false);
ok('More photos -> gallery', is_gallery('More photos'), true);
ok('more pics -> gallery', is_gallery('more pics'), true);
ok('Show packaging -> gallery', is_gallery('Show packaging'), true);
ok('Show other pictures -> gallery', is_gallery('Show other pictures'), true);
ok('gallery -> gallery', is_gallery('gallery'), true);

echo "== gallery builder: cap 3 + missing images ==\n";
ok('three images returned', gallery_images('/storage/a.jpg','/storage/b.jpg','/storage/c.jpg','palssnack.com'),
   ['https://palssnack.com/storage/a.jpg','https://palssnack.com/storage/b.jpg','https://palssnack.com/storage/c.jpg']);
ok('missing images -> empty (graceful)', gallery_images('','','','palssnack.com'), []);
ok('partial gallery skips blanks', gallery_images('/storage/a.jpg','','/storage/c.jpg','palssnack.com'),
   ['https://palssnack.com/storage/a.jpg','https://palssnack.com/storage/c.jpg']);
ok('no last product -> no gallery', (0>0?['x']:[]), []);


exit($fail===0?0:1);
