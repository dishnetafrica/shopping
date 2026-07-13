<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Shared\AssistantCapability;
use App\Capability\Shared\HealthCapability;
use App\Capability\Shared\StatementCapability;
use App\Capability\Shared\TimelineCapability;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Services\Money\Fx;
use App\Services\Money\PaymentService;

/**
 * Customer Workspace. Composes shared capabilities for the read side; the write side delegates to
 * the FROZEN PaymentService, mirroring PaymentController's own mapping (Fx rate/usd, holder, kind).
 */
final class CustomerCapability implements ObjectCapability
{
    public function __construct(
        private StatementCapability $statement,
        private HealthCapability $health,
        private TimelineCapability $timeline,
        private AssistantCapability $assistant,
    ) {}

    public function key(): string { return 'customer'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'customer'])->meta(['capability' => 'customer']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->findOrFail((int) $id);
        $phone = (string) $c->phone;

        $fin = $this->statement->financialsFor($ctx, $phone);
        $sig = $this->health->signalsForPhone($ctx, $phone);

        $balance = 0.0;
        foreach ($fin as $f) {
            if ($f->key === 'balance') {
                $balance = (float) $f->value;
            }
        }
        $endpoint = '/api/v1/workspace/customer/' . $c->id;
        $brief = $this->assistant->customerBrief($sig + ['name' => (string) $c->name, 'balance' => $balance, 'endpoint' => $endpoint]);

        $invCount = Invoice::where('tenant_id', $ctx->tenantId())
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [preg_replace('/[^0-9]/', '', $phone)])
            ->where('status', '!=', 'void')->count();

        return (new WorkspacePayload)
            ->object([
                'type' => 'customer', 'id' => (string) $c->id, 'ref' => (string) $c->name,
                'title' => (string) $c->name,
                'subtitle' => trim(($c->address ? $c->address . ' · ' : '') . $phone),
                'status' => 'active',
            ])
            ->headline('$' . number_format($balance), $balance > 0 ? 'outstanding balance' : 'no balance due', $balance > 0 ? 'warn' : 'ok')
            ->financials($fin)
            ->brief($brief)
            ->timeline($this->timeline->forPhone($ctx, $phone))
            ->related([new Related('invoices', 'invoice', null, null, null, $invCount)])
            ->actions($brief->actions)
            ->meta(['capability' => 'customer', 'ttl' => 60, 'permissions' => ['menu:customers']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return match ($action) {
            'record_payment' => $this->recordPayment($ctx, $input),
            'send_reminder' => ['ok' => true, 'sent' => true],
            default => ['ok' => false, 'error' => 'unknown_action:' . $action],
        };
    }

    /** Delegates to the frozen engine. No model writes, no business logic here. */
    private function recordPayment(CapabilityContext $ctx, array $in): array
    {
        $c = CustomerProfile::where('tenant_id', $ctx->tenantId())->findOrFail((int) ($in['customer_id'] ?? 0));
        $cur = strtoupper((string) ($in['currency'] ?? 'USD'));
        $amount = round((float) ($in['amount'] ?? 0), 2);
        $fx = Fx::forTenant($ctx->tenant);
        $rate = $fx->ratePerUsd($cur);
        $usd = $fx->toUsd($amount, $cur);

        return app(PaymentService::class)->record($ctx->tenant, [
            'customer' => (string) $c->name,
            'phone' => (string) $c->phone,
            'invoice_no' => $in['invoice_no'] ?? null,
            'order_no' => $in['order_no'] ?? null,
            'kind' => 'settlement',
            'currency' => $cur,
            'amount' => $amount,
            'rate' => $rate,
            'usd' => $usd,
            'method' => (string) ($in['method'] ?? 'cash'),
            'reference' => $in['reference'] ?? null,
            'holder' => (string) ($ctx->user->name ?? 'Owner'),
            'holder_user_id' => (int) $ctx->user->id,
            'created_by' => (string) ($ctx->user->name ?? 'Owner'),
        ]);
    }
}
