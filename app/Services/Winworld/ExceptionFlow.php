<?php
namespace App\Services\Winworld;

/**
 * SOP exception workflows (WW/SM/CRM/SOP/01 §4-6). Pure rules: each exception
 * type's owner role + SLA, and the approval chain — including the goods-return
 * value gate (returns above the limit also need MD).
 */
final class ExceptionFlow
{
    /** type => [label, owner_role, sla] */
    public const TYPES = [
        'complaint'    => ['Complaint',    'pdm',         ['wh' => 8]], // PD.M, 8 working hours
        'goods_return' => ['Goods return', 'sdh',         ['wh' => 3]], // SDH/SM, 3 working hours
        'credit_note'  => ['Credit note',  'sales_coord', ['h'  => 4]],
        'debit_note'   => ['Debit note',   'sales_coord', ['h'  => 4]],
    ];

    public const RETURN_MD_LIMIT = 10000000; // goods returns above this also need MD

    public static function isType(string $t): bool { return isset(self::TYPES[$t]); }
    public static function label(string $t): string { return self::TYPES[$t][0] ?? $t; }
    public static function role(string $t): string  { return self::TYPES[$t][1] ?? 'sales'; }
    public static function sla(string $t): array     { return self::TYPES[$t][2] ?? []; }

    /**
     * Approval chain to resolve an exception.
     *  - goods_return: SM, plus MD when amount is above the limit
     *  - credit_note / debit_note: SM then MD
     *  - complaint: handled by PD.M, no formal approval gate
     * @return string[]
     */
    public static function approvalsFor(string $type, float $amount = 0): array
    {
        return match ($type) {
            'goods_return'             => $amount > self::RETURN_MD_LIMIT ? ['sm', 'md'] : ['sm'],
            'credit_note', 'debit_note' => ['sm', 'md'],
            default                    => [],
        };
    }

    public static function canResolve(string $type, array $approvalsDone, float $amount = 0): bool
    {
        foreach (self::approvalsFor($type, $amount) as $role) {
            if (! in_array($role, $approvalsDone, true)) return false;
        }
        return true;
    }
}
