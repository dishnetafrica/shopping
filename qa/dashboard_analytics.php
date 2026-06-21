<?php
/**
 * qa/dashboard_analytics.php — pure-logic QA mirroring DashboardAnalytics math:
 * card ratios, daily zero-fill, view->order conversion, funnel, sort/limit.
 * Run: php qa/dashboard_analytics.php
 */
$pass=0;$fail=0;
function ok($l,$g,$w){global $pass,$fail;$a=json_encode($g);$b=json_encode($w);
  if($a===$b){$pass++;echo "  ok  $l\n";}else{$fail++;echo "FAIL  $l\n        got : $a\n        want: $b\n";}}

function checkout_conv($created,$confirmed){ return $created>0?round($confirmed*100/$created,1):0.0; }
function aov($rev,$placed){ return $placed>0?round($rev/$placed):0; }
function combo_conv($imp,$conv){ return $imp>0?round($conv*100/$imp,1):0.0; }
function by_day($raw,$days,$today){ $out=[]; $t=strtotime($today);
  for($i=0;$i<$days;$i++){ $day=date('Y-m-d',$t-($days-1-$i)*86400); $out[]=['label'=>substr($day,5),'value'=>(float)($raw[$day]??0)]; } return $out; }
function viewed_conv($views,$orders,$limit){ arsort($views); $out=[];
  foreach(array_slice(array_keys($views),0,$limit) as $pid){ $v=$views[$pid];$o=$orders[$pid]??0;
    $out[]=['pid'=>$pid,'views'=>$v,'orders'=>$o,'conv_pct'=>$v>0?round($o*100/$v,1):0.0]; } return $out; }
function top_sort($items,$limit){ usort($items,function($a,$b){return $b['value']<=>$a['value'];}); return array_slice($items,0,$limit); }

echo "== card ratios ==\n";
ok('checkout conv 18/50 = 36.0%', checkout_conv(50,18), 36.0);
ok('checkout conv divide-safe', checkout_conv(0,0), 0.0);
ok('AOV 900000/45 = 20000', aov(900000,45), 20000);
ok('AOV divide-safe', aov(0,0), 0);
ok('combo conv 61/220 = 27.7%', combo_conv(220,61), 27.7);

echo "== orders-by-day zero-fill ==\n";
$raw=['2026-06-19'=>2,'2026-06-21'=>5];
ok('missing day filled with 0', by_day($raw,3,'2026-06-21'),
   [['label'=>'06-19','value'=>2.0],['label'=>'06-20','value'=>0.0],['label'=>'06-21','value'=>5.0]]);
ok('all-zero window', by_day([],2,'2026-06-21'),
   [['label'=>'06-20','value'=>0.0],['label'=>'06-21','value'=>0.0]]);

echo "== most-viewed conversion (which need better photos) ==\n";
$mv=viewed_conv([9=>100,4=>50,7=>20],[9=>25,4=>5],10);
ok('high-view low-convert flagged', [$mv[0]['conv_pct'],$mv[1]['conv_pct'],$mv[2]['conv_pct']], [25.0,10.0,0.0]);
ok('sorted by views desc', [$mv[0]['pid'],$mv[1]['pid'],$mv[2]['pid']], [9,4,7]);

echo "== funnel passthrough ==\n";
$f=['viewed'=>120,'added'=>40,'checkout_started'=>18,'confirmed'=>12];
ok('funnel stages descend', [$f['viewed']>=$f['added'],$f['added']>=$f['checkout_started'],$f['checkout_started']>=$f['confirmed']], [true,true,true]);

echo "== top sort + limit ==\n";
ok('top products by qty desc, capped', top_sort([['label'=>'A','value'=>3],['label'=>'B','value'=>9],['label'=>'C','value'=>5]],2),
   [['label'=>'B','value'=>9],['label'=>'C','value'=>5]]);

echo "\n--------------------------------------------------\n";
echo "dashboard_analytics: {$pass} passed, {$fail} failed\n";
exit($fail===0?0:1);
