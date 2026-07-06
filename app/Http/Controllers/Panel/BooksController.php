<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Ledger\StatementMessenger;
use CloudBSS\Infra\Reporting\Aging;
use CloudBSS\Infra\Reporting\TrialBalance;
use CloudBSS\Kernel\Money\Currency;
use Illuminate\Http\Request;

/**
 * Books — the seller-panel accounting dashboard, wired the same way the rest of the panel is:
 * page() serves a brandized HTML asset; the JSON endpoints (data/enable/statement) feed the SPA.
 * All reads come from the CloudBSS ledger projection. Tenant-scoped via $request->user()->tenant.
 */
class BooksController extends Controller
{
    /** GET /panel/books — the page shell. */
    public function page(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/books.html');
        if (! is_file($path)) {
            abort(500, 'Books asset missing.');
        }
        // brandize() is protected on SellerPanelController; replicate its minimal contract here by
        // delegating through a fresh instance is overkill — the page fetches data via papi anyway,
        // so we only need the shell served. Keep tenant name substitution simple.
        $html = file_get_contents($path);
        $html = str_replace('{{SHOP_NAME}}', e($user->tenant->name ?? 'My Shop'), $html);
        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** GET /panel/papi/books — trial balance, P&L, aging as JSON. */
    public function data(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'no_tenant'], 401);
        }
        if (! (bool) $t->setting('ledger_enabled', false)) {
            return response()->json(['ok' => true, 'enabled' => false]);
        }

        [$tid, $eid, $cur, $fmt] = $this->scope($t);

        try {
            $tb = app(TrialBalance::class);
            $ag = app(Aging::class);
            $trial = $tb->trialBalance($tid, $eid, $cur);
            $pl    = $tb->profitAndLoss($tid, $eid, $cur);

            $mapAging = fn (array $list) => array_map(fn ($p) => [
                'party' => $p['party'], 'name' => $p['name'] ?: $p['party'],
                'current' => $fmt($p['current']), 'd1_30' => $fmt($p['d1_30']),
                'd31_60' => $fmt($p['d31_60']), 'd61_90' => $fmt($p['d61_90']),
                'd90_plus' => $fmt($p['d90_plus']), 'total' => $fmt($p['total']),
            ], $list);

            return response()->json([
                'ok' => true, 'enabled' => true, 'currency' => $cur,
                'net_profit' => $fmt($pl['net_profit']), 'net_raw' => $pl['net_profit'],
                'income' => $fmt($pl['income']), 'expense' => $fmt($pl['expense']),
                'balanced' => $trial['balanced'],
                'total_debit' => $fmt($trial['total_debit']), 'total_credit' => $fmt($trial['total_credit']),
                'receivables' => $fmt($ag->totalOutstanding($tid, $eid, 'AR', $cur)),
                'payables' => $fmt($ag->totalOutstanding($tid, $eid, 'AP', $cur)),
                'trial' => array_map(fn ($r) => [
                    'account' => $r['account'], 'name' => $r['name'], 'type' => $r['type'],
                    'debit' => $r['debit'] ? $fmt($r['debit']) : '', 'credit' => $r['credit'] ? $fmt($r['credit']) : '',
                ], $trial['rows']),
                'debtors' => $mapAging($ag->byParty($tid, $eid, 'AR', $cur)),
                'creditors' => $mapAging($ag->byParty($tid, $eid, 'AP', $cur)),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'enabled' => true, 'error' => $e->getMessage()]);
        }
    }

    /** GET|POST /panel/papi/books/enable — turn books on and set VAT/terms. */
    public function enable(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'no_tenant'], 401);
        }
        $t->putSetting('ledger_enabled', filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL));
        if ($request->filled('vat')) {
            $t->putSetting('prices_include_vat', true);
            $t->putSetting('quote_vat_pct', (float) $request->input('vat'));
        }
        if ($request->filled('terms')) {
            $t->putSetting('credit_terms_days', (int) $request->input('terms'));
        }
        return response()->json(['ok' => true, 'enabled' => (bool) $t->setting('ledger_enabled', false)]);
    }

    /** GET|POST /panel/papi/books/statement — WhatsApp a debtor their statement. */
    public function statement(Request $request)
    {
        $t = $request->user()->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'no_tenant'], 401);
        }
        $party = (string) $request->input('party', '');
        if ($party === '') {
            return response()->json(['ok' => false, 'error' => 'no_party']);
        }
        $res = app(StatementMessenger::class)->send($t, $party);
        return response()->json(['ok' => (bool) ($res['ok'] ?? false), 'reason' => $res['reason'] ?? null]);
    }

    /** @return array{0:string,1:string,2:string,3:callable} tenantId, entityId, currency, money-formatter */
    private function scope($t): array
    {
        $tid = (string) $t->setting('ledger_tenant', 't_' . $t->id);
        $eid = (string) $t->setting('ledger_entity', 'e1');
        $cur = (string) $t->setting('currency', 'UGX');
        try { $factor = Currency::of($cur)->subunit(); } catch (\Throwable $e) { $factor = 1; }
        $dec = $factor > 1 ? (int) round(log10($factor)) : 0;
        $fmt = fn (int $minor) => number_format($minor / $factor, $dec);
        return [$tid, $eid, $cur, $fmt];
    }
}
