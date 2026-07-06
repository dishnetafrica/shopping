<?php declare(strict_types=1);

namespace App\Observers;

use App\Models\LedgerEntry;
use App\Services\Ledger\OrderLedgerService;

/**
 * Posts a CloudBSS customer receipt (Dr Cash, Cr AR) whenever an order payment is recorded — i.e.
 * a LedgerEntry with category 'order_payment' (created by PanelApiController::recordPayment). One
 * entry = one payment, so this is naturally idempotent (keyed on the entry id). Best-effort inside
 * OrderLedgerService; never blocks payment recording.
 */
class LedgerEntryObserver
{
    public function created(LedgerEntry $entry): void
    {
        if ((string) $entry->category !== 'order_payment') return;
        app(OrderLedgerService::class)->postReceipt($entry);
    }
}
