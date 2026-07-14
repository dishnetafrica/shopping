<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Models\User;
use App\Services\Money\Fx;
use App\Services\Money\ReportingCurrency;
use App\Services\Settings\SettingsWriteService;

/**
 * Settings — the one screen where the shop configures itself, mirroring the prototype's tabs:
 * Business profile, Currency & reporting, Exchange rates, and Invoice & tax. Each section shows the
 * current values and has an edit form. Everything is written through SettingsWriteService (tenant name
 * column + settings JSON), which the PDF renderers, Fx service and bot already read — no engine changes.
 */
final class SettingsCapability implements ProcessCapability
{
    public function key(): string { return 'settings'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenant;
        $s = fn (string $k, string $d = '') => trim((string) ($t->setting($k, $d) ?? $d));
        $name = (string) ($t->name ?: 'Your business');
        $base = $ctx->baseCurrency();
        $mode = $ctx->reportMode();
        $rc = ReportingCurrency::forContext($ctx);
        $fx = Fx::forTenant($t);
        $ugx = $fx->ratePerUsd('UGX');
        $ssp = $fx->ratePerUsd('SSP');
        $ep = '/api/v1/workspace/settings/_';
        $modeLabel = $mode === 'original' ? 'Original transaction currency' : 'Consolidated to base';
        $email = $s('public_email', $s('email'));
        $loc = trim(implode(', ', array_filter([$s('address'), $s('city'), $s('country')])));

        // --- edit forms (all post to save_settings → SettingsWriteService::apply) ---
        $editProfile = [
            'key' => 'edit_profile', 'label' => 'Edit business profile', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['business_name' => 'text', 'trading_as' => 'text', 'owner_name' => 'text', 'public_phone' => 'text', 'public_email' => 'text', 'address' => 'text', 'city' => 'text', 'country' => 'text', 'tin' => 'text', 'tagline' => 'text', 'logo' => 'text'],
            'prefill' => ['business_name' => $name, 'trading_as' => $s('trading_as'), 'owner_name' => $s('owner_name'), 'public_phone' => $s('public_phone'), 'public_email' => $email, 'address' => $s('address'), 'city' => $s('city'), 'country' => $s('country'), 'tin' => $s('tin'), 'tagline' => $s('tagline'), 'logo' => $s('logo')],
        ];
        $editCurrency = [
            'key' => 'edit_currency', 'label' => 'Currency & reporting', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['base_currency' => 'enum:' . implode(',', SettingsWriteService::BASE_CHOICES), 'report_currency_mode' => 'enum:base,original'],
            'prefill' => ['base_currency' => $base, 'report_currency_mode' => $mode],
        ];
        $editRates = [
            'key' => 'edit_rates', 'label' => 'Exchange rates', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['rate_ugx_per_usd' => 'number', 'rate_ssp_per_usd' => 'number'],
            'prefill' => ['rate_ugx_per_usd' => (int) round($ugx), 'rate_ssp_per_usd' => (int) round($ssp)],
        ];
        $editInvoicing = [
            'key' => 'edit_invoicing', 'label' => 'Invoice & tax', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['invoice_title' => 'text', 'invoice_terms_days' => 'number', 'vat_rate' => 'number', 'bank_details' => 'text', 'invoice_footer' => 'text'],
            'prefill' => ['invoice_title' => $s('invoice_title', 'INVOICE'), 'invoice_terms_days' => $s('invoice_terms_days', '0'), 'vat_rate' => $s('vat_rate', '0'), 'bank_details' => $s('bank_details'), 'invoice_footer' => $s('invoice_footer')],
        ];
        $editBot = [
            'key' => 'edit_bot', 'label' => 'WhatsApp bot', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['bot_greeting' => 'text', 'bot_fallback_text' => 'text', 'ai_persona' => 'text', 'business_hours' => 'text', 'delivery_areas' => 'text', 'bot_website_link' => 'text', 'faq' => 'text'],
            'prefill' => ['bot_greeting' => $s('bot_greeting'), 'bot_fallback_text' => $s('bot_fallback_text'), 'ai_persona' => $s('ai_persona'), 'business_hours' => $s('business_hours'), 'delivery_areas' => $s('delivery_areas'), 'bot_website_link' => $s('bot_website_link'), 'faq' => $s('faq')],
        ];

        // --- display cards ---
        $profileCard = ['kind' => 'dl', 'title' => 'Business profile', 'rows' => [
            ['Business name', $name],
            ['Trading as', $s('trading_as') ?: '—'],
            ['Owner', $s('owner_name') ?: '—'],
            ['Phone', $s('public_phone') ?: '—'],
            ['Email', $email ?: '—'],
            ['Address', $loc ?: '—'],
            ['TIN', $s('tin') ?: '—'],
        ]];
        $currencyCard = ['kind' => 'dl', 'title' => 'Currency & reporting', 'rows' => [
            ['Base currency', $base],
            ['Report mode', $modeLabel],
            ['Figures shown in', $rc->consolidationCurrency()],
            ['KPI tiles', 'Always consolidated to ' . $rc->consolidationCurrency()],
        ]];
        $ratesCard = ['kind' => 'dl', 'title' => 'Exchange rates (per 1 USD)', 'rows' => [
            ['UGX', number_format($ugx)],
            ['SSP', number_format($ssp)],
            ['Basis', 'Historical: each transaction keeps its own stored rate'],
        ]];
        $invoiceCard = ['kind' => 'dl', 'title' => 'Invoice & tax', 'rows' => [
            ['Invoice title', $s('invoice_title', 'INVOICE')],
            ['Payment terms', ($s('invoice_terms_days', '0') ?: '0') . ' days'],
            ['VAT rate', ($s('vat_rate', '0') ?: '0') . '%'],
            ['Bank details', $s('bank_details') ?: '—'],
        ]];
        // --- WhatsApp & bot ---
        $waStatus = $t->whatsapp_instance ? 'Connected' : 'Not connected';
        $connCard = ['kind' => 'dl', 'title' => 'WhatsApp connection', 'rows' => [
            ['Number', (string) ($t->whatsapp_number ?: '—')],
            ['Driver', (string) ($t->whatsapp_driver ?: '—')],
            ['Instance', (string) ($t->whatsapp_instance ?: '—')],
            ['Status', $waStatus],
        ]];
        $trunc = fn (string $v, int $n = 60) => $v === '' ? '—' : (mb_strlen($v) > $n ? mb_substr($v, 0, $n) . '…' : $v);
        $botCard = ['kind' => 'dl', 'title' => 'Bot messages', 'rows' => [
            ['Greeting', $trunc($s('bot_greeting'))],
            ['Fallback reply', $trunc($s('bot_fallback_text'))],
            ['AI persona', $trunc($s('ai_persona'))],
            ['Business hours', $s('business_hours') ?: '—'],
            ['Delivery areas', $trunc($s('delivery_areas'))],
            ['Website link', $s('bot_website_link') ?: '—'],
        ]];

        // --- team: list live members, add/remove via the panel's proven owner-gated staff endpoints ---
        $meId = (int) $ctx->user->id;
        $members = User::where('tenant_id', $t->id)->orderBy('name')->limit(50)->get();
        $teamRows = $members->map(function ($u) use ($meId) {
            $isMe = (int) $u->id === $meId;
            $btns = $isMe ? [] : [['icon' => 'trash', 'title' => 'Remove member', 'tone' => 'danger', 'post' => '/papi/staff/delete', 'body' => ['id' => (int) $u->id], 'confirm' => 'Remove ' . ($u->name ?: $u->email) . ' from the team? Their login is deleted.']];

            return ['cells' => [
                (string) ($u->name ?: $u->email ?: 'Member') . ($isMe ? ' (you)' : ''),
                ucfirst(str_replace('_', ' ', (string) ($u->role ?: 'user'))),
                (string) ($u->email ?: '—'),
                ['buttons' => $btns],
            ], 'link' => null];
        })->all();
        $teamCard = ['kind' => 'table', 'title' => 'Team members', 'sub' => $members->count() . ' total',
            'columns' => [['label' => 'Member'], ['label' => 'Role'], ['label' => 'Email'], ['label' => '', 'align' => 'right']],
            'rows' => $teamRows, 'empty' => 'No team members yet'];

        $addMember = [
            'key' => 'add_member', 'label' => 'Add member', 'method' => 'POST', 'endpoint' => '/papi/staff/add', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['name' => 'text', 'email' => 'text', 'phone' => 'text', 'role' => 'enum:sales,manager,staff,purchaser,product_staff,field', 'password' => 'text'],
            'prefill' => ['role' => 'sales'],
        ];
        $teamNote = ['kind' => 'dl', 'title' => 'About team logins', 'rows' => [
            ['Sign in', 'By email + password, or WhatsApp number (OTP)'],
            ['Requirements', 'Name, a valid email, and either a WhatsApp number or a 6+ character password'],
            ['Note', 'Only owners can add or remove members'],
        ]];

        $help = ['kind' => 'links', 'title' => 'How reporting currency works', 'items' => [
            ['icon' => 'dollar', 'color' => '#16a34a', 'title' => 'Consolidated', 'sub' => 'Every figure is converted to your base currency so totals add up.', 'right' => ''],
            ['icon' => 'tag', 'color' => '#2563eb', 'title' => 'Original', 'sub' => 'Each figure stays in its own currency; KPI tiles still consolidate.', 'right' => ''],
            ['icon' => 'book', 'color' => '#d97706', 'title' => 'Historical rates', 'sub' => 'Past documents keep the rate stored on them — never re-priced at today\'s rate.', 'right' => ''],
        ]];

        $brief = new Brief(
            level: 'low',
            situation: 'Configure ' . $name . ' — profile, currency, exchange rates and invoicing.',
            why: 'These settings flow into every document and report: quotations, invoices, receipts, the catalogue and price list all use them.',
            risk: ['tone' => 'info', 'label' => $base . ' · ' . ($mode === 'original' ? 'Original' : 'Consolidated')],
        );

        return (new WorkspacePayload)
            ->object(['type' => 'process:settings', 'title' => 'Settings', 'subtitle' => 'Manage your business, currency and invoicing'])
            ->headline($base, 'base currency', 'ok')
            ->brief($brief)
            ->financials([
                ['key' => 'base', 'label' => 'Base currency', 'value' => $base, 'tone' => 'ok'],
                ['key' => 'mode', 'label' => 'Report mode', 'value' => $mode === 'original' ? 'Original' : 'Consolidated'],
                ['key' => 'terms', 'label' => 'Payment terms', 'value' => ($s('invoice_terms_days', '0') ?: '0') . ' days'],
            ])
            ->quickActions([$editProfile, $editCurrency, $editRates, $editInvoicing, $editBot, $addMember])
            ->overviewCards([$profileCard, $currencyCard, $invoiceCard])
            ->tabSections([
                'business' => [$profileCard],
                'currency' => [$currencyCard, $ratesCard, $help],
                'invoicing' => [$invoiceCard],
                'whatsapp' => [$connCard, $botCard],
                'team' => [$teamCard, $teamNote],
            ])
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'business', 'label' => 'Business profile'],
                ['key' => 'currency', 'label' => 'Currency & rates'],
                ['key' => 'invoicing', 'label' => 'Invoice & tax'],
                ['key' => 'whatsapp', 'label' => 'WhatsApp & bot'],
                ['key' => 'team', 'label' => 'Team', 'count' => $members->count()],
            ])
            ->actions([$editProfile])
            ->more([$editCurrency, $editRates, $editInvoicing, $editBot, $addMember])
            ->meta(['capability' => 'settings', 'ttl' => 20, 'layout' => 'record', 'listKey' => 'settings', 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['base' => $ctx->baseCurrency(), 'mode' => $ctx->reportMode()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        $svc = app(SettingsWriteService::class);

        return match ($action) {
            'save_settings' => $svc->apply($ctx->tenant, $input),
            'mode_base' => $svc->apply($ctx->tenant, ['report_currency_mode' => 'base']),
            'mode_original' => $svc->apply($ctx->tenant, ['report_currency_mode' => 'original']),
            default => ['ok' => false, 'error' => 'unknown_action'],
        };
    }
}
