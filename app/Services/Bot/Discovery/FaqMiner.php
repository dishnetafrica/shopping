<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — FAQ extraction. Pure logic.
 *
 * Counts how often customers ask about each common topic, across English / Swahili / Gujlish, and
 * returns the topics that recur as FAQ candidates with a confidence proportional to frequency.
 *
 * @return list of ['topic'=>string,'label'=>string,'count'=>int,'confidence'=>int]
 */
class FaqMiner
{
    private const TOPICS = [
        'hours'        => ['open', 'opening', 'closed', 'what time', 'kab khula', 'kitne baje', 'saa ngapi', 'khulla', 'band'],
        'delivery'     => ['deliver', 'delivery', 'home delivery', 'ghar', 'pahuncha', 'lete', 'fika', 'send to'],
        'location'     => ['where are you', 'location', 'address', 'kahan', 'kya', 'wapi', 'directions', 'shop kaha'],
        'price'        => ['how much', 'price', 'rate', 'kitne ka', 'kitla', 'bei gani', 'cost', 'bhav'],
        'payment'      => ['mpesa', 'momo', 'mobile money', 'cash', 'pay', 'payment', 'bank', 'paisa', 'pesa'],
        'availability' => ['available', 'in stock', 'do you have', 'milega', 'iko', 'have you got', 'stock'],
        'minimum'      => ['minimum order', 'min order', 'minimum'],
    ];

    private const LABEL = [
        'hours' => 'Opening hours', 'delivery' => 'Delivery available?', 'location' => 'Shop location',
        'price' => 'Pricing', 'payment' => 'Payment methods', 'availability' => 'Stock / availability',
        'minimum' => 'Minimum order',
    ];

    public static function mine(MessageCorpus $corpus, int $minHits = 2): array
    {
        $counts = array_fill_keys(array_keys(self::TOPICS), 0);

        foreach ($corpus->customer as $msg) {
            $m = mb_strtolower($msg);
            foreach (self::TOPICS as $topic => $needles) {
                foreach ($needles as $n) {
                    if (str_contains($m, $n)) { $counts[$topic]++; break; }
                }
            }
        }

        $out = [];
        arsort($counts);
        foreach ($counts as $topic => $c) {
            if ($c < $minHits) continue;
            $out[] = [
                'topic'      => $topic,
                'label'      => self::LABEL[$topic] ?? ucfirst($topic),
                'count'      => $c,
                'confidence' => min(95, 40 + $c * 8),
            ];
        }
        return $out;
    }
}
