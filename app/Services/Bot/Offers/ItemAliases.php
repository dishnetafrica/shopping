<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — item alias dictionary. Pure logic, no framework deps.
 *
 * Folds the many ways customers name a dish into a small set of CONCEPT tokens, so a query
 * item and an offer item can be compared regardless of language / spelling:
 *   "Roti" / "Rotli" / "Phulka"      -> chapati
 *   "Chawal" / "Bhaat"               -> rice
 *   "Buttermilk" / "Chhash" / "Taak" -> chaas
 *   "Dal Chawal"                     -> {dal, rice}  (matches an offer's "Dal Rice")
 *
 * concepts("5 Chapati") -> ['chapati']  (counts + connectors dropped)
 */
class ItemAliases
{
    /** variant token => canonical concept token */
    private const MAP = [
        // breads
        'roti' => 'chapati', 'rotli' => 'chapati', 'rotali' => 'chapati', 'phulka' => 'chapati',
        'chapatti' => 'chapati', 'chappati' => 'chapati', 'chapathi' => 'chapati', 'chapati' => 'chapati',
        'bhakri' => 'bhakri', 'thepla' => 'thepla', 'puri' => 'puri', 'poori' => 'puri', 'naan' => 'naan', 'bhatura' => 'bhatura',
        // rice
        'rice' => 'rice', 'chawal' => 'rice', 'chokha' => 'rice', 'bhaat' => 'rice', 'bhat' => 'rice', 'pulav' => 'pulav', 'pulao' => 'pulav', 'biryani' => 'biryani',
        // chaas / buttermilk
        'chaas' => 'chaas', 'chhaas' => 'chaas', 'chhash' => 'chaas', 'chhachh' => 'chaas', 'chaash' => 'chaas',
        'chaach' => 'chaas', 'taak' => 'chaas', 'mattha' => 'chaas', 'buttermilk' => 'chaas', 'chhachhh' => 'chaas',
        // dal / kadhi
        'dal' => 'dal', 'daal' => 'dal', 'kadhi' => 'kadhi', 'kadi' => 'kadhi',
        // sabji / shak
        'sabji' => 'sabji', 'sabzi' => 'sabji', 'subzi' => 'sabji', 'shak' => 'sabji', 'shaak' => 'sabji', 'nushak' => 'sabji',
        // tomato (for negatives like "tameta sev")
        'tameta' => 'tameta', 'tamatar' => 'tameta', 'tomato' => 'tameta', 'tamato' => 'tameta',
        // papad / salad
        'papad' => 'papad', 'papar' => 'papad', 'papadum' => 'papad', 'pappad' => 'papad',
        'salad' => 'salad', 'kachumber' => 'salad', 'kachumbar' => 'salad', 'kosambri' => 'salad',
        // namkeen / sweets / common thali sides
        'sev' => 'sev', 'ganthia' => 'ganthia', 'gathiya' => 'ganthia', 'fafda' => 'fafda', 'jalebi' => 'jalebi',
        'mag' => 'mag', 'mug' => 'mag', 'moong' => 'mag', 'dhokli' => 'dhokli', 'dhokla' => 'dhokla',
        'khaman' => 'khaman', 'handvo' => 'handvo', 'muthia' => 'muthia', 'undhiyu' => 'undhiyu', 'undhiyo' => 'undhiyu',
        'khichdi' => 'khichdi', 'khichdi' => 'khichdi', 'raita' => 'raita', 'sweet' => 'sweet', 'mishtan' => 'sweet', 'gulab' => 'gulab',
        'chana' => 'chana', 'chole' => 'chana', 'ringan' => 'ringan', 'bateta' => 'bateta', 'aloo' => 'bateta', 'batata' => 'bateta',
    ];

    /** connectors / possessives / filler dropped before mapping */
    private const DROP = ['nu', 'na', 'ni', 'no', 'ane', 'and', 'with', 'ka', 'ki', 'ke', 'of', 'the', 'a', 'an'];

    /** Concept tokens for a phrase (deduped, order-independent). */
    public static function concepts(string $phrase): array
    {
        $toks = preg_split('/[^a-z0-9]+/', mb_strtolower(trim($phrase))) ?: [];
        $out = [];
        foreach ($toks as $t) {
            if ($t === '' || ctype_digit($t)) continue;        // drop counts
            if (in_array($t, self::DROP, true)) continue;       // drop connectors
            $out[self::MAP[$t] ?? $t] = true;
        }
        return array_keys($out);
    }

    /** Set of every recognised food concept (keys + canonical values of MAP). */
    private static function foodSet(): array
    {
        static $set = null;
        if ($set === null) {
            $set = array_fill_keys(array_merge(array_keys(self::MAP), array_values(self::MAP)), true);
        }
        return $set;
    }

    /** True if the phrase names at least one recognised food (so a greeting like "kem che" is NOT). */
    public static function isKnownFood(string $phrase): bool
    {
        $food = self::foodSet();
        foreach (self::concepts($phrase) as $c) {
            if (isset($food[$c])) return true;
        }
        return false;
    }
}
