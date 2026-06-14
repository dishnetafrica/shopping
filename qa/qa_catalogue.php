<?php
// Realistic synthetic catalogue generator for CloudBSS scale tests.
// Deterministic (seeded) so runs are reproducible. Guarantees the staples +
// FMCG (Vimal, Coca Cola) needed by the functional categories are present.

function gen_catalogue(int $n, int $seed = 42): array
{
    mt_srand($seed);
    // [item, category, keywords]
    $items = [
        ['Rice','Rice','chokha chawal'],
        ['Sugar','Sugar','sakar khand'],
        ['Cooking Oil','Cooking Oil','tel oil'],
        ['Milk','Milk','doodh dudh'],
        ['Bread','Bakery','bread'],
        ['Flour','Flour','atta aata maida'],
        ['Soda','Drinks','soda coke drinks'],
        ['Biscuits','Snacks','biscuits'],
        ['Tea Leaves','Beverages','chai cha'],
        ['Salt','Cooking','namak'],
        ['Maize Flour','Flour','posho'],
        ['Washing Powder','Household','soap detergent'],
        ['Bar Soap','Household','sabun soap'],
        ['Mineral Water','Drinks','pani water'],
        ['Eggs','Dairy','anda'],
        ['Cooking Ghee','Cooking','ghee ghi'],
        ['Spaghetti','Pasta','noodles'],
        ['Beans','Cereals','maharagwe'],
        ['Lentils','Cereals','dal daal'],
        ['Tomato Paste','Cooking','tomato'],
        ['Juice','Drinks','juice'],
        ['Matchbox','Household','matches'],
        ['Candles','Household','candle'],
        ['Tissue','Household','tissue toilet'],
        ['Cooking Fat','Cooking','blueband margarine'],
    ];
    $brands = ['Kinyara','Pakistan','Local','Sunseed','Fortune','Jesa','Pearl','Superloaf','Britannia','Nile',
        'Movit','Mukwano','Bidco','Golden','Royal','Tilda','Daawat','Ndovu','Pembe','Hima','Riham','Rwenzori',
        'Blue','Star','Top','Family','Home','Premium','Classic','Fresh'];
    $sizes = ['250g','500g','1kg','2kg','5kg','10kg','250ml','500ml','1L','2L','5L','100g','12pcs','24pcs','1 dozen'];

    $cat = [];
    $id = 1;
    // guaranteed FMCG anchors (single SKU each so functional tests add cleanly)
    $cat[] = ['id'=>$id++,'name'=>'Vimal Pan Masala 100g','category'=>'Tobacco','keywords'=>'vimal panmasala','price'=>3500,'stock'=>500];
    $cat[] = ['id'=>$id++,'name'=>'Coca Cola 500ml','category'=>'Drinks','keywords'=>'coke cola soda','price'=>2000,'stock'=>800];

    while ($id <= $n) {
        $it = $items[mt_rand(0, count($items) - 1)];
        $brand = $brands[mt_rand(0, count($brands) - 1)];
        $size = $sizes[mt_rand(0, count($sizes) - 1)];
        $price = mt_rand(2, 80) * 500; // 1000..40000
        $cat[] = [
            'id' => $id++,
            'name' => "$brand {$it[0]} $size",
            'category' => $it[1],
            'keywords' => $it[2],
            'price' => $price,
            'stock' => mt_rand(0, 1) ? mt_rand(1, 200) : mt_rand(1, 200),
        ];
    }
    return $cat;
}
