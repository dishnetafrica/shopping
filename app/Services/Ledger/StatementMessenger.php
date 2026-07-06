<?php declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\MessageLog;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use CloudBSS\Infra\Reporting\Statement;
use CloudBSS\Kernel\Money\Currency;

/**
 * Turns a customer's subsidiary-ledger statement into a friendly WhatsApp message and sends it via
 * the tenant's existing WhatsApp channel — the "send statement / payment reminder" action behind
 * the Books page. Reuses CloudBSS\Infra\Reporting\Statement (already reconciles to the GL).
 */
class StatementMessenger
{
    public function __construct(private WhatsAppManager $wa) {}

    /**
     * @return array{ok:bool, reason?:string, total?:int}
     */
    public function send(Tenant $t, string $party): array
    {
        // party is the customer's WhatsApp phone (that's how sales tag AR). Guard non-phone parties.
        $phone = preg_replace('/[^0-9]/', '', $party);
        if (strlen($phone) < 9) {
            return ['ok' => false, 'reason' => 'no_phone'];
        }

        $ledgerTenant = (string) $t->setting('ledger_tenant', 't_' . $t->id);
        $entity       = (string) $t->setting('ledger_entity', 'e1');
        $cur          = (string) $t->setting('currency', 'UGX');

        $stmt = app(Statement::class)->forParty($ledgerTenant, $entity, $party, 'AR', $cur);
        if (($stmt['total_outstanding'] ?? 0) <= 0) {
            return ['ok' => false, 'reason' => 'nothing_owed'];
        }

        $text = $this->format($t, $stmt, $cur);

        try {
            $this->wa->forTenant($t)->sendText($t->whatsapp_instance, $party, $text);
            MessageLog::record($t->id, $party, $t->whatsapp_instance, 'out', 'system', $text, null, null, ['kind' => 'statement']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'send_failed'];
        }

        return ['ok' => true, 'total' => (int) $stmt['total_outstanding']];
    }

    private function format(Tenant $t, array $stmt, string $cur): string
    {
        try { $factor = Currency::of($cur)->subunit(); } catch (\Throwable $e) { $factor = 1; }
        $dec = $factor > 1 ? (int) round(log10($factor)) : 0;
        $money = fn (int $minor) => $cur . ' ' . number_format($minor / $factor, $dec);

        $name = (string) ($stmt['name'] ?: 'there');
        $lines = ["*" . $t->name . "* — Account Statement", "", "Hi {$name}, here's your current account with us:", ""];

        foreach ($stmt['items'] as $it) {
            $amt = $money($it['outstanding']);
            if (str_starts_with((string) $it['ref'], 'int_')) {
                $lines[] = "• Late-payment fee: {$amt}";
            } else {
                $due = $it['due_date'] ?? null;
                $tag = ($it['days_overdue'] ?? 0) > 0 ? " (⚠️ {$it['days_overdue']} days overdue)" : ($due ? " (due {$due})" : "");
                $lines[] = "• Invoice: {$amt}{$tag}";
            }
        }

        $lines[] = "";
        $lines[] = "*Total due: " . $money((int) $stmt['total_outstanding']) . "*";
        $lines[] = "";
        $lines[] = "Kindly arrange payment at your earliest convenience. Reply here if you have any questions — thank you! 🙏";

        return implode("\n", $lines);
    }
}
