<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\WwCustomer;
use App\Models\WwException;
use App\Models\WwSalesOrder;
use App\Services\Winworld\ExceptionFlow;
use Illuminate\Http\Request;

/**
 * SOP exceptions: complaints, goods returns, credit/debit notes — each with
 * its owner role, SLA clock and approval chain (incl. the goods-return MD gate).
 */
class WinworldExceptionController extends Controller
{
    public function exceptionsPage(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/exceptions.html');
        if (! is_file($path)) abort(500, 'Exceptions asset missing.');
        $name = (string) ($u->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    public function options(Request $r)
    {
        $types = [];
        foreach (ExceptionFlow::TYPES as $k => $def) {
            $types[] = ['key' => $k, 'label' => $def[0], 'role' => $def[1]];
        }
        return response()->json([
            'ok' => true,
            'types'     => $types,
            'customers' => WwCustomer::orderBy('name')->get(['id','name','contact']),
            'orders'    => WwSalesOrder::orderByDesc('id')->limit(100)->get(['id','order_no','customer_name']),
            'return_md_limit' => ExceptionFlow::RETURN_MD_LIMIT,
        ]);
    }

    public function list(Request $r)
    {
        $rows = WwException::orderByDesc('id')->limit(200)->get()->map(function (WwException $e) {
            $done = $e->approvalsDone();
            $need = ExceptionFlow::approvalsFor($e->type, (float) $e->amount);
            return [
                'id'             => $e->id,
                'ref'            => $e->ref,
                'type'           => $e->type,
                'type_label'     => ExceptionFlow::label($e->type),
                'customer'       => $e->customer_name,
                'subject'        => $e->subject,
                'amount'         => (float) $e->amount,
                'status'         => $e->status,
                'owner_role'     => $e->owner_role,
                'sla_due_at'     => optional($e->sla_due_at)->format('Y-m-d H:i'),
                'sla_status'     => $e->slaStatus(),
                'approvals_need' => array_values(array_diff($need, $done)),
                'approvals_done' => $done,
                'can_resolve'    => $e->canResolve(),
                'resolution'     => $e->resolution,
            ];
        });
        return response()->json(['ok' => true, 'exceptions' => $rows]);
    }

    public function save(Request $r)
    {
        $type = (string) $r->input('type');
        if (! ExceptionFlow::isType($type)) return response()->json(['ok' => false, 'error' => 'Bad type'], 422);

        $e = new WwException();
        $e->fill([
            'type'           => $type,
            'customer_id'    => $r->input('customer_id') ?: null,
            'customer_name'  => (string) $r->input('customer_name', ''),
            'sales_order_id' => $r->input('sales_order_id') ?: null,
            'subject'        => (string) $r->input('subject', ''),
            'amount'         => (float) $r->input('amount', 0),
            'status'         => 'open',
        ]);
        $e->ref = 'EXC' . str_pad((string) (WwException::count() + 1), 4, '0', STR_PAD_LEFT);
        $e->stage_started_at = now();
        $e->applySla();
        $e->save();
        return response()->json(['ok' => true, 'id' => $e->id, 'ref' => $e->ref]);
    }

    public function approve(Request $r)
    {
        $e = WwException::find((int) $r->input('id'));
        if (! $e) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        $role = (string) $r->input('role');
        $need = ExceptionFlow::approvalsFor($e->type, (float) $e->amount);
        if (! in_array($role, $need, true)) {
            return response()->json(['ok' => false, 'error' => 'No ' . strtoupper($role) . ' approval needed'], 422);
        }
        $by = (string) ($r->user()->name ?? 'Staff');
        if ($role === 'sm') { $e->sm_by = $by; $e->sm_at = now(); }
        if ($role === 'md') { $e->md_by = $by; $e->md_at = now(); }
        if ($e->canResolve() && $e->status === 'open') $e->status = 'approved';
        $e->save();
        return response()->json(['ok' => true, 'can_resolve' => $e->canResolve(), 'approvals_done' => $e->approvalsDone()]);
    }

    public function resolve(Request $r)
    {
        $e = WwException::find((int) $r->input('id'));
        if (! $e) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if (! $e->canResolve()) {
            $need = array_diff(ExceptionFlow::approvalsFor($e->type, (float) $e->amount), $e->approvalsDone());
            return response()->json(['ok' => false, 'error' => 'Needs approval: ' . implode(', ', $need)], 422);
        }
        $e->status = 'resolved';
        $e->resolution = (string) $r->input('resolution', $e->resolution);
        $e->save();
        return response()->json(['ok' => true, 'status' => $e->status]);
    }

    public function action(Request $r)
    {
        $e = WwException::find((int) $r->input('id'));
        if (! $e) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        $a = (string) $r->input('action');
        if ($a === 'reject')  $e->status = 'rejected';
        elseif ($a === 'reopen') $e->status = 'open';
        else return response()->json(['ok' => false, 'error' => 'Bad action'], 422);
        if ($r->filled('note')) $e->resolution = (string) $r->input('note');
        $e->save();
        return response()->json(['ok' => true, 'status' => $e->status]);
    }
}
