<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — sales-behaviour extraction. Pure logic.
 *
 * Where ProductMiner learns WHAT a business sells, SalesPatternMiner learns HOW its people sell:
 * it reconstructs customer -> owner reply turns from the conversation and classifies each owner
 * move (qualification question, upsell, cross-sell, objection handling, delivery / order workflow,
 * escalation, discovery question). The result is the behavioural half of a Digital Employee.
 *
 * Heuristic, not semantic: it catches clear, repeated moves and deliberately stays quiet on
 * ambiguous ones (the same discipline that keeps ProductMiner from inventing products).
 */
class SalesPatternMiner
{
    /** Pattern types in priority order — first match wins per turn. */
    private const TYPES = [
        'escalation', 'objection_handling', 'upsell', 'cross_sell',
        'delivery_workflow', 'order_workflow', 'qualification', 'discovery_question',
    ];

    private const LABEL = [
        'qualification'      => 'Qualification',
        'discovery_question' => 'Discovery question',
        'upsell'             => 'Upsell',
        'cross_sell'         => 'Cross-sell',
        'objection_handling' => 'Objection handling',
        'delivery_workflow'  => 'Delivery workflow',
        'order_workflow'     => 'Order workflow',
        'escalation'         => 'Escalation',
    ];

    public static function mine(MessageCorpus $corpus, int $minCount = 2): array
    {
        $pairs   = self::turns($corpus);
        $buckets = array_fill_keys(self::TYPES, []);

        foreach ($pairs as [$cust, $reply]) {
            $type = self::classify($cust, $reply);
            if ($type === null) continue;
            $buckets[$type][] = [
                'trigger'  => self::clip($cust, 70),
                'response' => self::clip($reply, 90),
                'rk'       => self::norm($reply),
            ];
        }

        $byType  = [];
        $summary = [];
        $flat    = [];
        foreach (self::TYPES as $type) {
            $rows = $buckets[$type];
            if (! $rows) continue;
            // de-dup near-identical responses, keep counts
            $seen = []; $examples = [];
            foreach ($rows as $r) {
                $k = $r['rk'];
                if (! isset($seen[$k])) { $seen[$k] = 0; $examples[$k] = $r; }
                $seen[$k]++;
            }
            $count = count($rows);
            if ($count < $minCount) continue;                  // must recur to be a pattern
            arsort($seen);
            $top = [];
            foreach (array_keys($seen) as $k) {
                $ex = $examples[$k];
                $top[] = ['trigger' => $ex['trigger'], 'response' => $ex['response'], 'count' => $seen[$k]];
                if (count($top) >= 3) break;
            }
            $byType[$type] = [
                'label'      => self::LABEL[$type],
                'count'      => $count,
                'confidence' => min(95, 35 + $count * 12),
                'examples'   => $top,
            ];
            $summary[] = self::LABEL[$type] . ': ' . self::summarise($type, $top);
            foreach ($top as $t) $flat[] = ['type' => $type, 'label' => self::LABEL[$type]] + $t;
        }

        // Distinct qualifying/discovery questions an employee asks — useful as a checklist.
        $questions = [];
        foreach (['qualification', 'discovery_question'] as $qt) {
            foreach ($byType[$qt]['examples'] ?? [] as $e) $questions[] = $e['response'];
        }
        $questions = array_values(array_unique($questions));

        $overall = $byType ? (int) round(array_sum(array_map(fn ($b) => $b['confidence'], $byType)) / max(1, count($byType))) : 0;

        return [
            'patterns'   => array_slice($flat, 0, 12),
            'by_type'    => $byType,
            'summary'    => $summary,
            'questions'  => array_slice($questions, 0, 8),
            'turns_seen' => count($pairs),
            'confidence' => $overall,
        ];
    }

    /** Reconstruct customer -> owner-reply turns in chronological order. */
    private static function turns(MessageCorpus $corpus): array
    {
        $rows = $corpus->all;
        usort($rows, fn ($a, $b) => strcmp((string) ($a['ts'] ?? ''), (string) ($b['ts'] ?? '')));

        $pairs = []; $lastCust = null; $i = 0; $n = count($rows);
        while ($i < $n) {
            $r = $rows[$i];
            $body = trim((string) ($r['body'] ?? ''));
            if (! ($r['from_owner'] ?? false)) {
                if ($body !== '') $lastCust = $body;
                $i++;
                continue;
            }
            // owner turn — gather consecutive owner messages as one reply
            if ($lastCust === null) { $i++; continue; }
            $reply = [];
            while ($i < $n && ($rows[$i]['from_owner'] ?? false)) {
                $b = trim((string) ($rows[$i]['body'] ?? ''));
                if ($b !== '') $reply[] = $b;
                $i++;
            }
            if ($reply) $pairs[] = [$lastCust, implode(' ', $reply)];
            $lastCust = null;
        }
        return $pairs;
    }

    private static function classify(string $cust, string $reply): ?string
    {
        $c = self::norm($cust);
        $r = self::norm($reply);
        if ($r === '') return null;
        $q = self::isQuestion($reply);

        if (preg_match('/(let me check|i will call|i\'?ll call|call you|get back to you|our (manager|team|engineer)|forward.*(team|manager)|connect you|visit (our|the)|come to (our )?(office|shop)|technician will)/', $r)) {
            return 'escalation';
        }
        if (preg_match('/(expensive|costly|too much|mehe?nga|bahut|slow|too slow|very slow|not working|problem|cheaper|any discount|kam kar|zyada|reduce|high price)/', $c)) {
            return 'objection_handling';
        }
        if (preg_match('/(is cheaper|cheaper (than|inside|for)|better (option|deal|value)|i (recommend|suggest)|instead of|upgrade|go for|i prefer|rather (take|go)|more value|premium|higher (plan|package)|fiber is|starlink is|better to)/', $r)) {
            return 'upsell';
        }
        if (preg_match('/(also (try|add|take|get|buy)|you can also|with (that|this|your)|add (a|an|some)|combo|together with|as well|extra|pair (it|with)|don\'?t forget)/', $r)) {
            return 'cross_sell';
        }
        if (preg_match('/(we deliver|delivery (is|will|takes|charge)|our rider|boda will|we dispatch|send (it )?to (your|you)|bring it|drop it|same day delivery)/', $r)) {
            return 'delivery_workflow';
        }
        if (preg_match('/(to order|share (your )?(location|address)|send (your )?(location|address|order)|confirm your order|pay (via|by|to|on)|payment (is|via|by)|momo|m-?pesa|mobile money|deposit|advance)/', $r)) {
            return 'order_workflow';
        }
        if ($q && preg_match('/(need|want|looking|chahiye|interested|do you have|price|how much|kitne|available|get|buy|setup|install)/', $c)) {
            return 'qualification';
        }
        if ($q) {
            return 'discovery_question';
        }
        return null;
    }

    private static function isQuestion(string $reply): bool
    {
        if (str_contains($reply, '?')) return true;
        $r = self::norm($reply);
        return (bool) preg_match('/\b(which|what|where|when|how many|how much|home or|or business|do you|are you|would you|konsa|kaun|kitne|kahan|kya|size|type|prefer)\b/', $r);
    }

    private static function summarise(string $type, array $top): string
    {
        $first = $top[0]['response'] ?? '';
        if (in_array($type, ['qualification', 'discovery_question'], true)) return 'asks "' . $first . '"';
        if ($type === 'upsell' || $type === 'cross_sell')                   return 'e.g. "' . $first . '"';
        return '"' . $first . '"';
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private static function clip(string $s, int $n): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
    }
}
