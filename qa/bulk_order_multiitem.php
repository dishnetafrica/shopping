<?php
// Defect-fix regression for BulkOrderParser multi-product loss.
// Covers the 4 reported cases + 22 real messages drawn from pals_chats.csv.
require __DIR__ . '/../app/Services/Bot/BulkOrderParser.php';
use App\Services\Bot\BulkOrderParser as B;

$pass = 0; $fail = 0;
function bulk($t){ return B::looksLikeBulkOrder($t); }
function lines($t){ return B::parseAll($t); }
function has($t,$sub,$qty=null){
    foreach (B::parseAll($t) as $l) {
        if (str_contains($l['query'], $sub) && ($qty===null || $l['qty']===$qty)) return true;
    }
    return false;
}
function check($label,$cond){ global $pass,$fail; if($cond){$pass++;} else {$fail++; echo "  FAIL  $label\n";} }

/** Positive: expect bulk + exact count + each [substr,qty] present (qty null = any). */
function positive($msg,$count,$items){
    check("[bulk] $msg", bulk($msg));
    $got = count(lines($msg));
    check("[count=$count got=$got] $msg", $got === $count);
    foreach ($items as [$sub,$qty]) check("   has '$sub' x".($qty??'*'), has($msg,$sub,$qty));
}
/** Negative: must NOT be treated as a multi-item order. */
function negative($msg){ check("[NOT bulk] $msg", ! bulk($msg)); }

echo "=== A. The 4 reported defect cases ===\n";
positive('Paneer and Khakhra', 2, [['paneer',1],['khakhra',1]]);
positive('Need 1kg Paneer and 2kg Mavo', 2, [['paneer',1],['mavo',2]]);
positive('Paneer, Khakhra, Gathiya', 3, [['paneer',1],['khakhra',1],['gathiya',1]]);
positive('Paneer 1kg Khakhra 2 packets Mavo 500gm', 3, [['paneer',1],['khakhra',2],['mavo',500]]);

echo "=== B. Real multi-item orders from pals_chats.csv ===\n";
positive('2pkt sev 2pkt chakri', 2, [['sev',2],['chakri',2]]);
positive('Dry painepal 8 packets and 500 gm peanuts', 2, [['painepal',8],['peanuts',500]]);
positive('15kg mavo 20 kg panir', 2, [['mavo',15],['panir',20]]);
positive('20kg mavo 15kg panir', 2, [['mavo',20],['panir',15]]);
positive('i need 1kg lato ghee 1 pkt pani puri', 2, [['lato ghee',1],['pani puri',1]]);
positive('Star gathiya 250 gm Papadi gathiya 250gm Sakkrpara 500 gm banana crisps 500gm', 4,
    [['star gathiya',250],['papadi gathiya',250],['sakkrpara',500],['banana crisps',500]]);
positive('3 kg kaju 1 kg crrinbary 1 prunes', 3, [['kaju',3],['crrinbary',1],['prunes',1]]);
positive('Thabdi fresh 500gm Ghav no lot 5kg Bhakhariot 1kg Bukoto towers', 3,
    [['thabdi fresh',500],['ghav no lot',5],['bhakhariot',1]]);
positive('2 pkt sev 1pkt chakri', 2, [['sev',2],['chakri',1]]);
positive('Banana crisp 1pkt Bhavanagari 1pkt Puri 1pkt Jini sev 1pkt', 4,
    [['banana crisp',1],['bhavanagari',1],['puri',1],['jini sev',1]]);
positive('20kg panir 5 kg cheese', 2, [['panir',20],['cheese',5]]);
positive('250gm fafda 100gm vanela 100gm jalebi', 3, [['fafda',250],['vanela',100],['jalebi',100]]);
positive('250 gm gathiya 250 gm fafda 150gm Jalebi 2 Papdi 1 Bhavnagari 1 star gathiya', 6,
    [['gathiya',250],['fafda',250],['jalebi',150],['papdi',2],['bhavnagari',1],['star gathiya',1]]);
positive('Please send 200 grams vanena gathiya, 200 grams sev khamani and 300 grams mava penda', 3,
    [['vanena gathiya',200],['sev khamani',200],['mava penda',300]]);
positive('Potato crips 2 pkt Farali chevdo chili.. 1 pkt', 2, [['potato crips',2],['farali chevdo',1]]);
positive('Helloo can i get 100g fafda and 100g jalebi tomorrow morning pls?', 2, [['fafda',100],['jalebi',100]]);
positive('1tiffin 2protha extra and 1 only rice extra', 3, [['tiffin',1],['protha',2],['rice',1]]);

echo "=== C. Real NON-orders (must not false-fire) ===\n";
negative('Ok,thank you');
negative('No, thank you....I shall buy next time pls I am sorry pls');
negative('Please call me once you reach, I have to buy lots of items');
negative('You have sunflower seeds and pumpkin seeds?');
negative('Do you have almonds and walnuts');
negative('do you have rice?');

echo "\n" . ($fail===0 ? "ALL GREEN: $pass passed, 0 failed.\n" : "$pass passed, $fail FAILED.\n");
if ($fail) exit(1);
