<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Money\Fx;
use App\Services\Money\ReportingCurrency;
use App\Services\Settings\SettingsWriteService;

/**
 * Currency & Reporting settings — the one screen where the shop chooses how money is shown across
 * every report in v3. Two decisions live here:
 *
 *   • Base currency        — the home currency the platform consolidates into (USD/UGX/SSP).
 *   • Report currency mode — Consolidated (convert every transaction to base) or
 *                            Original currency (show each transaction in the currency it happened in).
 *
 * Reads/writes only the tenant's own presentation settings (via SettingsWriteService); it does not
 * touch the trading engine, so it is safe over the frozen core.
 */
final class SettingsCapability implements ProcessCapability
{
    public function key(): string { return 'settings'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $base = $ctx->baseCurrency();
        $mode = $ctx->reportMode();
        $rc   = ReportingCurrency::forContext($ctx);
        $fx   = Fx::forTenant($ctx->tenant);

        $modeLabel = $mode === 'original' ? 'Original transaction currency' : 'Consolidated to base';

        $settingsCard = ['kind' => 'dl', 'title' => 'Currency & reporting', 'rows' => [
            ['Base currency', $base],
            ['Report mode', $modeLabel],
            ['Reports show', $rc->isOriginal() ? 'Each transaction in its own currency' : ('All figures in ' . $rc->consolidationCurrency())],
            ['KPI tiles', 'Always consolidated to ' . $rc->consolidationCurrency()],
        ]];

        // Transparency: the exchange rates the engine will use (native per 1 USD, today's effective row).
        $ratesCard = ['kind' => 'dl', 'title' => 'Exchange rates in use (per 1 USD)', 'rows' => [
            ['UGX', number_format($fx->ratePerUsd('UGX'))],
            ['SSP', number_format($fx->ratePerUsd('SSP'))],
            ['Basis', 'Historical: each transaction keeps its own stored rate'],
        ]];

        $help = ['kind' => 'links', 'title' => 'How this works', 'items' => [
            ['icon' => 'dollar', 'color' => '#16a34a', 'title' => 'Consolidated mode', 'sub' => 'Every quote, order, invoice and payment is converted to your base currency so totals add up to one number.', 'right' => ''],
            ['icon' => 'tag', 'color' => '#2563eb', 'title' => 'Original mode', 'sub' => 'Each figure stays in the currency it happened in. KPI tiles still consolidate so they can total.', 'right' => ''],
            ['icon' => 'book', 'color' => '#d97706', 'title' => 'Historical rates', 'sub' => 'Past documents keep the rate stored on them — they never re-price at today’s rate.', 'right' => ''],
        ]];

        $changeAction = [
            'key' => 'save_settings', 'label' => 'Change settings', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/settings/_/save_settings', 'actor' => 'human', 'tone' => 'primary',
            'schema' => [
                'base_currency' => 'enum:' . implode(',', SettingsWriteService::BASE_CHOICES),
                'report_currency_mode' => 'enum:base,original',
            ],
        ];
        $toConsolidated = ['key' => 'mode_base', 'label' => 'Use consolidated', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/settings/_/mode_base', 'actor' => 'human', 'tone' => 'default'];
        $toOriginal = ['key' => 'mode_original', 'label' => 'Use original currency', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/settings/_/mode_original', 'actor' => 'human', 'tone' => 'default'];

        $brief = new Brief(
            level: 'low',
            situation: 'Money across v3 is shown in ' . ($rc->isOriginal() ? 'each transaction’s own currency' : ($base . ' (consolidated)')) . '.',
            why: 'Change the base currency or switch between consolidated and original-currency reporting. Every dashboard and report follows this setting.',
            risk: ['tone' => 'info', 'label' => $modeLabel],
        );

        return (new WorkspacePayload)
            ->object(['type' => 'process:settings', 'title' => 'Currency & Reporting', 'subtitle' => 'How money is displayed across every v3 report'])
            ->headline($base, $rc->isOriginal() ? 'original currency' : 'base currency', 'ok')
            ->brief($brief)
            ->financials([
                ['key' => 'base', 'label' => 'Base currency', 'value' => $base, 'tone' => 'ok'],
                ['key' => 'mode', 'label' => 'Report mode', 'value' => $rc->isOriginal() ? 'Original' : 'Consolidated'],
                ['key' => 'shows', 'label' => 'Figures shown in', 'value' => $rc->consolidationCurrency()],
            ])
            ->quickActions([$changeAction, $mode === 'original' ? $toConsolidated : $toOriginal])
            ->overviewCards([$settingsCard, $ratesCard, $help])
            ->tabs([['key' => 'overview', 'label' => 'Overview']])
            ->actions([$changeAction, $mode === 'original' ? $toConsolidated : $toOriginal])
            ->more([$mode === 'original' ? $toConsolidated : $toOriginal])
            ->meta(['capability' => 'settings', 'ttl' => 30, 'layout' => 'record', 'listKey' => 'settings', 'permissions' => []]);
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
