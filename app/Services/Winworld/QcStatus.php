<?php
namespace App\Services\Winworld;

/**
 * QC sign-off status per process. Pure logic. Each process needs three
 * sign-offs (Supervisor / QC / Section-Head QC); a process is complete only
 * when all three are present. Used by the OIF document and to tell whether
 * an indent is fully signed (release-ready).
 */
final class QcStatus
{
    /**
     * @param string[] $activeProcesses e.g. ['Blending','Extrusion','Printing','Cutting']
     * @param array    $qcRows rows keyed by process with *_sign + result
     * @return array<int,array{process:string,supervisor:bool,qc:bool,sec_head:bool,complete:bool,result:?string}>
     */
    public static function perProcess(array $activeProcesses, array $qcRows): array
    {
        $byProc = [];
        foreach ($qcRows as $r) {
            $byProc[(string) ($r['process'] ?? '')] = $r;
        }
        $out = [];
        foreach ($activeProcesses as $p) {
            $r   = $byProc[$p] ?? [];
            $sup = ! empty($r['supervisor_sign']);
            $qc  = ! empty($r['qc_sign']);
            $sh  = ! empty($r['sec_head_sign']);
            $out[] = [
                'process'    => $p,
                'supervisor' => $sup,
                'qc'         => $qc,
                'sec_head'   => $sh,
                'complete'   => $sup && $qc && $sh,
                'result'     => $r['result'] ?? null,
            ];
        }
        return $out;
    }

    /** True only when every active process is fully signed off. */
    public static function allSigned(array $perProcess): bool
    {
        if ($perProcess === []) return false;
        foreach ($perProcess as $p) {
            if (empty($p['complete'])) return false;
        }
        return true;
    }

    /** Any process marked reject. */
    public static function anyReject(array $perProcess): bool
    {
        foreach ($perProcess as $p) {
            if (strtolower((string) ($p['result'] ?? '')) === 'reject') return true;
        }
        return false;
    }

    /** Count of fully-signed processes (for a "2/4 signed" badge). */
    public static function signedCount(array $perProcess): int
    {
        return count(array_filter($perProcess, fn($p) => ! empty($p['complete'])));
    }
}
