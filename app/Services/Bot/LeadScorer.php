<?php
namespace App\Services\Bot;

/**
 * Simple, deterministic lead score (0-100) so a salesperson sees HOT vs cold before
 * calling. Rule-based on urgency, high-value intent, and explicit call-back requests.
 * No AI, no state — pure function of the message.
 */
class LeadScorer
{
    private const URGENCY = ['urgent', 'asap', 'immediately', 'today', 'right now', 'emergency', 'quickly', 'as soon'];
    private const HIGH    = ['starlink', 'installation', 'install', 'quotation', 'quote', 'bulk', 'wholesale', 'dealer', 'reseller', 'demo', 'enterprise', 'office'];
    private const CALL    = ['call me', 'call back', 'callback', 'ring me', 'please call'];

    public function score(string $intent, string $message): int
    {
        $lc = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', trim($message))) . ' ';
        $s  = $intent === 'ticket' ? 45 : 30;          // service issues start a notch higher
        if ($this->any($lc, self::URGENCY)) $s += 40;
        if ($this->any($lc, self::HIGH))    $s += 20;
        if ($this->any($lc, self::CALL))    $s += 20;

        return max(0, min(100, $s));
    }

    /** "Hot" / "Warm" / "Cold" label for a score. */
    public function band(int $score): string
    {
        if ($score >= 70) return '🔥 Hot';
        if ($score >= 45) return 'Warm';
        return 'Cold';
    }

    private function any(string $haystack, array $words): bool
    {
        foreach ($words as $w) {
            if ($w !== '' && str_contains($haystack, $w)) return true;
        }
        return false;
    }
}
