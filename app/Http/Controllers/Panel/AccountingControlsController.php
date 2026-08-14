<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OrderCost;
use App\Services\AccountingGuard;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountingControlsController extends Controller
{
    private function owner($u): bool
    {
        return $u && ($u->isOwnerLike() || in_array((string)$u->role, ['manager'], true));
    }

    /** GET /panel/audit — the Accounting Controls screen (owner/manager only). */
    public function page(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        if (! (bool) $u->tenant->setting('order_system_enabled', false)) abort(404);
        if (! $this->owner($u)) abort(403);   // audit trail is sensitive — admins only
        $path = resource_path('panel/audit.html');
        if (! is_file($path)) abort(500, 'Audit asset missing.');
        return response(file_get_contents($path), 200)
            ->header('Content-Type','text/html; charset=UTF-8')->header('Cache-Control','no-store');
    }

    /** GET /papi/audit-log — paginated immutable audit trail (owner/manager only). */
    public function auditList(Request $request)
    {
        $u = $request->user(); $t = $u->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->owner($u)) return response()->json(['ok'=>false,'error'=>'forbidden'],403);
        $per  = min(100, max(10, (int)$request->input('per', 30)));
        $page = max(1, (int)$request->input('page', 1));
        $q = AuditLog::where('tenant_id',$t->id);
        if ($request->filled('action')) $q->where('action',$request->input('action'));
        if ($request->filled('entity')) $q->where('entity_type',$request->input('entity'));
        if ($request->filled('actor'))  $q->where('actor','like','%'.$request->input('actor').'%');
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page,$per)->get();
        return response()->json(['ok'=>true,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$per),
            'logs'=>$rows->map(fn($r)=>[
                'id'=>$r->id,'when'=>optional($r->created_at)->format('d M Y H:i'),
                'entity'=>$r->entity_type,'entity_id'=>$r->entity_id,'action'=>$r->action,
                'actor'=>$r->actor,'reason'=>$r->reason,
                'ip'=>$r->ip_address,'request_id'=>$r->request_id,
                'old'=>$r->old_values,'new'=>$r->new_values,
            ])]);
    }

    /** GET /papi/period-lock — current lock date (owner/manager only). */
    public function periodStatus(Request $request)
    {
        $u = $request->user(); $t = $u->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->owner($u)) return response()->json(['ok'=>false,'error'=>'forbidden'],403);
        return response()->json(['ok'=>true,'lock_date'=>AccountingGuard::lockDate($t->id)]);
    }

    /** POST /papi/period-lock — set/clear the lock (owner/manager only; audited). body: date=YYYY-MM-DD | clear=1 */
    public function periodLock(Request $request)
    {
        $u = $request->user(); $t = $u->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->owner($u)) return response()->json(['ok'=>false,'error'=>'forbidden'],403);
        $prev = AccountingGuard::lockDate($t->id);
        $s = $t->settings ?? [];
        if ($request->boolean('clear')) {
            unset($s['period_lock_date']);
            $t->settings = $s; $t->save();
            AuditLogger::log($t->id,'period',null,'unlock',$u,['lock_date'=>$prev],['lock_date'=>null],trim((string)$request->input('reason','')) ?: null);
            return response()->json(['ok'=>true,'lock_date'=>null]);
        }
        $date = trim((string)$request->input('date',''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return response()->json(['ok'=>false,'error'=>'bad_date'],422);
        $s['period_lock_date'] = $date;
        $t->settings = $s; $t->save();
        AuditLogger::log($t->id,'period',null,'lock',$u,['lock_date'=>$prev],['lock_date'=>$date],trim((string)$request->input('reason','')) ?: null);
        return response()->json(['ok'=>true,'lock_date'=>$date]);
    }

    /** GET /papi/integrity-check — verify the books and report inconsistencies (owner/manager only). */
    public function integrity(Request $request)
    {
        $u = $request->user(); $t = $u->tenant;
        if (! $t) return response()->json(['ok'=>false],401);
        if (! $this->owner($u)) return response()->json(['ok'=>false,'error'=>'forbidden'],403);
        $tid = $t->id;
        $issues = []; $checks = [];

        // 1) Ledger journals balance (debits == credits) — only if the ledger tables exist.
        if (Schema::hasTable('ledger_journals') && Schema::hasTable('ledger_lines')) {
            try {
                // v5.05 — `->having('diff','!=',0)` referenced the SELECT alias, which PostgreSQL
                // rejects in HAVING ("column \"diff\" does not exist"). The catch below then
                // recorded the control as ok=true/'skipped'. So the check that asks whether the
                // books balance has been reporting PASS without ever running. Same defect as the
                // quotations migration, and this is the expensive one: the alias is repeated in
                // the HAVING because that is what Postgres requires.
                $unbal = DB::table('ledger_lines')
                    ->select('journal_id', DB::raw('ROUND(SUM(debit_usd)-SUM(credit_usd),2) as diff'))
                    ->where('tenant_id',$tid)->groupBy('journal_id')
                    ->havingRaw('ROUND(SUM(debit_usd)-SUM(credit_usd),2) <> 0')->get();
                $checks[] = ['name'=>'Ledger journals balanced','ok'=>$unbal->count()===0,'detail'=>$unbal->count().' unbalanced'];
                foreach ($unbal as $j) $issues[] = "Journal {$j->journal_id} is out of balance by {$j->diff}";
            } catch (\Throwable $e) {
                // A control that could not run has NOT passed. Saying otherwise is how this hid.
                $checks[] = ['name'=>'Ledger journals balanced','ok'=>false,'detail'=>'could not run: '.$e->getMessage()];
                $issues[] = 'Ledger balance check could not run — this control is not proving anything.';
            }
        }

        // 2) Approved cash entries must have a positive amount + a currency.
        $badAmt = OrderCost::where('tenant_id',$tid)->where('status','approved')
            ->where(fn($w)=>$w->whereNull('currency')->orWhere('native_amount','<',0))->count();
        $checks[] = ['name'=>'Approved entries valid (currency + amount)','ok'=>$badAmt===0,'detail'=>$badAmt.' bad'];
        if ($badAmt) $issues[] = "$badAmt approved entries have no currency or a negative amount";

        // 3) Voided rows must be excluded from balances (status != approved) — consistency.
        $voidedButApproved = OrderCost::where('tenant_id',$tid)->whereNotNull('voided_at')->where('status','approved')->count();
        $checks[] = ['name'=>'Voided rows excluded from balances','ok'=>$voidedButApproved===0,'detail'=>$voidedButApproved.' leaking'];
        if ($voidedButApproved) $issues[] = "$voidedButApproved voided rows still marked approved (would double count)";

        // 4) Per-currency cash position snapshot (approved, non-void).
        $rows = OrderCost::where('tenant_id',$tid)->where('status','approved')
            ->where(fn($w)=>$w->where('pay_status','!=','credit')->orWhereNull('pay_status'))
            ->select('currency','stage', DB::raw('SUM(paid_native) as n'))->groupBy('currency','stage')->get();
        $bal = [];
        foreach ($rows as $r) {
            $sign = in_array($r->stage,['funding','cash_in','customer_payment'],true) ? 1 : -1;
            $bal[$r->currency] = ($bal[$r->currency] ?? 0) + $sign * (float)$r->n;
        }
        foreach ($bal as $cur=>$v) if ($v < -0.005) $issues[] = "$cur cash position is negative ($v)";

        return response()->json(['ok'=>true,'healthy'=>count($issues)===0,'checks'=>$checks,
            'issues'=>$issues,'balances'=>$bal,'lock_date'=>AccountingGuard::lockDate($tid),
            'ran_at'=>now()->format('d M Y H:i')]);
    }
}
