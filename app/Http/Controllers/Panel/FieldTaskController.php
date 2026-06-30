<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTask;
use App\Models\OrderTaskEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TaskEngine;
use App\Support\TenantContext;
use App\Support\WorkflowConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Field channel — a no-login surface for dispatched roles (drivers). Reached only via a
 * signed, expiring WhatsApp link; the link IS the auth (the DishNet model). This is a thin
 * adapter: it resolves the task + its owner and calls the SAME TaskEngine the panel uses,
 * so the engine never knows which channel completed the work.
 *
 * Link security: signed + 24h expiry (tamper/temporal), PLUS a per-offer nonce (`k`) checked
 * here — bumped on every (re)offer, so an old link dies the instant a task is reassigned.
 */
class FieldTaskController extends Controller
{
    public function __construct(private TaskEngine $engine) {}

    /** The field task page (signed). Read-only once the task is no longer active. */
    public function show(Request $r, int $task)
    {
        [$t, $o, $u, $err] = $this->resolve($task, $r->query('k'));
        if ($err) return response($this->stale($err), 410)->header('Content-Type', 'text/html; charset=UTF-8');

        // Audit: log the first open only (page reloads shouldn't spam the timeline).
        if (empty($o->opened_at)) {
            $o->opened_at = now(); $o->save();
            $this->event($t, $o, 'opened', 'Field link opened');
        }

        $path = resource_path('panel/field-task.html');
        if (! is_file($path)) abort(500, 'Field task asset missing.');
        $html = file_get_contents($path);

        $order   = Order::withoutGlobalScopes()->find($o->order_id);
        $active  = $o->isActive();
        $expired = $o->status === 'offered' && WorkflowConfig::isFieldStage($t, $o->stage) && $o->assigned_at
                   && $o->assigned_at->copy()->addMinutes(WorkflowConfig::offerTtl($t))->isPast();
        $payload = [
            'order_no'   => $order?->order_no ?: ('#' . $o->order_id),
            'customer'   => $order?->customer_name,
            'title'      => $o->title,
            'stage'      => WorkflowConfig::roleLabel($o->role),
            'status'     => $o->status,
            'offered'    => $o->status === 'offered',
            'expired'    => (bool) $expired,
            'active'     => $active,
            'overdue'    => $o->isOverdue(),
            'requires_upload' => (bool) $o->requires_upload,
            'attachment' => $o->attachment,
            'checklist'  => array_values((array) ($o->checklist ?? [])),
            'due'        => $o->due_at ? $o->due_at->format('M j, H:i') : '',
            'shop'       => $t->name,
        ];
        $k    = ['k' => $o->link_nonce];
        $urls = $active ? [
            'accept'    => URL::temporarySignedRoute('ops.field.accept',    now()->addHours(24), ['task' => $o->id] + $k),
            'complete'  => URL::temporarySignedRoute('ops.field.complete',  now()->addHours(24), ['task' => $o->id] + $k),
            'checklist' => URL::temporarySignedRoute('ops.field.checklist', now()->addHours(24), ['task' => $o->id] + $k),
            'comment'   => URL::temporarySignedRoute('ops.field.comment',   now()->addHours(24), ['task' => $o->id] + $k),
        ] : [];

        $inject = '<script>window.TASK=' . json_encode($payload, JSON_UNESCAPED_SLASHES)
                . ';window.URLS=' . json_encode($urls, JSON_UNESCAPED_SLASHES) . ';</script>';
        $html = preg_replace('#</head>#', $inject . '</head>', $html, 1);
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    public function accept(Request $r, int $task)
    {
        [$t, $o, $u, $err] = $this->resolve($task, $r->query('k'));
        if ($err) return response()->json(['ok' => false, 'error' => $err], 410);
        return response()->json($this->engine->accept($t, $o, $u ?: $this->ghost()));
    }

    public function checklist(Request $r, int $task)
    {
        [$t, $o, $u, $err] = $this->resolve($task, $r->query('k'));
        if ($err) return response()->json(['ok' => false, 'error' => $err], 410);
        $items = $r->input('checklist');
        $items = is_string($items) ? (json_decode($items, true) ?: []) : (array) $items;
        $cur = (array) ($o->checklist ?? []);
        foreach ($cur as $i => $row) if (isset($items[$i])) $cur[$i]['done'] = (bool) ($items[$i]['done'] ?? $items[$i] ?? false);
        $o->checklist = $cur; $o->save();
        return response()->json(['ok' => true]);
    }

    public function comment(Request $r, int $task)
    {
        [$t, $o, $u, $err] = $this->resolve($task, $r->query('k'));
        if ($err) return response()->json(['ok' => false, 'error' => $err], 410);
        return response()->json($this->engine->comment($t, $o, $u, (string) $r->input('text', '')));
    }

    public function complete(Request $r, int $task)
    {
        [$t, $o, $u, $err] = $this->resolve($task, $r->query('k'));
        if ($err) return response()->json(['ok' => false, 'error' => $err], 410);

        $opts = [];
        if ($r->filled('note')) $opts['note'] = (string) $r->input('note');
        if ($r->filled('checklist')) $this->checklist($r, $task);

        // Proof upload (base64) — stored publicly, same shape as the panel's upload-doc.
        $data = (string) $r->input('attachment_data', '');
        if ($data !== '') {
            if (str_contains($data, ',')) $data = substr($data, strpos($data, ',') + 1);
            $bin = base64_decode($data, true);
            if ($bin !== false && strlen($bin) <= 15 * 1024 * 1024) {
                $ext = strtolower(pathinfo((string) $r->input('attachment_name', 'proof.jpg'), PATHINFO_EXTENSION)) ?: 'jpg';
                if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                    $file = 'ops/' . $t->id . '/' . uniqid('proof_', true) . '.' . $ext;
                    Storage::disk('public')->put($file, $bin);
                    $opts['attachment'] = url(Storage::url($file));
                }
            }
        }
        return response()->json($this->engine->complete($t, $o, $u ?: $this->ghost(), $opts));
    }

