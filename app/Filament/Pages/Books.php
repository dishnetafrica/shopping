<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Support\TenantContext;
use CloudBSS\Infra\Reporting\Aging;
use CloudBSS\Infra\Reporting\TrialBalance;
use CloudBSS\Kernel\Money\Currency;
use Filament\Pages\Page;

/**
 * Read-only accounting dashboard for the current tenant: trial balance, P&L, and debtor/creditor
 * aging — straight from the CloudBSS ledger projection (cheap queries, no log replay). Shows a
 * friendly prompt if the tenant hasn't turned automatic books on yet.
 */
class Books extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-book-open';
    protected static ?string $navigationLabel = 'Books';
    protected static ?string $title           = 'Books';
    protected static ?int $navigationSort     = 8;
    protected static string $view             = 'filament.pages.books';

    protected function tenant(): Tenant
    {
        return Tenant::findOrFail(app(TenantContext::class)->id());
    }

    /** Livewire action: send a debtor their statement over WhatsApp (button on the Debtors table). */
    public function sendStatement(string $party): void
    {
        try {
            $res = app(\App\Services\Ledger\StatementMessenger::class)->send($this->tenant(), $party);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'reason' => 'error'];
        }

        if ($res['ok'] ?? false) {
            \Filament\Notifications\Notification::make()
                ->title('Statement sent on WhatsApp')->success()->send();
            return;
        }

        $why = match ($res['reason'] ?? '') {
            'nothing_owed' => 'That customer has nothing outstanding.',
            'no_phone'     => 'No valid WhatsApp number for that customer.',
            'send_failed'  => 'WhatsApp send failed — check the connection.',
            default        => 'Could not send the statement.',
        };
        \Filament\Notifications\Notification::make()->title($why)->warning()->send();
    }

    protected function getViewData(): array
    {
        $t   = $this->tenant();
        $on  = (bool) $t->setting('ledger_enabled', false);
        if (! $on) {
            return ['enabled' => false];
        }

        $tenantId = (string) $t->setting('ledger_tenant', 't_' . $t->id);
        $entityId = (string) $t->setting('ledger_entity', 'e1');
        $cur      = (string) $t->setting('currency', 'UGX');

        try {
            $factor = Currency::of($cur)->subunit();
        } catch (\Throwable $e) {
            $factor = 1;
        }
        $dec = $factor > 1 ? (int) round(log10($factor)) : 0;
        $fmt = fn (int $minor) => number_format($minor / $factor, $dec);

        try {
            $tb  = app(TrialBalance::class);
            $ag  = app(Aging::class);

            $trial = $tb->trialBalance($tenantId, $entityId, $cur);
            $pl    = $tb->profitAndLoss($tenantId, $entityId, $cur);
            $bs    = $tb->balanceSheetTotals($tenantId, $entityId, $cur);

            $rows = array_map(fn ($r) => [
                'account' => $r['account'], 'name' => $r['name'], 'type' => $r['type'],
                'debit'   => $r['debit']  ? $fmt($r['debit'])  : '',
                'credit'  => $r['credit'] ? $fmt($r['credit']) : '',
            ], $trial['rows']);

            $mapAging = fn (array $list) => array_map(fn ($p) => [
                'party'    => $p['party'],
                'name'     => $p['name'] ?: $p['party'],
                'current'  => $fmt($p['current']),
                'd1_30'    => $fmt($p['d1_30']),
                'd31_60'   => $fmt($p['d31_60']),
                'd61_90'   => $fmt($p['d61_90']),
                'd90_plus' => $fmt($p['d90_plus']),
                'total'    => $fmt($p['total']),
            ], $list);

            return [
                'enabled'   => true,
                'currency'  => $cur,
                'trial'     => ['rows' => $rows, 'debit' => $fmt($trial['total_debit']),
                                'credit' => $fmt($trial['total_credit']), 'balanced' => $trial['balanced']],
                'pl'        => ['income' => $fmt($pl['income']), 'expense' => $fmt($pl['expense']),
                                'net' => $fmt($pl['net_profit']), 'net_raw' => $pl['net_profit']],
                'bs'        => ['asset' => $fmt($bs['asset']), 'liability' => $fmt($bs['liability']), 'equity' => $fmt($bs['equity'])],
                'debtors'   => $mapAging($ag->byParty($tenantId, $entityId, 'AR', $cur)),
                'creditors' => $mapAging($ag->byParty($tenantId, $entityId, 'AP', $cur)),
                'receivables' => $fmt($ag->totalOutstanding($tenantId, $entityId, 'AR', $cur)),
                'payables'    => $fmt($ag->totalOutstanding($tenantId, $entityId, 'AP', $cur)),
            ];
        } catch (\Throwable $e) {
            return ['enabled' => true, 'error' => $e->getMessage()];
        }
    }
}
