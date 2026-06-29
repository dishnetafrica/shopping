<?php
/** Standalone logic test for the Business Opportunity brain. Run: php tests/Opportunity/logic_test.php */
$root = dirname(__DIR__, 2);
require "$root/app/Services/Bot/Opportunity/OpportunityTypes.php";
require "$root/app/Services/Bot/Opportunity/SlotExtractor.php";
require "$root/app/Services/Bot/Opportunity/OpportunityScorer.php";
use App\Services\Bot\Opportunity\OpportunityTypes as T;
use App\Services\Bot\Opportunity\SlotExtractor;
use App\Services\Bot\Opportunity\OpportunityScorer;
$pass=0;$fail=0; function check($n,$c){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS  ":"  FAIL  ").$n."\n";}
function detect($e,$t){$lc=' '.mb_strtolower(preg_replace('/\s+/',' ',trim($t))).' ';$b=null;$l=0;foreach($e as $ty)foreach((T::TRIGGERS[$ty]??[])as $p)if($p!==''&&str_contains($lc,$p)&&strlen($p)>$l){$b=$ty;$l=strlen($p);}return $b;}
function filled($p,$s){foreach(($s['any_of']??[$s['slot']])as $x)if(!empty($p[$x]))return true;return false;}
function nextSlot($p){foreach(T::SCRIPT as $s){if(filled($p,$s))continue;return $s['slot'];}return null;}
function missingCore($p){$o=[];foreach(T::SCRIPT as $s){if(empty($s['core']))continue;if(filled($p,$s))continue;$o[]=$s['slot'];}return $o;}
$all=array_keys(T::LABELS);
check('detect distributor price',detect($all,'I want distributor price')==='distributor');
check('detect wholesale',detect($all,'wholesale rate?')==='wholesale');
check('detect export',detect($all,'we want to export to kenya')==='export');
check('plain order -> null',detect($all,'i want toilet paper')===null);
$ex=new SlotExtractor();$p=[];
foreach([['I already take another brand',null],['they give me 8500',null],['I have a shop',null],['Ttula Road','area'],['around 300 cartons monthly',null],['Usman Daud','business_name']] as $turn){$p=array_merge($p,$ex->extract($turn[0],$p,$turn[1]));}
check('reseller',!empty($p['existing_reseller']));
check('price 8500',($p['buying_price']??0)===8500);
check('area',($p['area']??'')==='Ttula Road');
check('volume 300',($p['monthly_volume']??0)===300);
check('freq monthly',($p['order_frequency']??'')==='monthly');
$sc=new OpportunityScorer();$r=$sc->score('distributor',$p);
check('score Hot',$r['score']>=70);
check('size strategic',$sc->sizeBand($p)==='strategic');
check('first q business_name',nextSlot([])==='business_name');
check('price after volume in script',array_search('buying_price',array_column(T::SCRIPT,'slot'))>array_search('monthly_volume',array_column(T::SCRIPT,'slot')));
check('core gate ok',missingCore(['business_name'=>'U','area'=>'T','monthly_volume'=>300,'buying_price'=>8500])===[]);
echo "\n==== $pass passed, $fail failed ====\n"; exit($fail?1:0);
