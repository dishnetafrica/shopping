<?php
namespace App\Services\Knowledge\Classifier;

use App\Services\Knowledge\Contracts\Classifier;
use App\Services\Knowledge\Intent;
use App\Services\Knowledge\OwnerProfileResolver;

/**
 * Phase-1 deterministic intent classifier (no AI, no DB). Single-intent: returns the dominant
 * intent so the engine can route to one capability; that capability's extractor then parses the
 * full message. The AI fallback (Phase 3) implements the same Classifier contract — engine
 * code does not change. Keyword sets include Gujlish synonyms.
 */
class DeterministicClassifier implements Classifier
{
    private const MENU      = ['breakfast', 'lunch', 'dinner', 'nasto', 'jaman', 'farsan', 'menu', 'thali', 'snacks'];
    private const SPECIAL   = ['special', 'todays special', 'today special'];
    private const AVAIL     = ['sold out', 'soldout', 'finished', 'khatam', 'out of stock', 'not available', 'unavailable', 'no more'];
    private const SCHEDULE  = ['closed', 'open ', 'close ', 'closing', 'opening', 'holiday', 'hours', 'shut'];
    private const POLICY    = ['delivery', 'cash only', 'card only', 'min order', 'minimum order', 'free delivery', 'payment', 'mobile money', 'momo'];
    private const FACILITY  = ['parking', 'wifi', 'wi-fi', 'air condition', ' ac ', 'seating', 'washroom', 'toilet'];

    public function classify(string $text, array $profile = []): string
    {
        $t = ' ' . mb_strtolower(trim($text)) . ' ';

        if (OwnerProfileResolver::resolve($text, $profile) === Intent::REPEAT_PREVIOUS) return Intent::REPEAT_PREVIOUS;
        if ($this->has($t, self::AVAIL))    return Intent::AVAILABILITY;   // "X sold out" before menu/price
        if ($this->has($t, self::SPECIAL))  return Intent::SPECIAL;
        if ($this->has($t, self::MENU))     return Intent::MENU;
        if ($this->has($t, self::SCHEDULE)) return Intent::SCHEDULE;
        if ($this->has($t, self::POLICY))   return Intent::POLICY;
        if ($this->has($t, self::FACILITY)) return Intent::FACILITY;
        if ($this->looksLikePrice($t))      return Intent::PRICE;
        return Intent::NOTE;
    }

    private function has(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) { if (str_contains($haystack, $n)) return true; }
        return false;
    }

    /** "<name> <number>" or "<name> 5k" — a bare price line. */
    private function looksLikePrice(string $t): bool
    {
        return (bool) preg_match('/[a-z].*\s\d{1,3}(?:[,.]?\d{3})*\s*k?\s*$/i', trim($t));
    }
}
