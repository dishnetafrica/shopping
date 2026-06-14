<?php

namespace App\Services\Bot;

/**
 * Category intelligence: maps a broad category term the customer types ("spirits",
 * "snacks", "soft drinks") to member keywords (include) and false-friends to exclude
 * ("surgical spirit", "soda ash"). Pure & static.
 *
 * match() only fires when the WHOLE message is a category term (after stripping filler),
 * so "surgical spirit" is left to normal product search and only "spirit(s)" is a category.
 */
class CategoryDictionary
{
    /** name => [terms, include, exclude] */
    public const CATEGORIES = [
        'Spirits' => [
            'terms'   => ['spirits', 'spirit', 'alcohol', 'liquor', 'liquors', 'hard drinks',
                          'hard drink', 'wines and spirits', 'wine and spirits'],
            'include' => ['waragi', 'konyagi', 'vodka', 'whisky', 'whiskey', 'gin', 'rum',
                          'brandy', 'tequila', 'liqueur', 'cognac', 'bond 7', 'chrome', 'v&a'],
            'exclude' => ['surgical', 'methylated', 'cleaning', 'roll on', 'rollon', 'sanitizer',
                          'sanitiser', 'antiseptic', 'perfume', 'spirit gum'],
        ],
        'Snacks' => [
            'terms'   => ['snacks', 'snack'],
            'include' => ['chips', 'crisps', 'wafers', 'namkeen', 'namkeens', 'biscuit', 'biscuits',
                          'cookies', 'popcorn', 'pop rings', 'nuts', 'peanuts', 'gutkha'],
            'exclude' => [],
        ],
        'Soft Drinks' => [
            'terms'   => ['soft drinks', 'soft drink', 'sodas', 'soda', 'minerals', 'cold drinks', 'beverages'],
            'include' => ['coke', 'coca cola', 'pepsi', 'fanta', 'sprite', 'mirinda', 'novida',
                          'mountain dew', 'juice', 'mineral water', 'soda'],
            'exclude' => ['soda ash', 'baking soda', 'caustic soda', 'washing soda'],
        ],
        'Cleaning' => [
            'terms'   => ['cleaning', 'cleaning products', 'detergent', 'detergents', 'household'],
            'include' => ['soap', 'detergent', 'bleach', 'jik', 'omo', 'nomi', 'sunlight', 'toilet cleaner',
                          'cleaner', 'dishwash', 'washing powder'],
            'exclude' => [],
        ],
    ];

    private const FILLER = ['show me', 'show', 'send me', 'send', 'give me', 'do you have', 'do you sell',
        'list', 'list of', 'see', 'view', 'the', 'your', 'all', 'me', 'please', 'pls', 'kindly', 'whole', 'full'];

    private static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9&\s]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** @return array{name:string,terms:array,include:array,exclude:array}|null */
    public static function match(string $text): ?array
    {
        $t = self::norm($text);
        if ($t === '') return null;

        // strip leading/trailing filler words so "show me the spirits please" -> "spirits"
        $words = explode(' ', $t);
        $words = array_values(array_filter($words, fn ($w) => ! in_array($w, self::FILLER, true)));
        $core = implode(' ', $words);
        if ($core === '') return null;

        foreach (self::CATEGORIES as $name => $def) {
            foreach ($def['terms'] as $term) {
                if ($core === $term) {
                    return ['name' => $name] + $def;
                }
            }
        }
        return null;
    }

    /** True if the message is a bare category term (used by the intent classifier). */
    public static function isCategory(string $text): bool
    {
        return self::match($text) !== null;
    }
}
