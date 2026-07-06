<?php declare(strict_types=1);

namespace CloudBSS\Infra\Postgres;

use CloudBSS\Kernel\Event\RecordedEvent;
use CloudBSS\Kernel\Ledger\Posting;

/**
 * Subsidiary (open-item) ledger, keyed by counterparty. The general ledger keeps aggregate AR/AP
 * *control* accounts; this projection keeps the per-customer / per-supplier detail that reconciles
 * to them — so you can answer "who owes us what, and how overdue".
 *
 * It reads the business events (which carry the party + due date) and pairs each with its resulting
 * JournalPosted (same correlationId) to capture the EXACT control-account amount — so the subledger
 * total always equals the GL control balance. Payments apply oldest-due-first (or to a named ref).
 *
 * Subscribe on() to the bus for ALL events. Party tracking is opt-in: an event with no 'party' in
 * its payload simply isn't tracked here (the GL is unaffected).
 */
final class PostgresSubledgerProjection
{
    /** correlationId => [kind, party, name, ref, due] awaiting its JournalPosted. */
    private array $pending = [];

    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function on(RecordedEvent $rec): void
    {
        $e = $rec->event;

        // 1) A sale/purchase names a party -> remember it until its JournalPosted arrives.
        if ($e->name === 'DeliveryCompleted' || $e->name === 'PurchaseReceived') {
            $party = $e->payload['party'] ?? null;
            if ($party === null || $party === '') return;
            $this->pending[(string) $e->correlationId] = [
                'kind'  => $e->name === 'DeliveryCompleted' ? 'AR' : 'AP',
                'party' => (string) $party,
                'name'  => (string) ($e->payload['party_name'] ?? ''),
                'ref'   => $e->aggregateId,
                'due'   => $e->payload['due_date'] ?? null,
            ];
            return;
        }

        // 2) The posting for a tracked sale/purchase -> open the item with the exact control amount.
        if ($rec->name() === Posting::EVENT) {
            $corr = (string) $e->correlationId;
            if (!isset($this->pending[$corr])) return;
            $info = $this->pending[$corr];
            unset($this->pending[$corr]);

            $control = $info['kind'] === 'AR' ? '1100' : '2000';
            $amount = 0;
            foreach ($e->payload['lines'] as $l) {
                if ($l['code'] === $control) { $amount = (int) $l['minor']; break; }
            }
            if ($amount === 0) return;

            $cur = $e->payload['lines'][0]['currency'] ?? 'UGX';
            $st = $this->pdo->prepare(
                'INSERT INTO cloudbss_subledger
                   (tenant_id, entity_id, kind, party, party_name, ref, currency, amount_minor, due_date)
                 VALUES (:t,:en,:k,:p,:pn,:ref,:cur,:amt,:due)
                 ON CONFLICT (tenant_id, entity_id, kind, ref) DO NOTHING'
            );
            $st->execute([
                ':t'=>$e->tenantId, ':en'=>$e->entityId, ':k'=>$info['kind'], ':p'=>$info['party'],
                ':pn'=>$info['name'], ':ref'=>$info['ref'], ':cur'=>$cur, ':amt'=>$amount, ':due'=>$info['due'],
            ]);
            return;
        }

        // 3) A payment settles open items for a party (oldest-due first, or a named ref).
        if ($e->name === 'PaymentReceived' || $e->name === 'SupplierPaid') {
            $party = $e->payload['party'] ?? null;
            if ($party === null || $party === '') return;
            $kind = $e->name === 'PaymentReceived' ? 'AR' : 'AP';
            $this->applyPayment($e->tenantId, $e->entityId, $kind, (string) $party,
                (int) $e->payload['amount'], $e->payload['applies_to'] ?? null);
        }
    }

    private function applyPayment(string $t, string $en, string $kind, string $party, int $amount, ?string $ref): void
    {
        $sql = 'SELECT ref, (amount_minor - paid_minor) AS outstanding
                  FROM cloudbss_subledger
                 WHERE tenant_id=:t AND entity_id=:en AND kind=:k AND party=:p
                   AND amount_minor > paid_minor';
        $params = [':t'=>$t, ':en'=>$en, ':k'=>$kind, ':p'=>$party];
        if ($ref !== null && $ref !== '') { $sql .= ' AND ref = :ref'; $params[':ref'] = $ref; }
        $sql .= ' ORDER BY due_date NULLS LAST, created_at';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $open = $st->fetchAll(\PDO::FETCH_ASSOC);

        $upd = $this->pdo->prepare(
            'UPDATE cloudbss_subledger SET paid_minor = paid_minor + :pay
              WHERE tenant_id=:t AND entity_id=:en AND kind=:k AND ref=:ref'
        );
        $left = $amount;
        foreach ($open as $item) {
            if ($left <= 0) break;
            $apply = min($left, (int) $item['outstanding']);
            $upd->execute([':pay'=>$apply, ':t'=>$t, ':en'=>$en, ':k'=>$kind, ':ref'=>$item['ref']]);
            $left -= $apply;
        }
        // Any $left over (overpayment / unmatched) is intentionally left unallocated here; the GL
        // still reflects the full cash movement — subledger over-allocation is a separate concern.
    }

    /** Recovery: rebuild the subledger from the events log (same ordered fold). */
    public function rebuildFrom(array $recordedEvents): void
    {
        $this->pdo->exec('TRUNCATE cloudbss_subledger');
        $this->pending = [];
        foreach ($recordedEvents as $rec) $this->on($rec);
    }
}
