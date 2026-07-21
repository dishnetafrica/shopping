<?php

namespace App\Services\Ops;

use App\Models\Order;
use App\Models\OrderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Support\WorkflowConfig;
use Illuminate\Support\Carbon;

/**
 * Tasks register export (PDF + CSV) — the team's task/job list, in the same shape as the
 * V3 Tasks screen: task, client (order), assignee, due, duration, priority, status.
 * Filterable by due-date range, status and assignee. Read-only.
 */
final class TasksExport
{
    /**
     * @param array{from?:string,to?:string,status?:string,assignee_id?:int} $f
     */
    public function collect(Tenant $t, array $f = []): array
    {
        $q = OrderTask::query()->where('tenant_id', $t->id);

        $from = trim((string) ($f['from'] ?? ''));
        $to = trim((string) ($f['to'] ?? ''));
        $status = strtolower(trim((string) ($f['status'] ?? '')));
        $assigneeId = (int) ($f['assignee_id'] ?? 0);

        if ($from !== '') { $q->where('due_at', '>=', Carbon::parse($from)->startOfDay()); }
        if ($to !== '') { $q->where('due_at', '<=', Carbon::parse($to)->endOfDay()); }
        // Status values mirror the on-screen filter: open (any active) or a specific state.
        if ($status === 'open') { $q->whereIn('status', OrderTask::ACTIVE); }
        elseif ($status === 'closed') { $q->where('status', 'completed'); }
        elseif ($status === 'in_progress') { $q->where('status', 'in_progress'); }
        elseif ($status === 'paused') { $q->where('status', 'paused'); }
        elseif ($status === 'blocked') { $q->where('status', 'exception'); }
        if ($assigneeId > 0) { $q->where('owner_user_id', $assigneeId); }

        $tasks = $q->orderBy('due_at')->orderBy('id')->limit(1000)->get();

        $names = User::where('tenant_id', $t->id)->pluck('name', 'id');
        $orderIds = $tasks->pluck('order_id')->filter(fn ($v) => (int) $v > 0)->unique()->values()->all();
        $clients = $orderIds
            ? Order::withoutGlobalScopes()->where('tenant_id', $t->id)->whereIn('id', $orderIds)->pluck('order_no', 'id')->toArray()
            : [];

        $rows = []; $openN = 0; $overdueN = 0;
        foreach ($tasks as $x) {
            $isOpen = $x->status !== 'completed';
            if ($isOpen) { $openN++; }
            if ($isOpen && $x->isOverdue()) { $overdueN++; }
            $mins = (int) $x->sla_minutes;
            $dur = $mins > 0
                ? trim((intdiv($mins, 60) ? intdiv($mins, 60) . ' hr' : '') . (($mins % 60) ? ' ' . ($mins % 60) . ' min' : ''))
                : '';
            $rows[] = [
                'title'    => (string) $x->title,
                'client'   => (string) ($clients[$x->order_id] ?? ''),
                'assignee' => (string) ($names[$x->owner_user_id] ?? ''),
                'due'      => $x->due_at ? $x->due_at->format('d M Y H:i') : '',
                'duration' => $dur,
                'priority' => WorkflowConfig::priorityLabel((int) $x->priority),
                'status'   => match ($x->status) {
                    'completed'   => 'Closed',
                    'in_progress' => 'In progress',
                    'paused'      => 'Paused',
                    'exception'   => 'Blocked',
                    default       => 'Open',
                },
                'overdue'  => $isOpen && $x->isOverdue(),
            ];
        }

        return [
            'business'  => (string) ($t->name ?: 'Business'),
            'rows'      => $rows,
            'count'     => count($rows),
            'openN'     => $openN,
            'overdueN'  => $overdueN,
            'from'      => $from,
            'to'        => $to,
            'status'    => $status,
            'assignee'  => $assigneeId > 0 ? (string) ($names[$assigneeId] ?? ('User ' . $assigneeId)) : '',
            'generated' => now()->format('d M Y H:i'),
        ];
    }

