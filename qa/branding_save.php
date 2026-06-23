<?php
/** qa/branding_save.php — mirrors PanelApiController::brandingSave normalisation. */

$hex = fn($v) => preg_match('/^#[0-9a-fA-F]{6}$/', trim((string)$v)) ? strtoupper(trim((string)$v)) : null;
$darken = function($h,$f=0.82){$r=hexdec(substr($h,1,2));$g=hexdec(substr($h,3,2));$b=hexdec(substr($h,5,2));return sprintf('#%02X%02X%02X',(int)round($r*$f),(int)round($g*$f),(int)round($b*$f));};
function normFaq(array $faq): array {
    return array_values(array_filter(array_map(fn($x)=>['q'=>trim((string)($x['q']??'')),'a'=>trim((string)($x['a']??''))],$faq),
        fn($x)=>$x['q']!==''||$x['a']!==''));
}
function normBrands(array $br, callable $hex): array {
    return array_values(array_filter(array_map(function($b) use($hex){
        return array_filter([
            'name'=>trim((string)($b['name']??'')),'tag'=>trim((string)($b['tag']??'')),
            'color'=>$hex($b['color']??'')?:null,
            'items'=>array_values(array_filter(array_map('trim',(array)($b['items']??[])))),
            'chips'=>array_values(array_filter(array_map('trim',(array)($b['chips']??[])))),
        ], fn($v)=>$v!==null&&$v!==''&&$v!==[]);
    },$br), fn($b)=>!empty($b['name'])));
}

$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== branding_save QA ===\n";

check('valid hex accepted + uppercased', $hex('#103a8c')==='#103A8C');
check('bad hex rejected', $hex('navy')===null && $hex('#123')===null && $hex('103A8C')===null);
check('darken 0.82 of #FFFFFF', $darken('#FFFFFF')==='#D1D1D1');
check('darken keeps it a valid hex', preg_match('/^#[0-9A-F]{6}$/',$darken('#103A8C'))===1);

$faq=normFaq([['q'=>' How? ','a'=>' Like this '],['q'=>'','a'=>''],['q'=>'Only q','a'=>'']]);
check('faq trims + drops fully-empty', count($faq)===2 && $faq[0]==['q'=>'How?','a'=>'Like this']);
check('faq keeps q-only row', $faq[1]['q']==='Only q');

$brands=normBrands([
  ['name'=>' EuroPearl ','tag'=>'Premium','color'=>'#103a8c','items'=>[' A ','','B'],'chips'=>['2-Ply',' ']],
  ['name'=>'','tag'=>'orphan'],                       // dropped (no name)
  ['name'=>'Orchid','color'=>'bad'],                  // color dropped, kept
], $hex);
check('brand without name dropped', count($brands)===2);
check('brand name trimmed', $brands[0]['name']==='EuroPearl');
check('brand color validated+upper', $brands[0]['color']==='#103A8C');
check('brand items trimmed + blanks removed', $brands[0]['items']===['A','B']);
check('brand chips blanks removed', $brands[0]['chips']===['2-Ply']);
check('invalid brand color dropped but brand kept', !isset($brands[1]['color']) && $brands[1]['name']==='Orchid');

echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
