<?php

namespace App\Services\Sales;

use App\Models\Tenant;
use App\Services\Bot\OrderCalculator;
use App\Services\Bot\QuotationService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The ONE place v3 raises a quotation. It reuses the FROZEN bot path end-to-end: OrderCalculator
 * prices the picked lines exactly as the WhatsApp bot does, QuotationService renders the branded PDF
 * and persists the numbered 'sent' quotation, and (optionally) WhatsAppManager delivers the PDF to
 * the customer. No pricing, numbering or document logic is duplicated here.
 */
final class QuotationWriteService
{
    /**
     * @param array<int,array{name?:string,query?:string,qty?:mixed,price?:mixed}> $lines
     * @return array<string,mixed>
     */
    public function createFor(Tenant $t, string $phone, string $name, array $lines, ?string $currency, bool $sendWhatsapp): array
    {
        $items = [];
        foreach ($lines as $l) {
            $nm = trim((string) ($l['name'] ?? $l['query'] ?? ''));
            if ($nm === '') {
                continue;
            }
            $items[] = [
                'query' => $nm,
                'qty' => max(1, (int) ($l['qty'] ?? 1)),
                'price' => (isset($l['price']) && $l['price'] !== '' && $l['price'] !== null) ? (float) $l['price'] : null,
            ];
        }
        if (! $items) {
            return ['ok' => false, 'error' => 'no_lines', 'message' => 'Add at least one product line to the quotation.'];
        }

        $quote = app(OrderCalculator::class)->quote($t, $items);
        if ($currency) {
            $quote['currency'] = strtoupper($currency);
        }

        $svc = app(QuotationService::class);
        $doc = $svc->generate($t, $phone, $name, $quote);
        if (! $doc) {
            // PDF engine not installed on this host — still record the quotation so nothing is lost.
            $doc = ['no' => $this->fallbackNo($t), 'total' => (float) ($quote['total'] ?? 0), 'path' => '', 'url' => '', 'b64' => '', 'currency' => (string) $quote['currency'], 'fileName' => ''];
        }

        $q = $svc->persist($t, $phone, $name, $quote, $doc, 'v3');

        $sent = false;
        if ($sendWhatsapp && (($doc['b64'] ?? '') !== '' || ($doc['url'] ?? '') !== '')) {
            $sent = $this->sendWhatsapp($t, $phone, $doc);
        }

        return [
            'ok' => true,
            'quote_no' => (string) $doc['no'],
            'id' => $q?->id,
            'url' => (string) ($doc['url'] ?? ''),
            'total' => (float) ($doc['total'] ?? 0),
            'currency' => (string) $doc['currency'],
            'has_pdf' => ($doc['path'] ?? '') !== '',
            'sent' => $sent,
        ];
    }

    private function sendWhatsapp(Tenant $t, string $phone, array $doc): bool
    {
        try {
            $gw = app(WhatsAppManager::class)->forTenant($t);
            if (! method_exists($gw, 'sendDocument')) {
                return false;
            }
            $cur = (string) $doc['currency'];
            $valid = ((int) $t->setting('quote_validity_days', 14)) ?: 14;
            $caption = "📄 Quotation {$doc['no']} — Total {$cur} " . number_format((float) $doc['total']) . ". Valid {$valid} days. Reply to confirm and we'll arrange delivery.";
            $media = ($doc['b64'] ?? '') !== '' ? $doc['b64'] : $doc['url'];
            $file = ($doc['fileName'] ?? '') !== '' ? $doc['fileName'] : ('Quotation-' . $doc['no'] . '.pdf');
            $gw->sendDocument($t->whatsapp_instance, preg_replace('/[^0-9]/', '', $phone), $media, $file, $caption);

            return true;
        } catch (\Throwable $e) {
            Log::warning('v3 quotation WhatsApp send failed: ' . $e->getMessage());

            return false;
        }
    }

    private function fallbackNo(Tenant $t): string
    {
        return strtoupper((string) ($t->order_prefix ?: 'Q')) . '-Q' . now()->format('ymd') . '-' . strtoupper(Str::random(4));
    }
}
