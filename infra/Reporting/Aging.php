<?php declare(strict_types=1);

namespace CloudBSS\Infra\Reporting;

/**
 * Debtors / creditors aging over the subsidiary ledger. Answers "who owes us what, and how overdue"
 * (and the same for suppliers). Buckets by due_date vs an as-of date: current / 1-30 / 31-60 / 61-90 / 90+.
 * The grand total reconciles to the GL control account (1100 AR / 2000 AP).
 */
final class Aging
{
    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** kind = 'AR' (debtors) or 'AP' (creditors). @return list<array> one row per party. */
    public function byParty(string $tenantId, string $entityId, string $kind, string $currency = 'UGX', ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('today');
        $rows = $this->openItems($tenantId, $entityId, $kind, $currency);

        $parties = [];
        foreach ($rows as $r) {
            $out = (int) $r['amount_minor'] - (int) $r['paid_minor'];
            if ($out <= 0) continue;
            $p = $r['party'];
            $parties[$p] ??= ['party' => $p, 'name' => $r['party_name'],
                'current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0, 'total' => 0];
            $bucket = $this->bucket($r['due_date'], $asOf);
            $parties[$p][$bucket] += $out;
            $parties[$p]['total']  += $out;
        }
        usort($parties, fn ($a, $b) => $b['total'] <=> $a['total']);
        return array_values($parties);
    }

    /** Total outstanding for a kind — must equal the GL control-account balance. */
    public function totalOutstanding(string $tenantId, string $entityId, string $kind, string $currency = 'UGX'): int
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount_minor - paid_minor),0)
               FROM cloudbss_subledger
              WHERE tenant_id=:t AND entity_id=:en AND kind=:k AND currency=:cur'
        );
        $st->execute([':t'=>$tenantId, ':en'=>$entityId, ':k'=>$kind, ':cur'=>$currency]);
        return (int) $st->fetchColumn();
    }

    private function openItems(string $t, string $en, string $kind, string $cur): array
    {
        $st = $this->pdo->prepare(
            'SELECT party, party_name, ref, amount_minor, paid_minor, due_date
               FROM cloudbss_subledger
              WHERE tenant_id=:t AND entity_id=:en AND kind=:k AND currency=:cur
                AND amount_minor > paid_minor
              ORDER BY party, due_date NULLS LAST'
        );
        $st->execute([':t'=>$t, ':en'=>$en, ':k'=>$kind, ':cur'=>$cur]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function bucket(?string $dueDate, \DateTimeImmutable $asOf): string
    {
        if ($dueDate === null) return 'current';           // no terms -> treat as current
        $due = new \DateTimeImmutable($dueDate);
        $days = (int) $asOf->diff($due)->format('%r%a');   // negative = overdue by |days|
        if ($days >= 0)  return 'current';
        $over = -$days;
        if ($over <= 30) return 'd1_30';
        if ($over <= 60) return 'd31_60';
        if ($over <= 90) return 'd61_90';
        return 'd90_plus';
    }
}
