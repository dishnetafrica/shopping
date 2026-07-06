<?php declare(strict_types=1);

namespace CloudBSS\Infra;

/**
 * Periodic overdue-interest charge. Scans overdue AR open items and posts InterestCharged for each,
 * once per calendar month (idempotent aggregate id "int_{ref}_{YYYYMM}"). Interest is simple on the
 * principal outstanding (interest lines themselves are excluded, so it doesn't compound unless you
 * run it that way). Rate is basis points per month — Patronics 3%/month = 300 bp.
 *
 * Run monthly (a scheduled job): $run->run('t_ug','e1', new DateTimeImmutable('today'), 300);
 */
final class InterestRun
{
    public function __construct(private \PDO $pdo, private LedgerPoster $poster) {}

    /**
     * @return list<array{party:string,ref:string,interest:int}> charges actually posted this run.
     */
    public function run(string $tenantId, string $entityId, \DateTimeImmutable $asOf, int $monthlyRateBp = 300, string $currency = 'UGX'): array
    {
        $period = $asOf->format('Ym');
        $st = $this->pdo->prepare(
            "SELECT ref, party, party_name, (amount_minor - paid_minor) AS outstanding
               FROM cloudbss_subledger
              WHERE tenant_id=:t AND entity_id=:en AND kind='AR' AND currency=:cur
                AND amount_minor > paid_minor
                AND due_date IS NOT NULL AND due_date < :asof
                AND ref NOT LIKE 'int\\_%'
              ORDER BY due_date"
        );
        $st->execute([':t'=>$tenantId, ':en'=>$entityId, ':cur'=>$currency, ':asof'=>$asOf->format('Y-m-d')]);

        $charges = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $item) {
            $interest = intdiv((int) $item['outstanding'] * $monthlyRateBp, 10000);
            if ($interest <= 0) continue;

            $intRef = "int_{$item['ref']}_{$period}";
            $posted = $this->poster->post(
                $tenantId, $entityId, $intRef, 'Interest', 'InterestCharged',
                "int_{$tenantId}_{$intRef}",
                [
                    'amount'     => $interest,
                    'currency'   => $currency,
                    'party'      => $item['party'],
                    'party_name' => $item['party_name'],
                    'source_ref' => $item['ref'],
                    'due_date'   => $asOf->format('Y-m-d'),
                ],
                $asOf,
            );
            if ($posted) $charges[] = ['party'=>$item['party'], 'ref'=>$intRef, 'interest'=>$interest];
        }
        return $charges;
    }
}
