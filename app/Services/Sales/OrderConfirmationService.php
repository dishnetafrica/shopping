<?php

namespace App\Services\Sales;

use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;

/**
 * v4.43 — send the customer what they actually agreed to, and record that they agreed.
 *
 * THE PROBLEM THIS SOLVES
 * A customer confirms an order. The team loads the truck. At the door the customer says he
 * ordered five, not ten, or that the price was different. Until now the only thing he had
 * received was "Your order ORD-1041 is confirmed" — no items, no quantities, no prices, no
 * address. The itemised numbers reached him on the invoice, which auto-issues on DELIVERY,
 * i.e. after the argument. There was nothing to point at.
 *
 * THE THREE RULES THIS FILE EXISTS TO ENFORCE
 *
 *  1. TEXT FIRST, PDF SECOND. People read WhatsApp on a phone and very often never open the
 *     attachment. If the numbers are only in the PDF, the numbers were not communicated.
 *     (Same finding as v4.25's billing messages.)
 *
 *  2. THE PROMISE IS FROZEN. What we send is snapshotted and fingerprinted. If anyone edits
 *     the order afterwards, `stateFor()` reports `changed` and the old agreement stops
 *     counting. A confirmation that silently follows later edits proves nothing.
 *
 *  3. A "YES" MUST REALLY BE A YES. `looksLikeYes()` accepts a short standalone affirmation
 *     and REJECTS anything carrying a negation or a change word — "yes but make it 5" is not
 *     acceptance. Manufacturing false evidence is worse than having none, because the team
 *     would walk into the argument believing they had proof.
 *
 * Never throws into the caller: an order must still confirm even if WhatsApp is down.
 */
class OrderConfirmationService
{
    /** How long after sending a WhatsApp reply can still count as acceptance. */
    public const YES_WINDOW_HOURS = 72;

    // ── pure helpers (no DB, no framework — these are what the harness exercises) ────────

    /**
     * The canonical form used for the fingerprint: only the facts a customer could argue
     * about. Cosmetic changes (a note reworded, the order re-saved) must not invalidate a
     * confirmation, and line ORDER is not a fact — so lines are sorted.
     */
    public static function canonical(array $snap): array
    {
        $lines = [];
        foreach (($snap['lines'] ?? []) as $l) {
            $lines[] = [
                'name'  => self::squash((string) ($l['name'] ?? '')),
                'qty'   => round((float) ($l['qty'] ?? 0), 3),
                'price' => round((float) ($l['price'] ?? 0), 2),
            ];
        }
        usort($lines, fn ($a, $b) => [$a['name'], $a['qty'], $a['price']] <=> [$b['name'], $b['qty'], $b['price']]);

        return [
            'lines'    => $lines,
            'total'    => round((float) ($snap['total'] ?? 0), 2),
            'currency' => strtoupper(self::squash((string) ($snap['currency'] ?? ''))),
            'address'  => self::squash((string) ($snap['address'] ?? '')),
            'terms'    => self::squash((string) ($snap['terms'] ?? '')),
        ];
    }

