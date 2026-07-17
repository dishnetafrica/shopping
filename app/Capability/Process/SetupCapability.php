<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\Related;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Category;
use App\Models\CustomerPayment;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Supplier;

/**
 * "Get started" — the first screen a brand-new shop sees. An ordered, self-ticking setup checklist:
 * each step is done the moment the tenant has at least one of that record. Every step links straight
 * to where the work happens, so a new user just clicks down the list. Read-only.
 */
final class SetupCapability implements ProcessCapability
{
    public function key(): string { return 'setup'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $tenant = $ctx->tenant;
        $has = fn ($q) => $q->where('tenant_id', $t)->exists();

        // Foundational setup, tracked by settings rather than a record existing:
        $businessSet = (bool) $tenant->setting('onboarded', false) || trim((string) $tenant->setting('currency', '')) !== '';
        $waConnected = trim((string) $tenant->whatsapp_instance) !== '';

        $steps = [
            ['key' => 'settings', 'title' => 'Set up your business', 'desc' => 'Set your currency, exchange rates, invoice details and business profile.', 'done' => $businessSet, 'link' => '/api/v1/workspace/settings'],
            ['key' => 'whatsapp', 'title' => 'Connect WhatsApp', 'desc' => 'Link your WhatsApp number so customers can order and you get alerts — it also powers OTP login.', 'done' => $waConnected, 'link' => '/panel/setup'],
            ['key' => 'categories', 'title' => 'Create product categories', 'desc' => 'Group what you sell — e.g. Beverages, Hygiene, Staples.', 'done' => $has(Category::query())],
            ['key' => 'products',   'title' => 'Add your products',          'desc' => 'Add items with photos, price, cost, stock and MOQ — the bot uses these to sell.', 'done' => $has(Product::query())],
            ['key' => 'suppliers',  'title' => 'Add your suppliers',         'desc' => 'Who you buy stock from, so you can track what you owe.', 'done' => $has(Supplier::query())],
            ['key' => 'customers',  'title' => 'Add your customers',         'desc' => 'Who you sell to — business name, contact, segment and credit limit.', 'done' => $has(CustomerProfile::query())],
            ['key' => 'salesOrders', 'title' => 'Make your first sale',      'desc' => 'Create a quotation or a sales order for a customer.', 'done' => $has(Order::query()) || $has(Quotation::query())],
            ['key' => 'invoices',   'title' => 'Issue your first invoice',   'desc' => 'Bill a delivered order — an invoice is created automatically on delivery.', 'done' => $has(Invoice::query()->where('status', '!=', 'void'))],
            ['key' => 'payments',   'title' => 'Record your first payment',  'desc' => 'Log money received from a customer against their balance.', 'done' => $has(CustomerPayment::query())],
        ];

        $total = count($steps);
        $done = count(array_filter($steps, fn ($s) => $s['done']));
        $allDone = $done === $total;

        $related = array_map(fn ($s, $i) => new Related(
            rel: $s['done'] ? 'done' : 'todo',
            type: $s['key'],
            id: null,
            label: ($i + 1) . '. ' . $s['title'],
            sublabel: $s['desc'],
            count: null,
            link: $s['link'] ?? '/api/v1/workspace/' . $s['key'],
        ), $steps, array_keys($steps));

        // Mark done-ness on each related row (the shell renders a green check vs a "Start" chevron).
        $relatedArr = [];
        foreach ($related as $idx => $r) {
            $a = $r->toArray();
            $a['done'] = $steps[$idx]['done'];
            $relatedArr[] = $a;
        }

        $brief = new Brief(
            level: $allDone ? 'low' : 'med',
            situation: $allDone ? "You're all set — CloudBSS is ready to run your day." : 'Welcome to CloudBSS — let’s get your shop ready to sell.',
            why: $allDone ? 'Every setup step is complete. Open Morning Briefing to start your day.' : 'Work down the list below. Each step takes you straight to where the work happens.',
            risk: ['tone' => $allDone ? 'ok' : 'info', 'label' => $done . ' of ' . $total . ' done'],
        );

        return (new WorkspacePayload)
            ->object(['type' => 'process:setup', 'title' => 'Get started', 'subtitle' => 'Set up CloudBSS for your shop — just a few clicks'])
            ->headline($done . ' / ' . $total, 'setup steps done', $allDone ? 'ok' : 'warn')
            ->brief($brief)
            ->related($relatedArr)
            ->meta(['capability' => 'setup', 'ttl' => 30, 'setupComplete' => $allDone]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return [];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'no_command'];
    }
}
