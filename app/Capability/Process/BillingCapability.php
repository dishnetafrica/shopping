<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use Illuminate\Support\Carbon;

/**
 * Billing — the V3 face of the plan/subscription screen (V3 Migration: replaces the info side of legacy
 * /panel/billing). VIEW-FIRST and payment-safe: it shows the current plan, trial/paid status and the
 * Free/Starter/Pro comparison (from the existing plans config + the Tenant plan methods). The actual
 * upgrade/payment (Mobile Money / card) is untouched — the action opens the existing secure billing
 * page. No payment gateway is touched from here.
 */
final class BillingCapability implements ProcessCapability
{
    public function key(): string { return 'billing'; }

    public function permission(): string { return 'menu:settings'; }

    private const FEATURES = [
        'bot' => 'WhatsApp bot', 'orders' => 'Orders', 'confirmations' => 'Order confirmations',
        'pos' => 'POS', 'dispatch' => 'Dispatch', 'tracking' => 'Delivery tracking', 'reports' => 'Reports',
        'returns' => 'Returns', 'branding' => 'Custom branding', 'multi_user' => 'Multiple staff',
        'multi_number' => 'Multiple WhatsApp numbers',
    ];

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenant;
        $effective = $t->effectivePlan();
        $onTrial   = $t->onTrial();
        $trialDays = $t->trialDaysLeft();
        $paidUntil = $t->paid_until ? Carbon::parse($t->paid_until) : null;

        $orderCap = $t->orderCap();
        $usedOrders = $t->ordersThisMonth();

        $statusLabel = $onTrial ? 'Trial ends in' : ($paidUntil ? 'Renews on' : 'Plan');
        $statusValue = $onTrial ? ($trialDays . ' day' . ($trialDays === 1 ? '' : 's'))
            : ($paidUntil ? $paidUntil->format('d M Y') : 'Free');
        $statusTone = $onTrial ? ($trialDays <= 5 ? 'warn' : 'ok') : 'ok';

        // Plan comparison table, with the current plan flagged.
        $rows = [];
        foreach (['free', 'starter', 'pro'] as $key) {
            $c = (array) config('plans.' . $key, []);
            if ($c === []) {
                continue;
            }
            $isCurrent = $key === $effective;
            $price = $key === 'free'
                ? 'Free'
                : number_format((int) ($c['price_ugx'] ?? 0)) . ' UGX  ·  $' . (int) ($c['price_usd'] ?? 0) . '/mo';
            $orders = ($c['order_cap'] ?? null) === null ? 'Unlimited' : ((int) $c['order_cap'] . '/mo');
            $users  = ($c['user_cap'] ?? null) === null ? 'Unlimited' : ((int) $c['user_cap'] . ' user' . ((int) $c['user_cap'] === 1 ? '' : 's'));
            $includes = implode(', ', array_map(fn ($f) => self::FEATURES[$f] ?? $f, (array) ($c['features'] ?? [])));

            $planCell = ['value' => (string) ($c['name'] ?? ucfirst($key))];
            if ($isCurrent) {
                $planCell['badge'] = 'ok';
                $planCell['sub'] = 'Current plan';
            }
            $rows[] = [
                'plan'     => $planCell,
                'price'    => ['value' => $price],
                'orders'   => ['value' => $orders],
                'team'     => ['value' => $users],
                'includes' => ['value' => $includes],
            ];
        }

        $manage = ['key' => 'manage_billing', 'label' => 'Upgrade / manage payment',
            'href' => '/panel/billing?classic=1', 'actor' => 'human', 'tone' => 'primary'];

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:billing', 'title' => 'Billing',
                'subtitle' => 'Your plan: ' . $t->planLabel(),
            ])
            ->headline($t->planLabel(), 'current plan', $onTrial ? 'ok' : 'default')
            ->financials([
                ['key' => 'plan', 'label' => 'Current plan', 'value' => ucfirst($effective)],
                ['key' => 'status', 'label' => $statusLabel, 'value' => $statusValue, 'tone' => $statusTone],
                ['key' => 'orders', 'label' => 'Orders this month', 'value' => $usedOrders . ' / ' . ($orderCap === null ? '∞' : (string) $orderCap),
                    'foot' => $orderCap === null ? 'unlimited on your plan' : 'monthly limit'],
            ])
            ->table(
                [
                    ['key' => 'plan', 'label' => 'Plan', 'type' => 'strong'],
                    ['key' => 'price', 'label' => 'Price'],
                    ['key' => 'orders', 'label' => 'Orders'],
                    ['key' => 'team', 'label' => 'Team'],
                    ['key' => 'includes', 'label' => 'Includes'],
                ],
                $rows, [], ['pageSize' => 10])
            ->actions([$manage])
            ->meta(['capability' => 'billing', 'ttl' => 60, 'permissions' => ['menu:settings'],
                'note' => 'View-first: payment (Mobile Money / card) runs on the existing secure billing page.']);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['plan' => $ctx->tenant->effectivePlan()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
