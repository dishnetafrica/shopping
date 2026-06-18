<?php
require __DIR__ . '/../app/Services/Winworld/IndentBuilder.php';
use App\Services\Winworld\IndentBuilder as B;
$pass=0;$fail=0;
function ok($c,$l){global $pass,$fail; if($c)$pass++; else{$fail++; echo "  FAIL $l\n";}}
function eqs($g,$w,$l){global $pass,$fail; if($g===$w)$pass++; else{$fail++; echo "  FAIL $l -> ".var_export($g,true)." != ".var_export($w,true)."\n";}}

// indent number format matches the OIF example (003-090626 on 09.06.2026)
eqs(B::nextIndentNo(3, new DateTimeImmutable('2026-06-09')), '003-090626', 'indent no = seq-DDMMYY');
eqs(B::nextIndentNo(1, new DateTimeImmutable('2026-01-05')), '001-050126', 'pads seq, DDMMYY');
eqs(B::nextIndentNo(0, new DateTimeImmutable('2026-06-09')), '001-090626', 'seq floors at 1');

// clone copies specs, resets identity/status, renumbers blends
$src = [
    'customer_id'=>5,'customer_name'=>'Delicious Bakery','item_id'=>9,'product_name'=>'LD Printed Bags 1KG milk bread',
    'order_qty_pcs'=>300,'mixing_qty'=>100,'priority'=>'Normal','sample_available'=>true,
    'needs_blending'=>true,'needs_extrusion'=>true,'needs_printing'=>true,'needs_cutting'=>true,
    'ext_width'=>'41"','ext_gauge'=>'120G','prn_no_colours'=>'0+4','cut_bag_size'=>'11"x18"x20.5"',
    'indent_no'=>'003-090626','status'=>'Completed','id'=>42,'order_kg'=>10.7,
];
$blends = [
    ['material_id'=>1,'material_name'=>'LDPE Resin','pct_a'=>70],
    ['material_id'=>4,'material_name'=>'White MB','pct_a'=>30],
];
$c = B::cloneData($src, $blends);
eqs($c['indent']['customer_name'], 'Delicious Bakery', 'clone keeps customer');
eqs($c['indent']['prn_no_colours'], '0+4', 'clone keeps printing spec');
eqs($c['indent']['cut_bag_size'], '11"x18"x20.5"', 'clone keeps cutting spec');
eqs($c['indent']['status'], 'Open', 'clone resets status to Open');
eqs($c['indent']['indent_no'], '', 'clone clears indent number');
eqs($c['indent']['date_of_indent'], null, 'clone clears date');
ok(!array_key_exists('id', $c['indent']), 'clone drops id');
ok(!array_key_exists('order_kg', $c['indent']), 'clone drops cached order_kg');
eqs(count($c['blends']), 2, 'clone copies 2 blend lines');
eqs($c['blends'][0]['line_no'], 1, 'blend line renumbered 1');
eqs($c['blends'][1]['line_no'], 2, 'blend line renumbered 2');
eqs($c['blends'][0]['material_name'], 'LDPE Resin', 'blend material carried');
eqs((float)$c['blends'][0]['pct_a'], 70.0, 'blend pct carried');

echo "ww_indent_builder: $pass passed, ".($fail?"FAIL $fail":"0 failed")."\n";
