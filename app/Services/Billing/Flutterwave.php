<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;

/**
 * Flutterwave (Uganda Mobile Money — MTN & Airtel).
 * Flow: create a charge with the customer's phone + network; they approve the
 * PIN prompt on their phone; Flutterwave calls our webhook; we verify and
 * extend the plan. (No card-on-file recurring exists for MoMo — each renewal
 * is a fresh approved charge, which the panel makes one tap.)
 */
class Flutterwave
{
    public function enabled(): bool
    {
        return (bool) config('billing.flutterwave.secret');
    }

    protected function http()
    {
        return Http::withToken(config('billing.flutterwave.secret'))
            ->baseUrl(rtrim(config('billing.flutterwave.base'), '/'))
            ->acceptJson()
            ->timeout(25);
    }

    /** Charge a Ugandan MoMo number. $network = 'MTN' | 'AIRTEL'. */
    public function chargeMobileMoney(array $p): array
    {
        try {
            return $this->http()->post('/charges?type=mobile_money_uganda', [
                'tx_ref'       => $p['tx_ref'],
                'amount'       => $p['amount'],
                'currency'     => 'UGX',
                'phone_number' => preg_replace('/[^0-9]/', '', $p['phone']),
                'network'      => strtoupper($p['network']),
                'email'        => $p['email'] ?? 'billing@mycloudbss.com',
                'fullname'     => $p['fullname'] ?? 'CloudBSS Shop',
                'meta'         => $p['meta'] ?? [],
            ])->json() ?? [];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function verifyByReference(string $txRef): array
    {
        try {
            return $this->http()->get('/transactions/verify_by_reference', ['tx_ref' => $txRef])->json() ?? [];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function verifyById($id): array
    {
        try {
            return $this->http()->get("/transactions/{$id}/verify")->json() ?? [];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** Validate the "verif-hash" header Flutterwave sends with webhooks. */
    public function webhookHashValid(?string $given): bool
    {
        $hash = config('billing.flutterwave.hash');
        return $hash && $given && hash_equals($hash, $given);
    }
}