    // ----------------------------------------------------------------- helpers

    /** Resolve [tenant, task, owner-user, error]; enforce the offer nonce. */
    private function resolve(int $taskId, ?string $nonce): array
    {
        $task = OrderTask::withoutGlobalScopes()->find($taskId);
        if (! $task) return [null, null, null, 'This task link is no longer valid.'];
        if ($task->link_nonce !== null && (string) $nonce !== (string) $task->link_nonce) {
            return [null, null, null, 'This task was reassigned — this link is no longer active.'];
        }
        $tenant = Tenant::find($task->tenant_id);
        if (! $tenant) return [null, null, null, 'This task link is no longer valid.'];
        app(TenantContext::class)->set($tenant->id);
        $owner = $task->owner_user_id ? User::find($task->owner_user_id) : null;
        return [$tenant, $task, $owner, null];
    }

    private function event(Tenant $t, OrderTask $task, string $event, string $detail): void
    {
        OrderTaskEvent::create([
            'tenant_id' => $t->id, 'order_id' => $task->order_id, 'task_id' => $task->id,
            'event' => $event, 'from_stage' => $task->stage, 'detail' => $detail, 'created_at' => now(),
        ]);
    }

    /** Stand-in actor when the owner record can't be loaded (engine needs a name only). */
    private function ghost(): User
    {
        $u = new User(); $u->name = 'Field';
        return $u;
    }

    private function stale(string $msg): string
    {
        $m = htmlspecialchars($msg, ENT_QUOTES);
        return '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'
             . '<div style="font-family:system-ui;max-width:420px;margin:60px auto;padding:24px;text-align:center;color:#1B211D">'
             . '<div style="font-size:30px">&#8987;</div><h2 style="font-weight:600">Link not active</h2>'
             . '<p style="color:#6B726C">' . $m . '</p></div>';
    }
}
