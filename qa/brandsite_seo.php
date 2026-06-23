<?php
/** qa/brandsite_seo.php — validates the FAQPage + Organization JSON-LD the brand site emits. */

function faqLd(array $faq): array {
    return ['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>array_map(fn($f)=>[
        '@type'=>'Question','name'=>$f['q'],'acceptedAnswer'=>['@type'=>'Answer','text'=>$f['a']],
    ], $faq)];
}
function orgLd(array $c): array {
    $names = $c['brands'] ?? [];
    return array_filter([
        '@context'=>'https://schema.org','@type'=>'Organization',
        'name'=>$c['name'],'url'=>$c['url'],'logo'=>$c['logo']?:null,
        'telephone'=>$c['phone']?:null,'email'=>$c['email']?:null,
        'address'=>$c['address']?['@type'=>'PostalAddress','streetAddress'=>$c['address'],'addressCountry'=>'UG']:null,
        'sameAs'=>$c['website']?[$c['website']]:null,
        'brand'=>$names?array_map(fn($n)=>['@type'=>'Brand','name'=>$n],$names):null,
    ]);
}
function metaDesc(string $name, array $brands): string {
    return trim($name.' manufactures '.implode(', ',$brands).' — virgin-pulp toilet paper, napkins and copier paper. Wholesale trade pricing, UNBS & ISO 9001 certified. Order on WhatsApp.');
}

$pass=0;$fail=0;
function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}

echo "=== brandsite_seo QA ===\n";

$faq=[['q'=>'How do I order?','a'=>'Tap add, checkout on WhatsApp.'],['q'=>'Minimum?','a'=>'3 cartons.']];
$ld=faqLd($faq);
check('FAQPage type', $ld['@type']==='FAQPage');
check('mainEntity count matches', count($ld['mainEntity'])===2);
check('each entity is a Question with answer text',
    $ld['mainEntity'][0]['@type']==='Question' && $ld['mainEntity'][0]['acceptedAnswer']['text']==='Tap add, checkout on WhatsApp.');
check('FAQ JSON-LD encodes cleanly', json_encode($ld)!==false);

$full=orgLd(['name'=>'Krishna Wellness Ltd','url'=>'https://x/ep','logo'=>'https://x/l.png','phone'=>'+256 752 345 935','email'=>'a@b.com','address'=>'Namanve','website'=>'https://europearlafrica.com','brands'=>['EuroPearl','Angel Soft','Orchid']]);
check('Organization name set', $full['name']==='Krishna Wellness Ltd');
check('address shaped as PostalAddress', ($full['address']['@type']??'')==='PostalAddress' && $full['address']['addressCountry']==='UG');
check('website -> sameAs array', $full['sameAs']===['https://europearlafrica.com']);
check('brands -> Brand objects', count($full['brand'])===3 && $full['brand'][0]['@type']==='Brand');

$min=orgLd(['name'=>'Shop','url'=>'https://x/s','logo'=>'','phone'=>'','email'=>'','address'=>'','website'=>'','brands'=>[]]);
check('empty fields are dropped (no logo/email/address/sameAs/brand)',
    !isset($min['logo']) && !isset($min['email']) && !isset($min['address']) && !isset($min['sameAs']) && !isset($min['brand']));
check('Organization always keeps name+url', isset($min['name'],$min['url']));

$desc=metaDesc('Krishna Wellness Ltd',['EuroPearl','Angel Soft','Orchid']);
check('meta description names the brands', strpos($desc,'EuroPearl, Angel Soft, Orchid')!==false);
check('meta description mentions certifications', strpos($desc,'UNBS & ISO 9001')!==false);

echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
