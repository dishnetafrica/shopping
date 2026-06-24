<?php
/** qa/menus.php — restaurant menu grouping + menu-file send routing (mirrors AiBrain/storefront logic). */
// category_groups save mirror (PanelApiController)
function saveGroups($cg){$g=[];foreach((array)$cg as $name=>$cats){$name=trim((string)$name);$cats=array_values(array_filter(array_map('trim',(array)$cats)));if($name!==''&&$cats)$g[$name]=$cats;}return $g;}
// menu_files save mirror
function saveFiles($mf){$o=[];foreach((array)$mf as $m){$l=trim((string)($m['label']??''));$u=trim((string)($m['url']??''));if($l!==''&&$u!=='')$o[]=['label'=>$l,'url'=>$u];}return $o;}
// bot menu-send routing mirror
function pickMenu($files,$text){
  $t=mb_strtolower($text); $asks=false;
  foreach(['menu','carte','orodha'] as $w) if(str_contains($t,$w))$asks=true;
  if(!$asks)return ['ask'=>false,'send'=>[]];
  $food=str_contains($t,'food')||str_contains($t,'eat'); $drink=str_contains($t,'drink')||str_contains($t,'beverage')||str_contains($t,'bar');
  $want=[];
  foreach($files as $f){$l=mb_strtolower($f['label']);
    if($food&&!$drink&&str_contains($l,'food'))$want[]=$f;
    elseif($drink&&!$food&&(str_contains($l,'bever')||str_contains($l,'drink')))$want[]=$f;}
  if(!$want)$want=$files;
  return ['ask'=>true,'send'=>$want];
}
$files=[['label'=>'Food Menu','url'=>'a.jpg'],['label'=>'Beverages Menu','url'=>'b.pdf']];
$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== menus QA ===\n";
$g=saveGroups(['Food Menu'=>['Starters',' Mains ',''],'  '=>['x'],'Beverages'=>['Drinks']]);
check('groups keep named+trim cats', $g===['Food Menu'=>['Starters','Mains'],'Beverages'=>['Drinks']]);
check('drops nameless group', !isset($g['']));
$f=saveFiles([['label'=>'Food','url'=>'a.jpg'],['label'=>'','url'=>'x'],['label'=>'Bar','url'=>'']]);
check('files keep complete only', count($f)===1 && $f[0]['label']==='Food');
check('"send me the menu" → both', pickMenu($files,'send me the menu')['send']===$files);
check('"food menu" → food only', pickMenu($files,'can I see the food menu')['send']===[$files[0]]);
check('"drinks menu" → bev only', pickMenu($files,'drinks menu please')['send']===[$files[1]]);
check('"beverages" → bev only', pickMenu($files,'beverages menu')['send']===[$files[1]]);
check('greeting → not asked', pickMenu($files,'hello there')['ask']===false);
check('Swahili "orodha" → asked', pickMenu($files,'naomba orodha')['ask']===true);
check('pdf detection', (bool)preg_match('/\.pdf(\?|$)/i','b.pdf')===true);
echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n"; exit($fail===0?0:1);
