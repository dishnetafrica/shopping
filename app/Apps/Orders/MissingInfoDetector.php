<?php
namespace App\Apps\Orders;

use App\Apps\Orders\Dto\DraftOrder;

/**
 * Computes the SMALLEST missing piece (and a human question for it) instead of re-asking for
 * things already known. A required field with low confidence is also surfaced (to confirm, not
 * re-ask blindly). The bot never asks "what do you want?" — only the next needed field.
 */
class MissingInfoDetector
{
    private const QUESTIONS = [
        'lines'          => 'What would you like to order?',
        'address'        => 'What delivery address or area should we send it to?',
        'delivery_time'  => 'What time would you like it delivered?',
        'payment_method' => 'How would you like to pay — Mobile Money, Cash, or Card?',
        'phone'          => 'What phone number should we contact on delivery?',
    ];

    private const LOW_CONF = 0.5;

    /** @return array{complete:bool,next:?string,question:?string,missing:string[],uncertain:string[]} */
    public function evaluate(DraftOrder $draft): array
    {
        $missing = $draft->missing();
        $uncertain = [];
        foreach (DraftOrder::REQUIRED as $f) {
            if ($draft->has($f) && ($draft->confidence[$f] ?? 1.0) < self::LOW_CONF) $uncertain[] = $f;
        }
        $next = $missing[0] ?? ($uncertain[0] ?? null);
        return [
            'complete'  => $missing === [],
            'next'      => $next,
            'question'  => $next ? (self::QUESTIONS[$next] ?? null) : null,
            'missing'   => $missing,
            'uncertain' => $uncertain,
        ];
    }
}
