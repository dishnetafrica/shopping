<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — language mix + owner communication style. Pure logic.
 */
class StyleProfiler
{
    private const MARKERS = [
        'swahili'  => ['na ', 'kwa', 'asante', 'habari', 'bei', 'leta', 'nataka', 'iko', 'pesa', 'karibu', 'tafadhali', 'sawa'],
        'gujlish'  => [' che', 'kitlo', 'kitla', 'kitli', ' su ', ' kem', 'nathi', 'joiye', 'aapo', 'bhai', 'kai', 'apo'],
        'hindi'    => [' hai', 'kitna', 'chahiye', 'kya ', 'nahi', 'kaise', 'kahan', 'bhej'],
    ];

    /** @return list of ['lang'=>string,'pct'=>int] sorted desc */
    public static function languages(MessageCorpus $corpus): array
    {
        $all = array_merge($corpus->owner, $corpus->customer);
        if (! $all) return [['lang' => 'English', 'pct' => 100]];

        $tally = ['English' => 0, 'Swahili' => 0, 'Gujlish' => 0, 'Hindi' => 0];
        foreach ($all as $msg) {
            $m = ' ' . mb_strtolower($msg) . ' ';
            $lang = 'English';
            foreach (self::MARKERS as $name => $marks) {
                foreach ($marks as $mk) {
                    if (str_contains($m, $mk)) { $lang = ucfirst($name === 'gujlish' ? 'Gujlish' : $name); break 2; }
                }
            }
            $tally[$lang]++;
        }
        $total = max(1, array_sum($tally));
        $out = [];
        foreach ($tally as $lang => $c) {
            if ($c === 0) continue;
            $out[] = ['lang' => $lang, 'pct' => (int) round($c / $total * 100)];
        }
        usort($out, fn ($a, $b) => $b['pct'] <=> $a['pct']);
        return $out;
    }

    /** Owner tone snapshot used to seed bot voice (not applied automatically). */
    public static function ownerStyle(MessageCorpus $corpus): array
    {
        $msgs = $corpus->owner;
        $n = count($msgs);
        if ($n === 0) return ['messages' => 0, 'confidence' => 0];

        $emoji = 0; $words = 0; $excl = 0; $greet = 0; $polite = 0;
        foreach ($msgs as $msg) {
            $emoji += preg_match_all('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $msg);
            $words += str_word_count($msg);
            $excl  += substr_count($msg, '!');
            $m = mb_strtolower($msg);
            if (preg_match('/\b(hi|hello|hey|namaste|jambo|karibu|welcome)\b/u', $m)) $greet++;
            if (preg_match('/\b(please|thank|thanks|kindly|asante)\b/u', $m)) $polite++;
        }

        $emojiRate = round($emoji / $n, 2);
        return [
            'messages'       => $n,
            'avg_words'      => (int) round($words / $n),
            'emoji_per_msg'  => $emojiRate,
            'greeting_rate'  => (int) round($greet / $n * 100),
            'polite_rate'    => (int) round($polite / $n * 100),
            'exclaim_rate'   => round($excl / $n, 2),
            'tone'           => self::tone($emojiRate, $polite / $n, $words / $n),
            'confidence'     => min(90, $n * 2),
        ];
    }

    private static function tone(float $emoji, float $polite, float $avgWords): string
    {
        if ($emoji >= 1.0 || $avgWords < 6) return 'casual & friendly';
        if ($polite >= 0.4) return 'warm & polite';
        return 'concise & direct';
    }
}
