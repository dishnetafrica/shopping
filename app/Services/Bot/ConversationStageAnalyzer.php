<?php

namespace App\Services\Bot;

/**
 * ConversationStageAnalyzer — recognises the shopping stages present in a message and, when a
 * single message spans several of them, returns just the EARLIEST stage so the bot can handle
 * one step at a time like a human shop attendant, rather than trying to resolve the whole
 * journey at once.
 *
 *   DISCOVERY       "need rice", "daily use", "not expensive", "family of 5"
 *   RECOMMENDATION  "which one is good?", "what do you recommend?", "are you sure?"
 *   SELECTION       "add it", "add 2", "first one"
 *   DELIVERY        "do you deliver?", "how much to Ntinda?", "send a location pin"
 *   CHECKOUT        "checkout", "place order"
 *
 * For routing, DISCOVERY and RECOMMENDATION are one class (both go to the recommendation
 * layer); SELECTION / DELIVERY / CHECKOUT are their own classes. "Multi-stage" means more than
 * one CLASS is present, so "add 2 / add 1" (all selection) is a single transaction, not a
 * multi-stage journey.
 *
 *   "Need rice / which is good? / add 2 / checkout"  ->  leadSegment = "Need rice / which is good?"
 *   ...the bot recommends rice and stops; the add/checkout arrive as the customer's next turns.
 *
 * Pure & static. This is decomposition of ONE message — it does not lock future turns.
 */
class ConversationStageAnalyzer
{
    public const DISCOVERY      = 'DISCOVERY';
    public const RECOMMENDATION = 'RECOMMENDATION';
    public const SELECTION      = 'SELECTION';
    public const DELIVERY       = 'DELIVERY';
    public const CHECKOUT       = 'CHECKOUT';

    private const CLASS_OF = [
        self::DISCOVERY      => 'advisory',
        self::RECOMMENDATION => 'advisory',
        self::SELECTION      => 'selection',
        self::DELIVERY       => 'delivery',
        self::CHECKOUT       => 'checkout',
    ];

    /** @return array<string,string[]> stage => regexes (matched on lowercased text) */
    private static function patterns(): array
    {
        return [
            self::CHECKOUT => [
                '/\bcheck\s?out\b/', '/\bplace (an |my )?order\b/',
                '/\bproceed to (pay|checkout|payment)\b/', '/\bcomplete (my )?order\b/',
            ],
            self::DELIVERY => [
                '/\bdeliver(y|ies|ing)?\b/', '/\bsend (me )?(a |my )?(location|pin)\b/',
                '/\b(location|gps) pin\b/', '/\bhow much to \w+/', '/\bdo you deliver\b/',
            ],
            self::SELECTION => [
                '/\badd (it|that|them|this|those|these|the )\b/', '/\badd \d+\b/',
                '/\b(first|second|third|fourth|last) one\b/', '/\bthe (first|second|third|last)\b/',
                '/\b(i\'?ll |i will )?take (it|that|the )\b/', '/\byes,? add\b/',
            ],
            self::RECOMMENDATION => [
                '/\bwhich (one|brand|rice|oil|product)?\s*(is|do|should)?\s*(good|best)\b/',
                '/\bwhich (do|should) (i|you)\b/', '/\brecommend\b/', '/\bsuggest\b/',
                '/\bmost (people|customers)\b/', '/\bare you sure\b/',
                '/\bwhat\'?s good\b/', '/\bgood one\b/', '/\bwhat do you (recommend|suggest)\b/',
            ],
            self::DISCOVERY => [
                '/\bneed\b/', '/\bwant\b/', '/\blooking for\b/', '/\bdo you have\b/', '/\bgot any\b/',
                '/\bnot (too )?(expensive|cheap|pricey|costly|basmati|brown)\b/',
                '/\bdaily use\b/', '/\bfamily of \d+\b/', '/\bfor (daily|home|cooking)\b/',
            ],
        ];
    }

    /** @return array<int,array{stage:string,pos:int,class:string}> sorted by position */
    public static function stages(string $text): array
    {
        $t = mb_strtolower($text);
        $found = [];
        foreach (self::patterns() as $stage => $pats) {
            $min = PHP_INT_MAX;
            foreach ($pats as $re) {
                if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE)) {
                    $min = min($min, (int) $m[0][1]);
                }
            }
            if ($min !== PHP_INT_MAX) {
                $found[] = ['stage' => $stage, 'pos' => $min, 'class' => self::CLASS_OF[$stage]];
            }
        }
        usort($found, fn ($a, $b) => $a['pos'] <=> $b['pos']);
        return $found;
    }

    /** The earliest stage label, or null if none recognised. */
    public static function leadStage(string $text): ?string
    {
        return self::stages($text)[0]['stage'] ?? null;
    }

    /** True when more than one stage CLASS appears in the message. */
    public static function isMultiStage(string $text): bool
    {
        $classes = [];
        foreach (self::stages($text) as $s) $classes[$s['class']] = true;
        return count($classes) > 1;
    }

    /**
     * The portion of the message belonging to the earliest stage class — everything up to the
     * first stage of a DIFFERENT class. Returns the whole text when not multi-stage.
     */
    public static function leadSegment(string $text): string
    {
        $stages = self::stages($text);
        if (! $stages) return $text;
        $leadClass = $stages[0]['class'];
        foreach ($stages as $s) {
            if ($s['class'] !== $leadClass) {
                $cut = rtrim(mb_substr($text, 0, $s['pos']));
                return $cut !== '' ? $cut : $text;
            }
        }
        return $text;
    }
}
