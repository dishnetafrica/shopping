<?php
require dirname(__DIR__).'/app/Services/Bot/CatalogueMatcher.php';
use App\Services\Bot\CatalogueMatcher as M;
$pass=0;$fail=0;$fails=[];
function ck($l,$ok,$d=''){global $pass,$fail,$fails;if($ok){$pass++;echo "  PASS  $l".($d?"  ($d)":'')."\n";}else{$fail++;$fails[]=$l;echo "  FAIL  $l".($d?"  ($d)":'')."\n";}}

$cat=[
 ['id'=>1,'name'=>'India Gate Basmati Feast Rozzana 1KG','category'=>'Rice','price'=>13800,'stock'=>5],
 ['id'=>2,'name'=>'India Gate Basmati Rice 1KG','category'=>'Rice','price'=>14000,'stock'=>5],
 ['id'=>3,'name'=>'SWT Chenab Brand Basmati Rice 1KG','category'=>'Rice','price'=>12800,'stock'=>5],
 ['id'=>4,'name'=>'SWT Chenab Super Kernel Basmati Rice 2KG','category'=>'Rice','price'=>25500,'stock'=>5],
 ['id'=>5,'name'=>'SWT Chenab Super Kernel Basmati Rice 5KG','category'=>'Rice','price'=>62000,'stock'=>5],
 ['id'=>6,'name'=>'Cil Brown Rice 1KG','category'=>'Rice','price'=>10600,'stock'=>5],
 ['id'=>7,'name'=>'Organic Brown Rice 1KG','category'=>'Rice','price'=>8000,'stock'=>5],
 ['id'=>8,'name'=>'SWT Super Brown Rice 1KG','category'=>'Rice','price'=>12500,'stock'=>5],
 ['id'=>9,'name'=>'SWT Ravi Rice Extra Long Grain 1KG','category'=>'Rice','price'=>12500,'stock'=>5],
 ['id'=>10,'name'=>'SWT Ravi Rice Extra Long Grain 5KG','category'=>'Rice','price'=>60000,'stock'=>5],
 ['id'=>11,'name'=>'SWT MB Long Grain 5KG','category'=>'Rice','price'=>28000,'stock'=>5],
 ['id'=>20,'name'=>'Indian Mixture Snack 200g','category'=>'Snacks','price'=>3000,'stock'=>5],
 ['id'=>21,'name'=>'Super Baby Cereal 400g','category'=>'Baby','price'=>9000,'stock'=>5],
 ['id'=>22,'name'=>'LG Chewing Gum','category'=>'Confectionery','price'=>500,'stock'=>5],
 ['id'=>23,'name'=>'MB Razor Blades','category'=>'Toiletries','price'=>1500,'stock'=>5],
 ['id'=>24,'name'=>'Hing Powder 50g','category'=>'Spices','price'=>4000,'stock'=>5],
];
$m=new M();

echo "\n[category browse: rice-dominant query]\n";
$r=$m->categoryBrowse('Indian gate rice Chenab super brown rice SWT P LG Ravi rice MB',$cat,20);
ck('returns a browse', $r!==null);
ck('category is Rice', $r && $r['category']==='Rice', $r['category']??'-');
$bad=0; foreach(($r['products']??[]) as $p) if($p['category']!=='Rice')$bad++;
ck('ZERO non-rice products', $bad===0, "leaked=$bad");
ck('includes India Gate Basmati Feast (rice via category, no \"rice\" in name)',
   $r && in_array('India Gate Basmati Feast Rozzana 1KG', array_map(fn($p)=>$p['name'],$r['products'])));
ck('max 20', !$r || count($r['products'])<=20, (string)count($r['products']??[]));

echo "\n[guards: do NOT over-trigger]\n";
ck('"rice 2kg" -> null (short/precise)', $m->categoryBrowse('rice 2kg',$cat)===null);
ck('"milk" -> null (single token)', $m->categoryBrowse('milk',$cat)===null);
$cat2=[
 ['id'=>1,'name'=>'India Gate Rice 1KG','category'=>'Rice','price'=>14000,'stock'=>5],
 ['id'=>2,'name'=>'Ravi Rice 5KG','category'=>'Rice','price'=>60000,'stock'=>5],
 ['id'=>3,'name'=>'Kakira Sugar 1KG','category'=>'Sugar','price'=>4000,'stock'=>5],
 ['id'=>4,'name'=>'Brown Sugar 1KG','category'=>'Sugar','price'=>5000,'stock'=>5],
 ['id'=>5,'name'=>'Cooking Oil 1L','category'=>'Oil','price'=>9000,'stock'=>5],
 ['id'=>6,'name'=>'Geisha Soap','category'=>'Cleaning','price'=>2000,'stock'=>5],
 ['id'=>7,'name'=>'Sunlight Soap','category'=>'Cleaning','price'=>2500,'stock'=>5],
];
ck('"rice sugar oil soap" -> null (multi-category, normal multi-add)', $m->categoryBrowse('rice sugar oil soap',$cat2)===null);

echo "\nRESULT: PASS $pass  FAIL $fail\n";
if($fails){echo "Fails:\n";foreach($fails as $f)echo "  - $f\n";}
exit($fail?1:0);
