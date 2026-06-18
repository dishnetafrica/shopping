<?php
namespace App\Services\Winworld;

/**
 * Sales order workflow (from WW/SM/CRM/SOP/01). Pure stage machine: the
 * ordered stages, each stage's owner role and SLA, the approvals a stage
 * needs (incl. the credit/ageing MD gate), and transition rules.
 */
final class SalesFlow
{
    /** stage => [label, role, sla] */
    public const STAGES = [
        'enquiry'        => ['Enquiry',                'sales',       ['h' => 3]],
        'order_received' => ['Order received',         'sales',       ['h' => 1]],
        'credit_check'   => ['Credit & ageing check',  'sales_coord', ['h' => 1]],
        'sap_approval'   => ['SAP posting & approval',  'sm',          ['h' => 1]],
        'order_indent'   => ['Order indent',           'sales_coord', ['h' => 2]],
        'delivery'       => ['Delivery',               'stores',      ['wd' => 3]],
    ];

    public const ORDER = ['enquiry','order_received','credit_check','sap_approval','order_indent','delivery'];

    public const OVERDUE_MD_DAYS = 30;   // overdue beyond this needs MD approval (SOP 3.3.2)

    public static function isStage(string $s): bool { return isset(self::STAGES[$s]); }
    public static function label(string $s): string { return self::STAGES[$s][0] ?? $s; }
    public static function role(string $s): string  { return self::STAGES[$s][1] ?? 'sales'; }
    public static function sla(string $s): array     { return self::STAGES[$s][2] ?? []; }

    /** The next stage after $s, or null if $s is the last. */
    public static function next(string $s): ?string
    {
        $i = array_search($s, self::ORDER, true);
        if ($i === false) return null;
        return self::ORDER[$i + 1] ?? null;
    }

    /**
     * Approvals required to leave a stage, in order.
     * SAP approval = SM then MD (2nd approval). Credit check needs MD only
     * when the customer is overdue beyond the threshold.
     * @return string[]
     */
    public static function approvalsFor(string $stage, int $overdueDays = 0): array
    {
        if ($stage === 'sap_approval') return ['sm', 'md'];
        if ($stage === 'credit_check' && $overdueDays > self::OVERDUE_MD_DAYS) return ['md'];
        return [];
    }

    /** Can the order leave $stage given the approvals already recorded? */
    public static function canAdvance(string $stage, array $approvalsDone, int $overdueDays = 0): bool
    {
        $need = self::approvalsFor($stage, $overdueDays);
        foreach ($need as $role) {
            if (! in_array($role, $approvalsDone, true)) return false;
        }
        return true;
    }
}
