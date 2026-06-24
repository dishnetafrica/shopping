<?php
/** qa/combos.php — mirrors App\Support\Combos::normalize (item parse, save derivation, filtering). */
function parseItems($raw){
  $items=[];
  foreach((array)$raw as $it){
    if(is_array($it)){ $name=trim((string)($it['name']??'')); $qty=max(1,(int)($it['qty']??1)); }
    else{ $line=trim((string)$it); if($line==='')continue;
      if(preg_match('/^(\d+)\s*[x×*]?\s*(.+)$/u',$line,$m)){$qty=max(1,(int)$m[1]);$name=trim($m[2]);}
      else{$qty=1;$name=$line;} }
    if($name!=='')$items[]=['name'=>$name,'qty'=>$qty];
  }
  return $items;
}
function normalize($raw){
  $out=[];
  foreach($raw as $c){
    $items=parseItems($c['items']??[]);
    $price=(float)($c['price']??0);
    $was=(isset($c['was'])&&$c['was']!==''&&$c['was']!==null)?(float)$c['was']:null;
    $save=($was!==null&&$was>$price)?$was-$price:null;
    $row=['name'=>trim((string)($c['name']??'')),'who'=>trim((string)($c['who']??'')),
      'items'=>$items,'price'=>$price,'was'=>$was,'save'=>$save,'active'=>!isset($c['active'])||$c['active']];
    if($row['name']!==''&&$row['price']>0&&!empty($row['items']))$out[]=$row;
  }
  return $out;
}
$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== combos QA ===\n";
$n=normalize([['name'=>'Shop Starter','who'=>'shops','items'=>['2 x EuroPearl Toilet Paper','1 Napkins','3× POS Rolls'],'price'=>249000,'was'=>285000]]);
check('parses "2 x name"',  $n[0]['items'][0]===['name'=>'EuroPearl Toilet Paper','qty'=>2]);
check('parses bare "1 Napkins"', $n[0]['items'][1]===['name'=>'Napkins','qty'=>1]);
check('parses "3× name"', $n[0]['items'][2]===['name'=>'POS Rolls','qty'=>3]);
check('save = was-price', $n[0]['save']===36000.0);
check('active defaults true', $n[0]['active']===true);
$n2=normalize([['name'=>'NoPrice','items'=>['1 x A'],'price'=>0]]);
check('drops combo with no price', count($n2)===0);
$n3=normalize([['name'=>'','items'=>['1 x A'],'price'=>100]]);
check('drops nameless combo', count($n3)===0);
$n4=normalize([['name'=>'Empty','items'=>[],'price'=>100]]);
check('drops combo with no items', count($n4)===0);
$n5=normalize([['name'=>'NoWas','items'=>['2 x A'],'price'=>5000]]);
check('no was → save null', $n5[0]['save']===null);
$n6=normalize([['name'=>'Off','items'=>['1 x A'],'price'=>100,'active'=>false]]);
check('inactive kept in normalize (filtered at display)', $n6[0]['active']===false);
echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
