<?php
// Mirrors ShoppingEngine::sizeBase + composeFromPacks math (pure logic).
function sizeBase(?string $t){
    if($t===null) return null;
    $s=strtolower(str_replace(' ','',$t));
    if(!preg_match('/(\d+(?:\.\d+)?)(kg|gms|gm|g|mg|ltr|l|ml|cl)/',$s,$m)) return null;
    $n=(float)$m[1];
    return match($m[2]){
        'kg'=>['mag'=>$n*1000,'fam'=>'g'],'g','gm','gms'=>['mag'=>$n,'fam'=>'g'],'mg'=>['mag'=>$n/1000,'fam'=>'g'],
        'l','ltr'=>['mag'=>$n*1000,'fam'=>'ml'],'cl'=>['mag'=>$n*10,'fam'=>'ml'],'ml'=>['mag'=>$n,'fam'=>'ml'],default=>null,
    };
}
// returns [packLabel, qty] or null
function compose(string $req, array $packs){ // packs: ['1kg'=>true,...]
    $want=sizeBase($req); if(!$want||$want['mag']<=0) return null;
    $best=null;$bestMag=0;$bestQty=0;
    foreach($packs as $label=>$_){
        $pm=sizeBase($label); if(!$pm||$pm['fam']!==$want['fam']||$pm['mag']<=0) continue;
        $r=$want['mag']/$pm['mag']; $q=(int)round($r);
        if($q>=2 && $q<=50 && abs($r-$q)<0.001 && $pm['mag']>$bestMag){$bestMag=$pm['mag'];$best=$label;$bestQty=$q;}
    }
    return $best?[$best,$bestQty]:null;
}
$pass=0;$fail=0; function chk($got,$exp,$l){global $pass,$fail; if($got===$exp)$pass++; else{$fail++;echo "  FAIL $l: got ".json_encode($got)." exp ".json_encode($exp)."\n";}}

$weight=['250g'=>1,'500g'=>1,'1kg'=>1];
chk(compose('2kg',$weight), ['1kg',2], '2kg -> 2x1kg');
chk(compose('3kg',$weight), ['1kg',3], '3kg -> 3x1kg');
chk(compose('5kg',$weight), ['1kg',5], '5kg -> 5x1kg');
chk(compose('750g',$weight), ['250g',3], '750g -> 3x250g (500 does not divide)');
chk(compose('1.5kg',$weight),['500g',3], '1.5kg -> 3x500g');
chk(compose('700g',$weight),  null,      '700g -> none divides -> null');
chk(compose('1kg',['250g'=>1,'500g'=>1]), ['500g',2], '1kg with no 1kg pack -> 2x500g');
$vol=['500ml'=>1,'1ltr'=>1];
chk(compose('2ltr',$vol), ['1ltr',2], '2ltr -> 2x1ltr');
chk(compose('2kg',$vol),  null,        'weight request vs volume packs -> null (family guard)');
echo "shop_pack_compose: $pass passed".($fail?", FAIL $fail":", 0 failed")."\n";
