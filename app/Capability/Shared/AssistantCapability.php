<?php

namespace App\Capability\Shared;

use App\Capability\Dto\ActionSpec;
use App\Capability\Dto\Brief;

/**
 * The brief kernel. PURE reasoning over signals — no DB, no service calls — so it is deterministic
 * and unit-testable in isolation. Object capabilities feed it the signals they gathered.
 */
final class AssistantCapability
{
    /**
     * @param array $s ['overdue','overdueCount','maxDaysLate','exposure','name','balance','endpoint','phone']
     */
    public function customerBrief(array $s): Brief
    {
        $name = $s['name'] ?? 'This customer';
        $overdue = (float) ($s['overdue'] ?? 0);
        $count = (int) ($s['overdueCount'] ?? 0);
        $maxLate = (int) ($s['maxDaysLate'] ?? 0);
        $exposure = (float) ($s['exposure'] ?? 0);
        $balance = (float) ($s['balance'] ?? $exposure);
        $ep = (string) ($s['endpoint'] ?? '');
        $money = fn ($n) => '$' . number_format((float) $n);
        $metrics = [
            ['label' => 'Exposure', 'value' => $money($exposure)],
            ['label' => 'Overdue', 'value' => $count . ' · ' . $money($overdue)],
        ];
        $pay = fn () => $ep ? new ActionSpec('record_payment', 'Record payment', $ep . '/record_payment', 'POST', 'human', 'primary', [
            'amount' => 'money',
            'currency' => 'enum:UGX,USD,SSP',
            'method' => 'enum:cash,bank,mobile',
        ]) : null;
        // v4.52 — this was `send_reminder`, which answered "Reminder sent." and sent nothing at all.
        // Chasing an overdue account now attaches the real statement PDF through the same panel
        // endpoint the customer record's ⋯ menu uses, and is offered only when there is a number to
        // send it to. Still pure: the phone arrives as a signal, nothing is looked up here.
        $phone = trim((string) ($s['phone'] ?? ''));
        $chase = fn () => $phone !== '' ? new ActionSpec(
            key: 'send_statement',
            label: 'Send statement',
            endpoint: '/papi/statement-send',
            method: 'POST',
            actor: 'human',
            confirm: 'Send the account statement to ' . $name . ' on WhatsApp?',
            body: ['phone' => $phone],
        ) : null;

        if ($count >= 2) {
            $level = $maxLate > 30 ? 'high' : 'med';

            return new Brief($level,
                "{$name} has {$count} invoices overdue — " . $money($overdue) . ' past due.',
                "Oldest is {$maxLate} days late. Collection risk rises the longer it sits.",
                ['label' => "{$count} overdue", 'tone' => $maxLate > 30 ? 'danger' : 'warn'],
                $metrics,
                "Send the statement, then call once and discuss all {$count} invoices together. Offer a payment plan; hold new orders until at least " . $money(round($overdue / 2)) . ' is collected.',
                array_values(array_filter([$pay(), $chase()])),
                ['StatementService', 'HealthCapability'],
            );
        }

        if ($overdue > 0) {
            $level = $maxLate > 30 ? 'high' : 'med';

            return new Brief($level,
                "{$name} owes " . $money($balance) . ', ' . $money($overdue) . ' overdue.',
                "Oldest invoice is {$maxLate} days past due.",
                ['label' => $maxLate . 'd overdue', 'tone' => $maxLate > 30 ? 'danger' : 'warn'],
                $metrics,
                'Send the statement now so they can see exactly what is outstanding; call if unpaid within 3 days.',
                array_values(array_filter([$pay(), $chase()])),
                ['StatementService', 'HealthCapability'],
            );
        }

        if ($balance > 0) {
            return new Brief('low',
                "{$name} owes " . $money($balance) . ', within terms.',
                'No overdue invoices — nothing to chase yet.',
                ['label' => 'Awaiting payment', 'tone' => 'info'],
                $metrics,
                'No action needed; it will surface in Collections if it slips.',
                array_values(array_filter([$pay()])),
                ['StatementService'],
            );
        }

        return new Brief('low',
            "{$name} is a healthy account — nothing owed.",
            'No outstanding balance and a clean history.',
            ['label' => 'Healthy', 'tone' => 'ok'],
            $metrics,
            'Good time to upsell a larger order.',
            [],
            ['StatementService'],
        );
    }
}
