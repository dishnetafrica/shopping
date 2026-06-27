<?php
namespace App\Services\Bot\Merchant;

/**
 * Guesses a category for a product the owner is creating over WhatsApp, so the
 * owner never has to think about taxonomy. Strategy (all pure, unit-testable):
 *
 *   1. Find a "hint" word in the product name via a small East-African grocery
 *      lexicon (e.g. "crisps" -> Snacks, "jalebi" -> Sweets).
 *   2. If the tenant ALREADY has a category whose name matches that hint, reuse
 *      the tenant's own spelling (keeps the catalogue consistent — no new
 *      "Snacks" alongside an existing "Snacks & Crisps").
 *   3. Otherwise return the hint's default human label.
 *   4. If nothing matches, return null — the caller falls back to the tenant's
 *      most common category (or a generic default).
 *
 * The owner always sees the guess in the confirmation summary and can reply NO
 * if it's wrong, so a miss is never silently committed.
 */
class CategoryInferer
{
    /**
     * keyword (matched as a whole word, case-insensitive) => default category label.
     * Ordered roughly specific-first; first hit wins.
     */
    private const LEXICON = [
        // sweets / mithai
        'jalebi' => 'Sweets', 'mithai' => 'Sweets', 'barfi' => 'Sweets', 'burfi' => 'Sweets',
        'ladoo' => 'Sweets', 'laddu' => 'Sweets', 'halwa' => 'Sweets', 'gulab' => 'Sweets',
        'katri' => 'Sweets', 'katli' => 'Sweets', 'peda' => 'Sweets', 'rasgulla' => 'Sweets',
        // namkeen / snacks
        'fafda' => 'Snacks & Crisps', 'fafada' => 'Snacks & Crisps', 'gathiya' => 'Snacks & Crisps',
        'khaman' => 'Snacks & Crisps', 'dhokla' => 'Snacks & Crisps', 'khakhra' => 'Snacks & Crisps',
        'sev' => 'Snacks & Crisps', 'chevda' => 'Snacks & Crisps', 'chivda' => 'Snacks & Crisps',
        'mixture' => 'Snacks & Crisps', 'namkeen' => 'Snacks & Crisps', 'wafer' => 'Snacks & Crisps',
        'wafers' => 'Snacks & Crisps', 'crisps' => 'Snacks & Crisps', 'crisp' => 'Snacks & Crisps',
        'chips' => 'Snacks & Crisps', 'puri' => 'Snacks & Crisps', 'papad' => 'Snacks & Crisps',
        'patra' => 'Snacks & Crisps', 'samosa' => 'Snacks & Crisps', 'mathri' => 'Snacks & Crisps',
        'tam' => 'Snacks & Crisps',
        // biscuits
        'biscuit' => 'Biscuits & Cookies', 'biscuits' => 'Biscuits & Cookies', 'cookie' => 'Biscuits & Cookies',
        'cookies' => 'Biscuits & Cookies', 'rusk' => 'Biscuits & Cookies',
        // chocolate / candy
        'chocolate' => 'Chocolates', 'choc' => 'Chocolates', 'candy' => 'Chewing Gum & Candy',
        'gum' => 'Chewing Gum & Candy', 'toffee' => 'Chewing Gum & Candy', 'lollipop' => 'Chewing Gum & Candy',
        // drinks
        'juice' => 'Juices & Drinks', 'drink' => 'Juices & Drinks', 'soda' => 'Soft Drinks',
        'cola' => 'Soft Drinks', 'water' => 'Water', 'energy' => 'Juices & Drinks',
        // dairy
        'milk' => 'Milk & Dairy', 'yogurt' => 'Yogurt', 'yoghurt' => 'Yogurt', 'ghee' => 'Cooking & Baking',
        'butter' => 'Milk & Dairy', 'cheese' => 'Milk & Dairy', 'paneer' => 'Milk & Dairy',
        'cream' => 'Milk & Dairy',
        // staples
        'rice' => 'Rice', 'basmati' => 'Rice', 'atta' => 'Flours & Grains', 'flour' => 'Flours & Grains',
        'maida' => 'Flours & Grains', 'besan' => 'Flours & Grains', 'dal' => 'Pulses & Dals',
        'daal' => 'Pulses & Dals', 'lentil' => 'Pulses & Dals', 'pulse' => 'Pulses & Dals',
        'pasta' => 'Pasta & Noodles', 'noodle' => 'Pasta & Noodles', 'noodles' => 'Pasta & Noodles',
        'oil' => 'Cooking & Baking', 'sugar' => 'Cooking & Baking', 'salt' => 'Spices & Masala',
        // spices
        'masala' => 'Spices & Masala', 'spice' => 'Spices & Masala', 'turmeric' => 'Spices & Masala',
        'chilli' => 'Spices & Masala', 'chili' => 'Spices & Masala', 'cumin' => 'Spices & Masala',
        'coriander' => 'Spices & Masala', 'pepper' => 'Spices & Masala', 'hing' => 'Spices & Masala',
        'asafoetida' => 'Spices & Masala', 'seeds' => 'Seeds & Mukhwas', 'mukhwas' => 'Seeds & Mukhwas',
        // home / care
        'soap' => 'Bath Soap', 'shampoo' => 'Hair Care', 'detergent' => 'Household Cleaning',
        'tissue' => 'Household', 'cleaner' => 'Household Cleaning', 'sauce' => 'Sauces & Condiments',
        'ketchup' => 'Sauces & Condiments', 'pickle' => 'Sauces & Condiments', 'honey' => 'Honey',
        'tea' => 'Tea & Coffee', 'coffee' => 'Tea & Coffee',
    ];

    /**
     * @param string   $name            the new product name as typed
     * @param string[] $knownCategories the tenant's existing distinct category names
     */
    public static function infer(string $name, array $knownCategories = []): ?string
    {
        $hint = self::hint($name);
        if ($hint === null) return null;

        // Prefer the tenant's own category spelling when it clearly refers to the same thing.
        if ($match = self::matchKnown($hint, $knownCategories)) return $match;

        return $hint;
    }

    /** The default label for the first lexicon keyword present in the name, else null. */
    private static function hint(string $name): ?string
    {
        $tokens = self::tokens($name);
        foreach ($tokens as $tok) {
            if (isset(self::LEXICON[$tok])) return self::LEXICON[$tok];
        }
        return null;
    }

    /**
     * Does the tenant already have a category that means the same as $hint?
     * Matches when either name contains the significant words of the other
     * (so "Snacks" hint reuses an existing "Snacks & Crisps").
     */
    private static function matchKnown(string $hint, array $knownCategories): ?string
    {
        $hintWords = self::sigWords($hint);
        if (! $hintWords) return null;

        foreach ($knownCategories as $cat) {
            $cat = trim((string) $cat);
            if ($cat === '') continue;
            if (strcasecmp($cat, $hint) === 0) return $cat;

            $catWords = self::sigWords($cat);
            if (! $catWords) continue;
            $shared = array_intersect($hintWords, $catWords);
            if ($shared) return $cat; // share at least one significant word
        }
        return null;
    }

    /** lowercase alphabetic tokens */
    private static function tokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z\s]/', ' ', $s) ?? '';
        return array_values(array_filter(preg_split('/\s+/', trim($s)) ?: []));
    }

    /** significant words of a category label (drop joiners like &, and, the) */
    private static function sigWords(string $s): array
    {
        $stop = ['and', 'the', 'of', 'for', 'amp'];
        return array_values(array_filter(self::tokens($s), fn ($w) => strlen($w) > 2 && ! in_array($w, $stop, true)));
    }
}
