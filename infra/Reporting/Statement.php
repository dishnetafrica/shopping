<?php declare(strict_types=1);

namespace CloudBSS\Infra\Reporting;

/**
 * A customer (or supplier) statement built from the subsidiary ledger: every open item with its
 * outstanding + days overdue, and the total owed. This is the artifact you can render to PDF or
 * send over WhatsApp as a payment reminder. Reconciles to the GL control account.
 */
final class Statement
{
    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{party:string,name:string,kind:string,currency:string,total_outstanding:int,
     *               items:list<array{ref:string,amount:int,paid:int,outstanding:int,due_date:?string,days_overdue:int}>}
     */
    public function forParty(string $tenantId, string $entityId, string $party, string $kind = 'AR', string $currency = 'UGX', ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('today');
        $st = $this->pdo->prepare(
            'SELECT ref, party_name, amount_minor, paid_minor, due_date
               FROM cloudbss_subledger
              WHERE tenant_id=:t AND entity_id=:en AND kind=:k AND party=:p AND currency=:cur
                AND amount_minor > paid_minor
              ORDER BY due_date NULLS LAST, created_at'
        );
        $st->execute([':t'=>$tenantId, ':en'=>$entityId, ':k'=>$kind, ':p'=>$party, ':cur'=>$currency]);

        $items = [];
        $total = 0;
        $name  = $party;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out = (int) $r['amount_minor'] - (int) $r['paid_minor'];
            $total += $out;
            $name = (string) $r['party_name'] ?: $name;
            $overdue = 0;
            if ($r['due_date'] !== null) {
                $days = (int) $asOf->diff(new \DateTimeImmutable((string) $r['due_date']))->format('%r%a');
                $overdue = $days < 0 ? -$days : 0;
            }
            $items[] = [
                'ref' => (string) $r['ref'], 'amount' => (int) $r['amount_minor'],
                'paid' => (int) $r['paid_minor'], 'outstanding' => $out,
                'due_date' => $r['due_date'] !== null ? (string) $r['due_date'] : null,
                'days_overdue' => $overdue,
            ];
        }

        return [
            'party' => $party, 'name' => $name, 'kind' => $kind, 'currency' => $currency,
            'total_outstanding' => $total, 'items' => $items,
        ];
    }
}
