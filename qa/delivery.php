<?php
// Pure-logic verification for Delivery V2 (D1+D2). Real output.

// Minimal stub so DeliveryStatus can load without the Eloquent base class.
namespace App\Models { class Delivery { const ASSIGNED='assigned'; const PICKED='picked'; const OUT='out'; const DELIVERED='delivered'; const FAILED='failed'; } }

namespace {
require dirname(__DIR__).'/app/Services/Delivery/ZoneResolver.php';
require dirname(__DIR__).'/app/Services/Delivery/DeliveryStatus.php';
require dirname(__DIR__).'/app/Services/Delivery/RiderAssigner.php';
use App\Services\Delivery\ZoneResolver as Z;
use App\Services\Delivery\DeliveryStatus as S;
use App\Services\Delivery\RiderAssigner as R;

$pass=0;$fail=0;$fails=[];
function ck($l,$ok){global $pass,$fail,$fails; if($ok){$pass++;echo "  PASS  $l\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l\n";}}

$zones = [
  ['id'=>1,'name'=>'Zone A','match_keywords'=>['kisaasi','kyanja'],'center_lat'=>0.3700,'center_lng'=>32.6000,'radius_m'=>3000,'flat_fee'=>3000,'per_km_fee'=>null,'min_fee'=>2000,'free_over'=>50000,'eta_minutes'=>35],
  ['id'=>2,'name'=>'Zone B','match_keywords'=>['ntinda','naalya'],'center_lat'=>0.3600,'center_lng'=>32.6200,'radius_m'=>2500,'flat_fee'=>5000,'per_km_fee'=>500,'min_fee'=>3000,'free_over'=>null,'eta_minutes'=>50],
];

echo "\n[D1] zone matching\n";
ck('keyword "deliver to kisaasi please" -> Zone A', (Z::matchZone('deliver to kisaasi please', null, null, $zones)['name'] ?? '')==='Zone A');
ck('keyword "Ntinda" (case) -> Zone B', (Z::matchZone('Ntinda', null, null, $zones)['name'] ?? '')==='Zone B');
ck('unknown area -> no zone (fallback)', Z::matchZone('somewhere far', null, null, $zones)===null);
ck('pin inside Zone A radius -> Zone A', (Z::matchZone('', 0.3705, 32.6005, $zones)['name'] ?? '')==='Zone A');
ck('pin far outside any radius -> null', Z::matchZone('', 1.0, 33.0, $zones)===null);

echo "\n[D1] fee calculation\n";
ck('flat fee (Zone A, small order)', Z::computeFee($zones[0], 10000, null, [])===3000);
ck('min fee floor', Z::computeFee(['flat_fee'=>1000,'min_fee'=>2500], 5000, null, [])===2500);
ck('free over threshold -> 0', Z::computeFee($zones[0], 50000, null, [])===0);
ck('per-km added (Zone B, 4km)', Z::computeFee($zones[1], 10000, 4.0, [])=== (5000 + 2000)); // 5000 + 4*500
ck('fallback distance rule when no zone', Z::computeFee(null, 10000, 3.0, ['base'=>2000,'per_km'=>1000,'min'=>2500,'free_over'=>0])=== (2000+3000));
ck('fallback free_over', Z::computeFee(null, 60000, 3.0, ['base'=>2000,'per_km'=>1000,'min'=>2500,'free_over'=>50000])===0);

echo "\n[D1] ETA\n";
ck('eta = now + zone minutes', Z::etaSeconds($zones[0], 1000)===1000+35*60);
ck('eta default 45 when no zone', Z::etaSeconds(null, 1000)===1000+45*60);

echo "\n[D2] status lifecycle\n";
ck('assigned -> out allowed', S::canTransition('assigned','out'));
ck('assigned -> picked allowed', S::canTransition('assigned','picked'));
ck('out -> delivered allowed', S::canTransition('out','delivered'));
ck('delivered -> anything blocked', !S::canTransition('delivered','out'));
ck('out -> assigned blocked (no going back)', !S::canTransition('out','assigned'));
ck('any -> failed allowed (from out)', S::canTransition('out','failed'));
ck('order status: out -> "Out for delivery"', S::orderStatusFor('out')==='Out for delivery');
ck('order status: delivered -> "Delivered"', S::orderStatusFor('delivered')==='Delivered');
ck('order status: picked -> null (unchanged)', S::orderStatusFor('picked')===null);

echo "\n[D2] least-loaded rider suggestion\n";
ck('picks the lightest rider', R::suggest([5=>3, 6=>1, 7=>2])===6);
ck('zone default chosen when not overloaded', R::suggest([5=>2, 6=>2, 9=>2], 9)===9);
ck('zone default skipped when overloaded', R::suggest([5=>0, 9=>5], 9)===5);
ck('no riders -> null', R::suggest([])===null);

echo "\n[D2] assign/advance against real SQLite (unique order_id)\n";
$db=new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE deliveries (id INTEGER PRIMARY KEY, order_id INT UNIQUE, rider_id INT, status TEXT, fee INT, cod_amount INT)");
$assign=function($orderId,$riderId,$fee,$cod)use($db){
  // firstOrNew(order_id) upsert
  $row=$db->query("SELECT id FROM deliveries WHERE order_id=$orderId")->fetch(PDO::FETCH_ASSOC);
  if($row){ $db->prepare("UPDATE deliveries SET rider_id=?,status='assigned',fee=?,cod_amount=? WHERE order_id=?")->execute([$riderId,$fee,$cod,$orderId]); }
  else { $db->prepare("INSERT INTO deliveries (order_id,rider_id,status,fee,cod_amount) VALUES (?,?,'assigned',?,?)")->execute([$orderId,$riderId,$fee,$cod]); }
};
$advance=function($orderId,$to)use($db){
  $cur=$db->query("SELECT status FROM deliveries WHERE order_id=$orderId")->fetch(PDO::FETCH_ASSOC)['status']??null;
  if($cur===null) return ['ok'=>false];
  if(!S::canTransition($cur,$to)) return ['ok'=>false,'error'=>'bad'];
  $db->prepare("UPDATE deliveries SET status=? WHERE order_id=?")->execute([$to,$orderId]);
  return ['ok'=>true];
};
$assign(1001, 6, 3000, 14700);
ck('assign creates one delivery', (int)$db->query("SELECT count(*) c FROM deliveries WHERE order_id=1001")->fetch()['c']===1);
$assign(1001, 7, 3000, 14700);  // reassign to another rider
ck('reassign updates SAME row (still one delivery, new rider)', (int)$db->query("SELECT count(*) c FROM deliveries WHERE order_id=1001")->fetch()['c']===1 && (int)$db->query("SELECT rider_id FROM deliveries WHERE order_id=1001")->fetch()['rider_id']===7);
ck('advance assigned->out ok', $advance(1001,'out')['ok']===true);
ck('advance out->delivered ok', $advance(1001,'delivered')['ok']===true);
ck('advance delivered->out rejected', $advance(1001,'out')['ok']===false);

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
}