    public function csv(array $d): string
    {
        $out = fopen('php://temp', 'r+');
        $scope = array_filter([
            $d['from'] || $d['to'] ? ('Due: ' . ($d['from'] ?: '…') . ' to ' . ($d['to'] ?: '…')) : '',
            $d['status'] ? ('Status: ' . $d['status']) : '',
            $d['assignee'] ? ('Assignee: ' . $d['assignee']) : '',
        ]);
        fputcsv($out, [$d['business'] . ' — Tasks']);
        fputcsv($out, [implode('  ·  ', $scope) ?: 'All tasks', 'Generated ' . $d['generated']]);
        fputcsv($out, []);
        fputcsv($out, ['Task', 'Client', 'Assignee', 'Due', 'Duration', 'Priority', 'Status', 'Overdue']);
        foreach ($d['rows'] as $r) {
            fputcsv($out, [$r['title'], $r['client'], $r['assignee'], $r['due'], $r['duration'], $r['priority'], $r['status'], $r['overdue'] ? 'YES' : '']);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF" . $csv; // BOM so Excel reads UTF-8
    }

    public function pdf(Tenant $t, array $d): ?string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) { return null; }
        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->loadHtml($this->html($d));
            $dompdf->render();

            return $dompdf->output();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function html(array $d): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);

        $scope = array_filter([
            $d['from'] || $d['to'] ? ('Due ' . ($d['from'] ?: '…') . ' → ' . ($d['to'] ?: '…')) : '',
            $d['status'] ? ('Status ' . $d['status']) : '',
            $d['assignee'] ? ('Assignee ' . $d['assignee']) : '',
        ]);

        $rows = '';
        foreach ($d['rows'] as $r) {
            $badge = match ($r['status']) {
                'Closed'      => 'ok',
                'In progress' => 'info',
                'Blocked'     => 'bad',
                'Paused'      => 'mut2',
                default       => 'warn',
            };
            $rows .= '<tr><td><b>' . $e($r['title']) . '</b></td><td>' . $e($r['client'] ?: '—')
                . '</td><td>' . $e($r['assignee'] ?: '—')
                . '</td><td' . ($r['overdue'] ? ' class="out"' : '') . '>' . $e($r['due'] ?: '—') . ($r['overdue'] ? ' ⚠' : '')
                . '</td><td>' . $e($r['duration'] ?: '—') . '</td><td>' . $e($r['priority'])
                . '</td><td><span class="pill ' . $badge . '">' . $e($r['status']) . '</span></td></tr>';
        }
        if ($rows === '') { $rows = '<tr><td colspan="7" class="mut">No tasks in this range.</td></tr>'; }

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1f2933; font-size: 10.5px; }
            h1 { font-size: 17px; margin: 0 0 2px; color: #15803d; }
            .sub { color: #6b7280; font-size: 10px; margin-bottom: 6px; }
            .chips { margin-bottom: 12px; } .chip { display: inline-block; background: #f3f4f6; border-radius: 10px; padding: 2px 9px; font-size: 10px; color: #374151; margin-right: 4px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; background: #f3f4f6; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 5px 7px; border-bottom: 1px solid #e5e7eb; }
            td { padding: 5px 7px; border-bottom: 1px solid #eef1f4; } td.mut { color: #9ca3af; text-align: center; padding: 24px; }
            .out { color: #b91c1c; }
            .pill { display: inline-block; border-radius: 9px; padding: 1px 8px; font-size: 9px; }
            .pill.ok { background: #dcfce7; color: #166534; } .pill.info { background: #dbeafe; color: #1d4ed8; }
            .pill.warn { background: #fef9c3; color: #854d0e; } .pill.bad { background: #fee2e2; color: #b91c1c; }
            .pill.mut2 { background: #f3f4f6; color: #4b5563; }
            .foot { margin-top: 14px; color: #9ca3af; font-size: 9px; text-align: center; }
        </style></head><body>
            <h1>' . $e($d['business']) . ' — Tasks</h1>
            <div class="sub">' . ($scope ? $e(implode('  ·  ', $scope)) : 'All tasks') . ' &nbsp;·&nbsp; ' . $e($d['count']) . ' tasks &nbsp;·&nbsp; Generated ' . $e($d['generated']) . '</div>
            <div class="chips"><span class="chip">Open ' . $e($d['openN']) . '</span><span class="chip">Overdue ' . $e($d['overdueN']) . '</span></div>
            <table><thead><tr><th>Task</th><th>Client</th><th>Assignee</th><th>Due</th><th>Duration</th><th>Priority</th><th>Status</th></tr></thead>
            <tbody>' . $rows . '</tbody></table>
            <div class="foot">Duration is the planned time (SLA) · overdue rows marked ⚠</div>
        </body></html>';
    }
}