    /** sha256 over the canonical snapshot. Same facts in, same fingerprint out. */
    public static function fingerprint(array $snap): string
    {
        return hash('sha256', json_encode(self::canonical($snap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The message. Everything a dispute could turn on is in the TEXT — line by line, with the
     * quantity and unit price spelled out, then the total, the address and the terms.
     */
    public static function text(array $snap, array $opts = []): string
    {
        $cur      = strtoupper((string) ($snap['currency'] ?? 'UGX')) ?: 'UGX';
        $business = (string) ($opts['business'] ?? '');
        $first    = (string) ($opts['first_name'] ?? '');
        $link     = (string) ($opts['confirm_url'] ?? '');

        $out = [];
        $out[] = '📋 *Order ' . ($snap['order_no'] ?? '') . ' — please check*';
        $out[] = '';
        $out[] = $first !== '' ? ('Hello ' . $first . ',') : 'Hello,';
        $out[] = 'This is exactly what we have written down for you. Please read it before we deliver.';
        $out[] = '';

        $lines = $snap['lines'] ?? [];
        if ($lines) {
            $out[] = '*What you ordered*';
            $i = 0;
            foreach ($lines as $l) {
                $i++;
                $qty   = self::qtyText((float) ($l['qty'] ?? 0), (string) ($l['unit'] ?? ''));
                $name  = (string) ($l['name'] ?? '');
                $price = (float) ($l['price'] ?? 0);
                $row   = $i . '. ' . $qty . ' × ' . $name;
                if ($price > 0) {
                    $row .= ' — ' . self::money($price, $cur) . ' each = *' . self::money($price * (float) ($l['qty'] ?? 0), $cur) . '*';
                }
                $out[] = $row;
            }
            $out[] = '';
        }

        if ((float) ($snap['total'] ?? 0) > 0) {
            $out[] = '💵 *Total: ' . self::money((float) $snap['total'], $cur) . '*';
        }
        if (trim((string) ($snap['address'] ?? '')) !== '') {
            $out[] = '📍 Deliver to: ' . $snap['address'];
        }
        if (trim((string) ($snap['delivery_date'] ?? '')) !== '') {
            $out[] = '🚚 Expected: ' . $snap['delivery_date'];
        }
        if (trim((string) ($snap['terms'] ?? '')) !== '') {
            $out[] = '💳 Payment: ' . $snap['terms'];
        }
        if (trim((string) ($snap['contact'] ?? '')) !== '') {
            $out[] = '📞 Any question: ' . $snap['contact'];
        }

        $out[] = '';
        // The sentence that does the work: it makes silence mean agreement and gives the
        // customer a fair, explicit chance to correct us before anything is loaded.
        //
        // The URL goes on its own line with NO trailing punctuation. WhatsApp's link detector
        // swallows a full stop into the href, and a confirm link that 404s because of a period
        // is a feature that silently does nothing.
        if ($link !== '') {
            $out[] = '✅ If this is correct, reply *YES* — or tap here:';
            $out[] = $link;
        } else {
            $out[] = '✅ If this is correct, reply *YES*.';
        }
        $out[] = '✏️ If anything is wrong — a quantity, a price, the address — tell us NOW and we will fix it before delivery.';
        if ($business !== '') {
            $out[] = '';
            $out[] = '— ' . $business;
        }

        return implode("\n", $out);
    }

    /**
     * Is this inbound message a plain acceptance?
     *
     * Deliberately strict. A confirmation is evidence, and evidence built from a guess is
     * worse than no evidence at all — the team would walk into the dispute believing they
     * were covered. So: it has to be SHORT, it has to contain an affirmation, and it must
     * not contain a negation or any word that signals a change.
     */
    public static function looksLikeYes(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 40) {
            return false;   // a long message is a conversation, not a signature
        }

        // Anything suggesting disagreement or an amendment disqualifies it outright.
        if (preg_match('/\b(no|not|dont|don\'t|never|but|except|change|changed|wrong|instead|remove|cancel|less|more|reduce|increase|add|without|mistake|error|too)\b/u', $t)) {
            return false;
        }

        $yes = '/(^|\W)('
             . 'y|ya|yes|yeah|yep|yup|yess+|'
             . 'ok|oky|okay|okey|okey?doke|'
             . 'sure|fine|good|great|perfect|correct|right|confirm|confirmed|confirming|accept|accepted|agree|agreed|approved|noted|'
             . 'sawa|ndio|ndiyo|eeh|aywa|aiwa|na\'?am|naam|tamam|kwes|kweis|zein'
             . ')(\W|$)/u';

        return (bool) preg_match($yes, $t) || preg_match('/^[\s\x{2705}\x{1F44D}\x{1F44C}\x{1F64F}]+$/u', $t) === 1;
    }

    public static function money(float $n, string $cur): string
    {
        $dec = strtoupper($cur) === 'USD' ? 2 : 0;

        return strtoupper($cur) . ' ' . number_format($n, $dec);
    }

    private static function qtyText(float $qty, string $unit): string
    {
        $q = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
        $q = $q === '' ? '0' : $q;

        return $unit !== '' ? ($q . ' ' . $unit) : $q;
    }

    private static function squash(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    // ── the order → snapshot bridge ────────────────────────────────────────────────────

    /**
     * Read the order into a snapshot. Lines come from order_items when they exist (the
     * canonical source since v4.6.4) and fall back to items_json, then to items_text, so an
     * AI-captured order that was never synced still produces a real list rather than a blank.
     */
    public function snapshot(Tenant $t, Order $o): array
    {
        $cur = strtoupper((string) ($o->sell_currency ?: $t->setting('currency', 'UGX') ?: 'UGX'));

        $lines = [];
        try {
            foreach ($o->items()->get() as $it) {
                $lines[] = ['name' => (string) $it->name, 'qty' => (float) $it->qty, 'price' => (float) $it->price,
                            'unit' => (string) ($it->unit ?? ''), 'product_id' => $it->product_id ? (int) $it->product_id : null];
            }
        } catch (\Throwable $e) { /* fall through */ }

        if (! $lines) {
            foreach ((array) ($o->items_json ?? []) as $l) {
                if (! is_array($l)) continue;
                $lines[] = ['name' => (string) ($l['name'] ?? ''), 'qty' => (float) ($l['qty'] ?? $l['quantity'] ?? 1),
                            'price' => (float) ($l['price'] ?? $l['unit_price'] ?? 0), 'unit' => (string) ($l['unit'] ?? ''),
                            'product_id' => isset($l['product_id']) ? (int) $l['product_id'] : null];
            }
        }
        if (! $lines && trim((string) $o->items_text) !== '') {
            $lines[] = ['name' => (string) $o->items_text, 'qty' => 1, 'price' => 0, 'unit' => '', 'product_id' => null];
        }

        $lines = $this->decorate($t, $lines);

        $terms = trim((string) $t->setting('order_confirm_terms', ''));
        if ($terms === '') {
            $paid = (float) ($o->amount_paid ?? 0);
            $terms = $paid > 0.005
                ? ('already paid ' . self::money($paid, $cur) . ', balance on delivery')
                : 'due on delivery';
        }

        return [
            'order_no'      => (string) $o->order_no,
            'order_date'    => $o->created_at ? $o->created_at->format('d M Y') : '',
            'lines'         => $lines,
            'total'         => (float) $o->total,
            'currency'      => $cur,
            'address'       => trim((string) ($o->location ?? '')),
            // There is no scheduled-delivery field on orders today (`delivery_date` elsewhere in
            // this codebase is derived from delivered_at, which is the past, not a promise). Left
            // blank deliberately rather than inventing a date we cannot keep; text() omits it.
            'delivery_date' => '',
            'terms'         => $terms,
            'contact'       => trim((string) ($t->setting('public_phone', '') ?: ($t->whatsapp_number ?? ''))),
        ];
    }

    /**
     * v4.44 — hang the product's photo and its pack rule on each line.
     *
     * WHY THIS IS NOT PART OF THE DEAL
     * `canonical()` reads name, quantity, price, total, currency, address and terms — and nothing
     * else. A photo swapped or a pack label tidied is NOT a change to what was agreed, so it must
     * never invalidate a confirmation the customer already gave. Anything added here is decoration
     * that helps them recognise the goods; the moment one of these fields could change the
     * fingerprint, re-photographing a product would silently void live agreements.
     *
     * ONE query for ids and ONE for names, never per line — an order with 30 lines must not become
     * 30 lookups on a page a customer is waiting for.
     */
    private function decorate(Tenant $t, array $lines): array
    {
        if (! $lines) {
            return $lines;
        }
        try {
            $ids   = array_values(array_filter(array_map(fn ($l) => $l['product_id'] ?? null, $lines)));
            $names = array_values(array_filter(array_map(
                fn ($l) => ($l['product_id'] ?? null) ? null : trim((string) ($l['name'] ?? '')), $lines)));

            $cols = ['id', 'name', 'image_url', 'pack_size', 'unit_label', 'moq'];
            $byId = $ids ? \App\Models\Product::where('tenant_id', $t->id)->whereIn('id', $ids)->get($cols)->keyBy('id') : collect();
            // Lines that never carried a product_id (older AI-captured orders) still get a photo when
            // the name matches the catalogue exactly. Best effort — a miss just means no thumbnail.
            $byName = $names ? \App\Models\Product::where('tenant_id', $t->id)->whereIn('name', $names)->get($cols)
                ->keyBy(fn ($p) => mb_strtolower(trim((string) $p->name))) : collect();

            foreach ($lines as $i => $l) {
                $p = ($l['product_id'] ?? null) ? ($byId[$l['product_id']] ?? null)
                                                : ($byName[mb_strtolower(trim((string) ($l['name'] ?? '')))] ?? null);
                $lines[$i]['image'] = $p ? $this->absoluteImage($t, (string) $p->image_url) : '';
                $lines[$i]['pack']  = $p ? self::packLabel((string) $p->pack_size, (string) $p->unit_label, (float) $p->moq) : '';
            }
        } catch (\Throwable $e) {
            foreach ($lines as $i => $l) {
                $lines[$i]['image'] = $lines[$i]['image'] ?? '';
                $lines[$i]['pack']  = $lines[$i]['pack'] ?? '';
            }
        }

        return $lines;
    }

    /**
     * "carton of 6pc · min 5" — the pack rule as its own quiet line.
     *
     * These belong in `pack_size` / `unit_label` / `moq` (real columns since v4.28), not baked into
     * the product name. A name like "ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, USD 165.00 MIN 5
     * CARTON)" puts a price inside the name, so the confirmation page shows that price beside the
     * unit price AND the line total — three prices in one row on a page whose whole job is "check
     * this is right". Filling the columns lets the name be just the product.
     */
    public static function packLabel(string $packSize, string $unitLabel, float $moq): string
    {
        $bits = [];
        $pack = self::squash($packSize);
        $unit = self::squash($unitLabel);
        if ($pack !== '') {
            $bits[] = $unit !== '' ? ($unit . ' of ' . $pack) : $pack;
        } elseif ($unit !== '') {
            $bits[] = $unit;
        }
        if ($moq > 1) {
            $bits[] = 'min ' . rtrim(rtrim(number_format($moq, 2, '.', ''), '0'), '.');
        }

        return implode(' · ', $bits);
    }

    /** A stored path becomes a real URL; anything already absolute is left alone. */
    private function absoluteImage(Tenant $t, string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, 'data:')) {
            return $url;
        }

        return $t->publicUrl($url);
    }

    // ── sending ────────────────────────────────────────────────────────────────────────

    /**
     * Build, record and send the confirmation. Idempotent per version of the order: sending
     * twice for an unchanged order is a no-op, while a genuinely changed order supersedes the
     * previous confirmation and asks the customer again.
     */
    public function send(Tenant $t, Order $o, string $trigger = 'confirmed'): array
    {
        try {
            if (! (bool) $t->setting('notify_order_confirmation', true)) {
                return $this->no('switched off in Settings');
            }
            $phone = (string) preg_replace('/[^0-9]/', '', (string) $o->customer_phone);
            if ($phone === '') {
                return $this->no('no customer phone on the order');
            }

            $snap = $this->snapshot($t, $o);
            if (! ($snap['lines'] ?? [])) {
                return $this->no('order has no lines to confirm');
            }
            $fp = self::fingerprint($snap);

            $existing = OrderConfirmation::where('tenant_id', $t->id)->where('order_id', $o->id)
                ->where('fingerprint', $fp)->first();
            if ($existing && $existing->sent_at) {
                return $this->no('already sent on ' . $existing->sent_at->toDateTimeString());
            }

            $row = $existing ?: new OrderConfirmation([
                'tenant_id' => $t->id, 'order_id' => (int) $o->id, 'order_no' => (string) $o->order_no,
                'token' => bin2hex(random_bytes(16)), 'snapshot' => $snap, 'fingerprint' => $fp, 'trigger' => $trigger,
            ]);
            $row->tenant_id = $t->id;
            $row->save();

            $link = (bool) $t->setting('order_confirm_link', true)
                ? $t->publicUrl('/o/' . $row->token)
                : '';
            $text = self::text($snap, [
                'business'    => (string) $t->name,
                'first_name'  => \App\Support\HumanText::firstName((string) ($o->customer_name ?? '')),
                'confirm_url' => $link,
            ]);

            $ok = $this->deliver($t, $phone, $text);
            $row->message_text = $text;
            if ($ok) {
                $row->sent_at = now();
                $row->send_error = null;
            } else {
                $row->send_error = 'WhatsApp send failed';
            }
            $row->save();

            if ($ok) {
                // Everything the customer has NOT re-agreed to is now stale.
                OrderConfirmation::where('tenant_id', $t->id)->where('order_id', $o->id)
                    ->where('id', '!=', $row->id)->whereNull('superseded_at')
                    ->update(['superseded_at' => now()]);
                $this->trace($t, $phone, 'confirmation_sent', (string) $o->order_no . ' · ' . substr($fp, 0, 12));

                return ['sent' => true, 'reason' => '', 'id' => (int) $row->id];
            }

            return $this->no('WhatsApp send failed');
        } catch (\Throwable $e) {
            \Log::warning('OrderConfirmationService send failed: ' . $e->getMessage());

            return $this->no('error: ' . $e->getMessage());
        }
    }

    /** Record acceptance. Returns false when it was already confirmed (never re-stamps). */
    public function accept(OrderConfirmation $row, string $via, ?string $ip = null, ?string $by = null, ?string $note = null): bool
    {
        if ($row->confirmed_at !== null) {
            return false;
        }
        $row->confirmed_at  = now();
        $row->confirmed_via = in_array($via, ['link', 'whatsapp', 'staff'], true) ? $via : 'link';
        $row->confirmed_ip  = $ip ? substr($ip, 0, 45) : null;
        $row->confirmed_by  = $by ? substr($by, 0, 120) : null;
        $row->confirmed_note = $note ? mb_substr($note, 0, 500) : null;
        $row->save();

        return true;
    }

    /**
     * A short affirmative reply from a customer counts as acceptance of the confirmation we
     * sent them — but only the newest unconfirmed one, only inside the window, and only when
     * the message is unambiguous. Called on the inbound path; never blocks the bot.
     */
    public static function noteInboundYes(int $tenantId, string $phone, string $text): bool
    {
        try {
            if (! self::looksLikeYes($text)) {
                return false;
            }
            $digits = (string) preg_replace('/[^0-9]/', '', $phone);
            if ($digits === '') {
                return false;
            }
            $row = OrderConfirmation::where('tenant_id', $tenantId)
                ->whereNull('confirmed_at')->whereNull('superseded_at')
                ->whereNotNull('sent_at')
                ->where('sent_at', '>=', now()->subHours(self::YES_WINDOW_HOURS))
                ->whereIn('order_id', \App\Models\Order::where('tenant_id', $tenantId)
                    ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
                    ->pluck('id'))
                ->orderByDesc('sent_at')->first();
            if (! $row) {
                return false;
            }

            return app(self::class)->accept($row, 'whatsapp', null, null, $text);
        } catch (\Throwable $e) {
            return false;   // never break message handling over a courtesy stamp
        }
    }

    // ── reading state for the staff screen ─────────────────────────────────────────────

    /**
     * What the team needs to know BEFORE they load the truck:
     *   none      — nothing was ever sent
     *   sent      — sent, customer has not agreed yet
     *   confirmed — customer agreed, and the order still matches what they agreed to
     *   changed   — customer agreed to a DIFFERENT version; the order was edited since
     */
    public function stateFor(Tenant $t, Order $o): array
    {
        try {
            $rows = OrderConfirmation::where('tenant_id', $t->id)->where('order_id', $o->id)
                ->orderByDesc('id')->get();
            if ($rows->isEmpty()) {
                return ['state' => 'none', 'label' => 'Not sent to the customer', 'tone' => 'warn'];
            }
            $live = self::fingerprint($this->snapshot($t, $o));
            $agreed = $rows->firstWhere(fn ($r) => $r->confirmed_at !== null);

            if ($agreed && $agreed->fingerprint === $live) {
                return ['state' => 'confirmed', 'tone' => 'ok',
                    'label' => 'Customer confirmed ' . $agreed->confirmed_at->format('d M H:i')
                             . ' (' . ($agreed->confirmed_via === 'whatsapp' ? 'replied on WhatsApp' : ($agreed->confirmed_via === 'staff' ? 'recorded by staff' : 'tapped the link')) . ')',
                    'at' => $agreed->confirmed_at->toDateTimeString(), 'via' => (string) $agreed->confirmed_via];
            }
            if ($agreed) {
                return ['state' => 'changed', 'tone' => 'danger',
                    'label' => 'This order was CHANGED after the customer confirmed it on ' . $agreed->confirmed_at->format('d M H:i') . ' — send it again',
                    'at' => $agreed->confirmed_at->toDateTimeString(), 'via' => (string) $agreed->confirmed_via];
            }
            $latest = $rows->first();
            if ($latest->fingerprint !== $live) {
                return ['state' => 'none', 'tone' => 'warn', 'label' => 'Order changed since the last message — nothing confirmed'];
            }

            return ['state' => 'sent', 'tone' => 'warn',
                'label' => 'Sent ' . ($latest->sent_at ? $latest->sent_at->format('d M H:i') : '—') . ' — customer has not confirmed yet'];
        } catch (\Throwable $e) {
            return ['state' => 'none', 'label' => 'Confirmation status unavailable', 'tone' => ''];
        }
    }

    // ── plumbing ───────────────────────────────────────────────────────────────────────

    private function deliver(Tenant $t, string $phone, string $text): bool
    {
        $inst = $t->sendInstance();
        if (trim($inst) === '') {
            return false;
        }
        try {
            app(WhatsAppManager::class)->forTenant($t)->sendText($inst, $phone, $text);
            MessageLog::record($t->id, $phone, $inst, 'out', 'system', $text);

            return true;
        } catch (\Throwable $e) {
            \Log::warning('OrderConfirmationService deliver failed: ' . $e->getMessage());

            return false;
        }
    }

    private function trace(Tenant $t, string $phone, string $event, string $detail): void
    {
        try {
            \App\Support\BotTrace::log($t->id, 'order-confirm', $phone, $event, $detail);
        } catch (\Throwable $e) { /* tracing must never break a send */ }
    }

    private function no(string $reason): array
    {
        return ['sent' => false, 'reason' => $reason];
    }
}
