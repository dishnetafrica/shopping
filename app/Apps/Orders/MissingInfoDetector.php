<?php
namespace App\Apps\Orders;

use App\Apps\Orders\Dto\DraftOrder;

/**
 * Computes the SMALLEST missing piece by PRIORITY (CRITICAL > MEDIUM > LOW), so the bot always
 * asks the most important question first and never re-asks for things already known. A required
 * field present but low-confidence is surfaced for confirmation.
 */
class MissingInfoDetector
{
    public const PRIORITY = [
        'lines'          => 'CRITICAL',
        'address'        => 'CRITICAL',
        'delivery_time'  => 'MEDIUM',
        'payment_method' => 'MEDIUM',
        'phone'          => 'LOW',
    ];

    private const RANK = ['CRITICAL' => 0, 'MEDIUM' => 1, 'LOW' => 2];

    private const QUESTIONS = [
        'lines'          => 'What would you like to order?',
        'address'        => 'What delivery address or area should we send it to?',
        'delivery_time'  => 'What time would you like it delivered?',
        'payment_method' => 'How would you like to pay — Mobile Money, Cash, or Card?',
        'phone'          => 'What phone number should we contact on delivery?',
    ];

    private const LOW_CONF = 0.5;

    /**
     * @return array{complete:bool,next:?string,question:?string,missing:string[],priorities:array<string,string>,uncertain:string[]}
     */
    public function evaluate(DraftOrder $draft): array
    {
        $missing = $draft->missing();

        // order missing fields by priority rank, then by REQUIRED declaration order
        usort($missing, function ($a, $b) {
            $ra = self::RANK[self::PRIORITY[$a] ?? 'LOW']; $rb = self::RANK[self::PRIORITY[$b] ?? 'LOW'];
            if ($ra !== $rb) return $ra <=> $rb;
            return array_search($a, DraftOrder::REQUIRED, true) <=> array_search($b, DraftOrder::REQUIRED, true);
        });

        $uncertain = [];
        foreach (DraftOrder::REQUIRED as $f) {
            if ($draft->has($f) && ($draft->confidence[$f] ?? 1.0) < self::LOW_CONF) $uncertain[] = $f;
        }

        $next = $missing[0] ?? ($uncertain[0] ?? null);
        return [
            'complete'   => $missing === [],
            'next'       => $next,
            'question'   => $next ? (self::QUESTIONS[$next] ?? null) : null,
            'missing'    => $missing,
            'priorities' => array_filter(self::PRIORITY, fn ($f) => in_array($f, $missing, true), ARRAY_FILTER_USE_KEY),
            'uncertain'  => $uncertain,
        ];
    }
}
