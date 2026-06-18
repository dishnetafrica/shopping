<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\WwCustomer;
use App\Models\WwSalesEvent;
use App\Models\WwSalesOrder;
use App\Services\Winworld\SalesFlow;
use App\Services\Winworld\CustomerMessages;
use App\Models\Tenant;
use App\Jobs\NotifyOwner;
use Illuminate\Http\Request;

/**
 * Sales order workflow (SOP WW/SM/CRM/SOP/01): capture → credit check →
 * SAP/SM/MD approval → indent → delivery, each stage SLA-clocked with an
 * audit trail. Tenant scoping automatic; writes via POST (papi/* CSRF-exempt).
 */
class WinworldSalesController extends Controller
{
    public function salesPage(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/sales.html');
        if (! is_file($path)) abort(500, 'Sales asset missing.');
        $name = (string) ($u->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    public function options(Request $r)
    {
        $stages = [];
        foreach (SalesFlow::ORDER as $s) {
            $stages[] = ['key' => $s, 'label' => SalesFlow::label($s), 'role' => SalesFlow::role($s)];
        }
        return response()->json([
            'ok' => true,
            'customers' => WwCustomer::orderBy('name')->get(['id','name','contact','overdue_days']),
            'stages'    => $stages,
        ]);
    }

    public function list(Request $r)
    {
        $orders = WwSalesOrder::orderByDesc('id')->limit(200)->get();
        $rows = $orders->map(function (WwSalesOrder $o) {
            $done = $o->approvalsDone();
            $need = SalesFlow::approvalsFor($o->stage, (int) $o->overdue_days);
            return [
                'id'             => $o->id,
                'order_no'       => $o->order_no,
                'customer'       => $o->customer_name,
                'product'        => $o->product_name,
                'qty'            => $o->qty,
                'value'          => (float) $o->value,
                'source'         => $o->source,
                'stage'          => $o->stage,
                'stage_label'    => SalesFlow::label($o->stage),
                'owner_role'     => $o->owner_role,
                'status'         => $o->status,
                'sla_due_at'     => optional($o->sla_due_at)->format('Y-m-d H:i'),
                'sla_status'     => $o->slaStatus(),
                'overdue_days'   => (int) $o->overdue_days,
                'approvals_need' => array_values(array_diff($need, $done)),
                'approvals_done' => $done,
                'can_advance'    => $o->canAdvance(),
                'next_label'     => SalesFlow::next($o->stage) ? SalesFlow::label(SalesFlow::next($o->stage)) : null,
                'evidence'       => $o->evidence,
                'indent_id'      => $o->indent_id,
            ];
        });
        return response()->json(['ok' => true, 'orders' => $rows]);
    }

    public function save(Request $r)
    {
        $id = (int) $r->input('id', 0);
        $o = $id ? WwSalesOrder::find($id) : new WwSalesOrder();
        if (! $o) return response()->json(['ok' => false, 'error' => 'Not found'], 404);

        $new = ! $o->exists;
        $o->fill([
            'customer_id'  => $r->input('customer_id') ?: null,
            'customer_name'=> (string) $r->input('customer_name', $o->customer_name ?? ''),
            'contact'      => $r->input('contact'),
            'source'       => $r->input('source'),
            'product_name' => $r->input('product_name'),
            'qty'          => (int) $r->input('qty', $o->qty ?? 0),
            'value'        => (float) $r->input('value', $o->value ?? 0),
            'evidence'     => $r->input('evidence'),
            'assigned_to'  => $r->input('assigned_to'),
        ]);
        if ($new) {
            $o->order_no = 'SO' . str_pad((string) (WwSalesOrder::count() + 1), 4, '0', STR_PAD_LEFT);
            $o->stage = 'enquiry';
            $o->status = 'open';
            $o->applyStage('enquiry');
        }
        $o->save();
        if ($new) $this->log($o, 'capture', null, $r, 'Order captured');

        return response()->json(['ok' => true, 'id' => $o->id, 'order_no' => $o->order_no]);
    }

    public function advance(Request $r)
    {
        $o = WwSalesOrder::find((int) $r->input('id'));
        if (! $o) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        if ($o->status !== 'open') return response()->json(['ok' => false, 'error' => 'Order is ' . $o->status], 422);
        if (! $o->canAdvance()) {
            $need = array_diff(SalesFlow::approvalsFor($o->stage, (int) $o->overdue_days), $o->approvalsDone());
            return response()->json(['ok' => false, 'error' => 'Needs approval: ' . implode(', ', $need)], 422);
        }
        $next = SalesFlow::next($o->stage);
        if (! $next) return response()->json(['ok' => false, 'error' => 'Last stage — mark Won instead'], 422);

        if ($next === 'credit_check' && $o->customer_id) {
            $cust = WwCustomer::find($o->customer_id);
            if ($cust) $o->overdue_days = (int) $cust->overdue_days;   // snapshot for the MD gate
        }
        $o->applyStage($next);
        $o->save();
        $this->log($o, 'advance', null, $r, 'Advanced to ' . SalesFlow::label($next));
        if ($ev = CustomerMessages::eventForStage($next)) $this->notifyCustomer($o, $ev);
        return response()->json(['ok' => true]);
    }

    public function approve(Request $r)
    {
        $o = WwSalesOrder::find((int) $r->input('id'));
        if (! $o) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        $role = (string) $r->input('role');
        $allowed = SalesFlow::approvalsFor($o->stage, (int) $o->overdue_days);
        if (! in_array($role, $allowed, true)) {
            return response()->json(['ok' => false, 'error' => 'No ' . strtoupper($role) . ' approval needed here'], 422);
        }
        $this->log($o, 'approve', $role, $r, strtoupper($role) . ' approved');
        return response()->json(['ok' => true, 'can_advance' => $o->canAdvance(), 'approvals_done' => $o->approvalsDone()]);
    }

    public function action(Request $r)
    {
        $o = WwSalesOrder::find((int) $r->input('id'));
        if (! $o) return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        $action = (string) $r->input('action');
        $map = ['won' => 'won', 'lost' => 'lost', 'hold' => 'on_hold', 'reopen' => 'open'];
        if (isset($map[$action])) { $o->status = $map[$action]; $o->save(); }
        if ($action === 'won') $this->notifyCustomer($o, 'delivered');
        elseif ($action !== 'remark') return response()->json(['ok' => false, 'error' => 'Bad action'], 422);
        $this->log($o, $action, null, $r, (string) $r->input('note', ''));
        return response()->json(['ok' => true, 'status' => $o->status]);
    }

    /** Send a customer-facing WhatsApp for a milestone, if enabled and a contact exists. */
    private function notifyCustomer(WwSalesOrder $o, string $event): void
    {
        $phone = trim((string) $o->contact);
        if ($phone === '') return;
        $t = $o->tenant ?? Tenant::find($o->tenant_id);
        if (! $t || ! (bool) $t->setting('ww_customer_msgs_enabled', true) || ! $t->whatsapp_instance) return;
        $tpl = $t->setting('ww_cust_' . $event);
        $text = CustomerMessages::render($event, [
            'order_no' => $o->order_no, 'customer' => $o->customer_name, 'product' => $o->product_name,
        ], $tpl);
        if ($text) NotifyOwner::dispatch($t->id, $text, $phone);
    }

    private function log(WwSalesOrder $o, string $action, ?string $role, Request $r, string $note = ''): void
    {
        WwSalesEvent::create([
            'sales_order_id' => $o->id,
            'stage'          => $o->stage,
            'action'         => $action,
            'role'           => $role,
            'actor'          => (string) ($r->user()->name ?? 'Staff'),
            'note'           => $note ?: ($r->input('note') ?: null),
            'at'             => now(),
        ]);
    }
}
