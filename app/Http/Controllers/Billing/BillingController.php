<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Billing\Flutterwave;
use App\Services\Billing\StripeGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    /** The in-app billing/upgrade page (authed + tenant). */
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) return redirect('/app/login');

        $path = resource_path('panel/billing.html');
        if (! is_file($path)) abort(500, 'Billing asset missing.');

        $name = preg_replace('/[<>"\']/', '', (string) ($user->tenant->name ?? 'Shop')) ?: 'Shop';
        $html = str_replace('Family Shopper', $name, file_get_contents($path));

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Plans, prices and which providers are switched on. */
    public function quote(Request $request, Flutterwave $flw, StripeGateway $stripe)
    {
        $t = $request->user()->tenant;
        $plans = [];
        foreach (['starter', 'pro'] as $key) {
            $c = config('plans.' . $key);
            $plans[] = [
                'key'       => $key,
                'name'      => $c['name'],
                'price_ugx' => $c['price_ugx'] ?? null,
                'price_usd' => $c['price_usd'] ?? null,
            ];
        }

        return response()->json([
            'current' => [
                'plan'       => $t->effectivePlan(),
                'label'      => $t->planLabel(),
                'paid_until' => optional($t->paid_until)->toDateString(),
                'trial_days' => $t->trialDaysLeft(),
            ],
            'plans'     => $plans,
            'providers' => [
                'momo' => $flw->enabled(),
                'card' => $stripe->enabled(),
            ],
        ]);
    }

    /** Start a Mobile Money charge (MTN / Airtel). */
    public function payMomo(Request $request, Flutterwave $flw)
    {
        if (! $flw->enabled()) {
            return response()->json(['ok' => false, 'error' => 'momo_not_configured'], 400);
        }
        $t       = $request->user()->tenant;
        $plan    = (string) $request->input('plan');
        $phone   = preg_replace('/[^0-9]/', '', (string) $request->input('phone'));
        $network = strtoupper((string) $request->input('network'));

        if (! in_array($plan, ['starter', 'pro'], true)) {
            return response()->json(['ok' => false, 'error' => 'bad_plan'], 422);
        }
        if (! in_array($network, ['MTN', 'AIRTEL'], true)) {
            return response()->json(['ok' => false, 'error' => 'bad_network'], 422);
        }
        if (strlen($phone) < 9) {
            return response()->json(['ok' => false, 'error' => 'bad_phone'], 422);
        }

        $amount = (int) (config('plans.' . $plan . '.price_ugx') ?? 0);
        $txRef  = 'CB-' . $t->id . '-' . strtoupper(Str::random(8));

        $pay = Payment::create([
            'tenant_id' => $t->id, 'provider' => 'flutterwave', 'plan' => $plan,
            'months' => 1, 'amount' => $amount, 'currency' => 'UGX',
            'tx_ref' => $txRef, 'network' => $network, 'phone' => $phone, 'status' => 'pending',
        ]);

        $resp = $flw->chargeMobileMoney([
            'tx_ref' => $txRef, 'amount' => $amount, 'phone' => $phone, 'network' => $network,
            'email' => $request->user()->email ?? 'billing@mycloudbss.com',
            'fullname' => $t->name, 'meta' => ['tenant_id' => $t->id, 'plan' => $plan],
        ]);

        if (($resp['status'] ?? '') === 'error') {
            $pay->update(['status' => 'failed', 'meta' => $resp]);
            return response()->json(['ok' => false, 'error' => 'charge_failed', 'detail' => $resp['message'] ?? 'Charge failed'], 502);
        }

        $pay->update(['provider_ref' => (string) data_get($resp, 'data.id', ''), 'meta' => $resp]);

        return response()->json([
            'ok'      => true,
            'tx_ref'  => $txRef,
            'status'  => 'pending',
            'message' => 'Check your phone and approve the payment (enter your Mobile Money PIN).',
        ]);
    }

    /** Start a Stripe card checkout (hosted page, monthly auto-renew). */
    public function payCard(Request $request, StripeGateway $stripe)
    {
        if (! $stripe->enabled()) {
            return response()->json(['ok' => false, 'error' => 'card_not_configured'], 400);
        }
        $t    = $request->user()->tenant;
        $plan = (string) $request->input('plan');
        if (! in_array($plan, ['starter', 'pro'], true)) {
            return response()->json(['ok' => false, 'error' => 'bad_plan'], 422);
        }

        $usd   = (float) (config('plans.' . $plan . '.price_usd') ?? 0);
        $txRef = 'CB-' . $t->id . '-' . strtoupper(Str::random(8));

        Payment::create([
            'tenant_id' => $t->id, 'provider' => 'stripe', 'plan' => $plan,
            'months' => 1, 'amount' => $usd, 'currency' => strtoupper(config('billing.stripe.currency', 'usd')),
            'tx_ref' => $txRef, 'status' => 'pending',
        ]);

        $session = $stripe->checkoutSubscription([
            'tx_ref' => $txRef, 'plan' => $plan, 'tenant_id' => $t->id, 'amount_usd' => $usd,
            'name' => 'CloudBSS ' . ucfirst($plan) . ' — ' . $t->name,
            'success_url' => url('/panel/billing?paid=1'),
            'cancel_url'  => url('/panel/billing?cancelled=1'),
        ]);

        $url = data_get($session, 'url');
        if (! $url) {
            return response()->json(['ok' => false, 'error' => 'session_failed', 'detail' => data_get($session, 'error.message')], 502);
        }
        return response()->json(['ok' => true, 'redirect' => $url]);
    }

    /** Poll a payment's status; also actively verify MoMo with Flutterwave. */
    public function status(Request $request, Flutterwave $flw)
    {
        $t   = $request->user()->tenant;
        $pay = Payment::where('tenant_id', $t->id)->where('tx_ref', (string) $request->query('tx_ref'))->first();
        if (! $pay) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        if ($pay->status === 'pending' && $pay->provider === 'flutterwave') {
            $v = $flw->verifyByReference($pay->tx_ref);
            $st = strtolower((string) data_get($v, 'data.status'));
            if ($st === 'successful' && (int) data_get($v, 'data.amount') >= (int) $pay->amount) {
                $this->markPaid($pay, $t);
            } elseif (in_array($st, ['failed', 'cancelled'], true)) {
                $pay->update(['status' => 'failed']);
            }
            $pay->refresh();
        }

        return response()->json(['ok' => true, 'status' => $pay->status, 'plan' => $t->fresh()->effectivePlan()]);
    }

    // ---------------- Webhooks (public, no session) ----------------

    public function flutterwaveWebhook(Request $request, Flutterwave $flw)
    {
        if (! $flw->webhookHashValid($request->header('verif-hash'))) {
            return response('invalid', 401);
        }
        $data  = $request->input('data', []);
        $txRef = (string) data_get($data, 'tx_ref');
        $pay   = Payment::where('tx_ref', $txRef)->first();
        if (! $pay) return response('ok'); // not ours; ack anyway

        if ($pay->status !== 'successful'
            && strtolower((string) data_get($data, 'status')) === 'successful'
            && (int) data_get($data, 'amount') >= (int) $pay->amount) {
            if ($tenant = Tenant::find($pay->tenant_id)) {
                $this->markPaid($pay, $tenant);
            }
        }
        return response('ok');
    }

    public function stripeWebhook(Request $request, StripeGateway $stripe)
    {
        $raw = $request->getContent();
        if (! $stripe->webhookValid($raw, $request->header('Stripe-Signature'))) {
            return response('invalid', 401);
        }
        $event = json_decode($raw, true) ?: [];
        $type  = $event['type'] ?? '';
        $obj   = data_get($event, 'data.object', []);

        // First payment (checkout) and every monthly renewal (invoice.paid).
        $tenantId = data_get($obj, 'metadata.tenant_id')
            ?? data_get($obj, 'subscription_details.metadata.tenant_id')
            ?? data_get($obj, 'lines.data.0.metadata.tenant_id');
        $plan = data_get($obj, 'metadata.plan')
            ?? data_get($obj, 'subscription_details.metadata.plan')
            ?? data_get($obj, 'lines.data.0.metadata.plan');

        if (in_array($type, ['checkout.session.completed', 'invoice.paid', 'invoice.payment_succeeded'], true)
            && $tenantId && in_array($plan, ['starter', 'pro'], true)) {
            if ($tenant = Tenant::find((int) $tenantId)) {
                $ref = (string) (data_get($obj, 'client_reference_id') ?: data_get($obj, 'id'));
                $pay = Payment::firstOrNew(['tx_ref' => $ref]);
                $pay->fill([
                    'tenant_id' => $tenant->id, 'provider' => 'stripe', 'plan' => $plan,
                    'months' => 1, 'amount' => (float) (config('plans.' . $plan . '.price_usd') ?? 0),
                    'currency' => 'USD', 'provider_ref' => (string) data_get($obj, 'subscription', ''),
                ]);
                $pay->status = 'successful';
                $pay->save();
                $tenant->applyPaidPlan($plan, 1);
                $this->sendReceipt($tenant, $pay);
            }
        }
        return response('ok');
    }

    /** Shared: mark a pending payment successful and extend the plan. */
    protected function markPaid(Payment $pay, Tenant $tenant): void
    {
        $pay->update(['status' => 'successful']);
        $tenant->applyPaidPlan($pay->plan, (int) ($pay->months ?: 1));
        $this->sendReceipt($tenant, $pay);
    }

    /** WhatsApp a payment receipt to the payer / owner. */
    protected function sendReceipt(Tenant $tenant, Payment $pay): void
    {
        $cur   = $pay->currency;
        $amt   = number_format((float) $pay->amount);
        $until = optional($tenant->fresh()->paid_until)->toDateString();
        $txt = "\u{2705} Payment received — {$cur} {$amt}.\n"
            . 'Your ' . ucfirst($pay->plan) . ' plan is active' . ($until ? " until {$until}" : '') . ".\n"
            . 'Thank you for using CloudBSS!';
        \App\Jobs\NotifyOwner::dispatch($tenant->id, $txt, $pay->phone ?: null);
    }
}
