<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;

/**
 * Stripe (international cards, USD) with true monthly auto-renew.
 * We create a hosted Checkout Session in subscription mode using inline
 * price_data (no pre-created Price needed). Stripe then bills the card every
 * month and fires invoice.paid, which our webhook turns into another month.
 */
class StripeGateway
{
    public function enabled(): bool
    {
        return (bool) config('billing.stripe.secret');
    }

    protected function http()
    {
        return Http::asForm()
            ->withToken(config('billing.stripe.secret'))
            ->baseUrl('https://api.stripe.com/v1')
            ->timeout(25);
    }

    /**
     * Create a hosted Checkout session (subscription, monthly).
     * Returns the decoded session; use ['url'] to redirect the shop.
     */
    public function checkoutSubscription(array $p): array
    {
        $currency = config('billing.stripe.currency', 'usd');
        try {
            return $this->http()->post('/checkout/sessions', [
                'mode'                  => 'subscription',
                'success_url'           => $p['success_url'],
                'cancel_url'            => $p['cancel_url'],
                'client_reference_id'   => $p['tx_ref'],
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]'    => $currency,
                'line_items[0][price_data][unit_amount]' => (int) round($p['amount_usd'] * 100),
                'line_items[0][price_data][recurring][interval]' => 'month',
                'line_items[0][price_data][product_data][name]'  => $p['name'],
                'metadata[tenant_id]' => $p['tenant_id'],
                'metadata[plan]'      => $p['plan'],
                'metadata[tx_ref]'    => $p['tx_ref'],
                'subscription_data[metadata][tenant_id]' => $p['tenant_id'],
                'subscription_data[metadata][plan]'      => $p['plan'],
            ])->json() ?? [];
        } catch (\Throwable $e) {
            return ['error' => ['message' => $e->getMessage()]];
        }
    }

    /** Verify the Stripe-Signature header against the raw request body. */
    public function webhookValid(string $rawBody, ?string $sigHeader): bool
    {
        $secret = config('billing.stripe.webhook_secret');
        if (! $secret || ! $sigHeader) return false;

        $t = null; $v1 = [];
        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) continue;
            if ($kv[0] === 't')  $t = $kv[1];
            if ($kv[0] === 'v1') $v1[] = $kv[1];
        }
        if (! $t || ! $v1) return false;

        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        foreach ($v1 as $sig) {
            if (hash_equals($expected, $sig)) return true;
        }
        return false;
    }
}
