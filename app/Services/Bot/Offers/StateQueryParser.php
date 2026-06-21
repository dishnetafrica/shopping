<?php

namespace App\Services\Bot\Offers;

/**
 * Status Intelligence — customer state question parser. Pure logic, no framework deps.
 *
 * Detects "is it ready?" and "how much is left?" questions, which are answered from the latest
 * owner update events rather than the menu itself.
 *
 *   "Lunch ready?"      -> ready,     item lunch
 *   "Thali baki che?"   -> remaining, item thali
 *   "Kitla thali baki?" -> remaining, item thali
 *
 * Plain availability ("Fafda che?") is handled by ItemQueryParser; the service checks events first.
 *
 * @return array{kind:string,item:string}|null
 */
class StateQueryParser
{
    private const REMAIN = ['baki', 'baaki', 'left', 'remaining', 'bachi', 'bachya', 'bache'];
    private const READY  = ['ready', 'taiyar', 'tayar', 'taiyaar'];
    private const STOP   = ['che', 'chhe', 'available', 'is', 'there', 'do', 'you', 'have', 'how',
        'many', 'much', 'kitla', 'ketla', 'kitlu', 'ketlu', 'kitli', 'ketli', 'aaje', 'aaj', 'today',
        'still', 'now', 'su', 'shu', 'any', 'the'];

    public static function detect(string $text): ?array
    {
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($text))));
        if ($t === '') return null;

        $w = explode(' ', $t);
        if (count($w) > 5) return null;

        $isRemain = (bool) array_intersect($w, self::REMAIN);
        $isReady  = (bool) array_intersect($w, self::READY);
        if (! $isRemain && ! $isReady) return null;

        $strip = array_merge(self::REMAIN, self::READY, self::STOP);
        $item  = trim(implode(' ', array_filter(
            $w,
            fn ($x) => ! in_array($x, $strip, true) && ! ctype_digit($x)
        )));

        return ['kind' => $isRemain ? 'remaining' : 'ready', 'item' => $item];
    }
}
