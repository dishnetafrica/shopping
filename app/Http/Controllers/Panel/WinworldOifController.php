<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\WwIndent;
use App\Models\WwIndentQc;
use App\Services\Winworld\QcStatus;
use Illuminate\Http\Request;

/**
 * Order Indent Form (WIL/MKT/OIF/001): a print-perfect document + digital
 * QC sign-offs (Supervisor / QC / Section-Head per process). The page prints
 * to PDF from the browser; sign-offs are captured live to ww_indent_qc.
 */
class WinworldOifController extends Controller
{
    private const ROLES = [
        'supervisor' => ['supervisor_sign','supervisor_at'],
        'qc'         => ['qc_sign','qc_at'],
        'sec_head'   => ['sec_head_sign','sec_head_at'],
    ];

    public function oifPage(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return redirect('/app/login');
        $path = resource_path('panel/oif.html');
        if (! is_file($path)) abort(500, 'OIF asset missing.');
        $name = (string) ($u->tenant->name ?? 'Win World');
        $html = str_replace('{{WW_TENANT}}', htmlspecialchars($name, ENT_QUOTES), file_get_contents($path));
        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    public function oifData(Request $r)
    {
        $indent = WwIndent::with(['blends','qc'])->find((int) $r->query('id'));
        if (! $indent) return response()->json(['ok' => false, 'error' => 'Not found'], 404);

        $qcRows = $indent->qc->map(fn($q) => $q->toArray())->all();
        $perProcess = QcStatus::perProcess($indent->activeProcesses(), $qcRows);

        return response()->json([
            'ok'        => true,
            'indent'    => $indent,
            'blends'    => $indent->blends,
            'qc'        => $indent->qc->keyBy('process'),
            'processes' => $indent->activeProcesses(),
            'qc_status' => $perProcess,
            'all_signed'=> QcStatus::allSigned($perProcess),
            'signed'    => QcStatus::signedCount($perProcess),
            'signer'    => (string) ($r->user()->name ?? ''),
            'issue'     => ['no' => '01', 'date' => 'November 2020', 'ref' => WwIndent::DOC_REF],
        ]);
    }

    /** Record one sign-off (role) for one process. */
    public function qcSign(Request $r)
    {
        $indentId = (int) $r->input('indent_id');
        $process  = (string) $r->input('process');
        $role     = (string) $r->input('role');          // '' allowed for result-only
        $hasResult = $r->filled('result');
        if ($role !== '' && ! isset(self::ROLES[$role])) {
            return response()->json(['ok' => false, 'error' => 'Bad role'], 422);
        }
        if ($role === '' && ! $hasResult) {
            return response()->json(['ok' => false, 'error' => 'Nothing to record'], 422);
        }
        $indent = WwIndent::find($indentId);
        if (! $indent) return response()->json(['ok' => false, 'error' => 'Indent not found'], 404);

        $row = WwIndentQc::firstOrNew(['indent_id' => $indentId, 'process' => $process]);
        $row->indent_id = $indentId;
        $row->process   = $process;
        if (empty($row->production_at)) $row->production_at = now();

        if ($role !== '') {
            $signer = trim((string) $r->input('name')) ?: (string) ($r->user()->name ?? 'Staff');
            [$signCol, $atCol] = self::ROLES[$role];
            $row->{$signCol} = $signer;
            $row->{$atCol}   = now();
        }
        if ($hasResult) $row->result = (string) $r->input('result');
        $row->save();

        return response()->json([
            'ok'   => true,
            'process' => $process,
            'qc'   => $row->fresh(),
        ]);
    }
}
