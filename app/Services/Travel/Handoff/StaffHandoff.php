<?php

namespace App\Services\Travel\Handoff;

use App\Models\Tenant;
use App\Models\TravelEnquiry;
use App\Models\TravelEnquiryNotification;
use App\Models\WhatsappChannel;
use App\Services\Travel\Enquiry\EnquiryBrief;
use App\Services\Travel\Enquiry\EnquirySlots;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Support\Facades\Log;

/**
 * Hands a travel enquiry to the agency's own people (issue #145).
 *
 * The staff numbers are a tenant setting of their own (`travel_staff_whatsapp`), separate from the
 * number customers write to. This class is the only place that sends an enquiry to staff, and it:
 *   - never sends to a number the agency publishes to customers, even if someone enters it;
 *   - records every attempt, so a failed alert is visible and can be retried;
 *   - never sends the same enquiry content twice to the same person;
 *   - reports honestly whether anyone was actually told.
 *
 * With no staff number configured the enquiry is still saved; nothing is sent anywhere, and the
 * panel shows a warning.
 */
final class StaffHandoff
{
    public const SETTING = 'travel_staff_whatsapp';
    public const MAX_RECIPIENTS = 10;
    public const MAX_ATTEMPTS = 6;

    /**
     * PURE. Turn what an admin typed into the numbers that may be alerted.
     *
     * @param  string[] $publicNumbers  numbers the agency publishes to customers (digits)
     * @return array{recipients:string[],rejected:array<int,array{number:string,reason:string}>}
     */
    public static function parseRecipients(string $raw, array $publicNumbers = []): array
    {
        $public = array_values(array_filter(array_map(fn ($n) => preg_replace('/[^0-9]/', '', (string) $n) ?? '', $publicNumbers)));
        $out = [];
        $rejected = [];
        foreach (preg_split('/[,;\r\n]+/', $raw) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $digits = preg_replace('/[^0-9]/', '', $entry) ?? '';
            if (strlen($digits) < 9 || strlen($digits) > 15) {
                $rejected[] = ['number' => mb_substr($entry, 0, 24), 'reason' => 'not a full phone number'];
                continue;
            }
            if ($digits[0] === '0') {
                $rejected[] = ['number' => $digits, 'reason' => 'needs the country code (for example 2567…)'];
                continue;
            }
            $isPublic = false;
            foreach ($public as $p) {
                if ($p !== '' && ($p === $digits || (strlen($p) >= 9 && substr($p, -9) === substr($digits, -9)))) {
                    $isPublic = true;
                }
            }
            if ($isPublic) {
                $rejected[] = ['number' => $digits, 'reason' => 'this is the number customers write to; staff alerts are never sent there'];
                continue;
            }
            if (! in_array($digits, $out, true)) {
                $out[] = $digits;
            }
        }
        if (count($out) > self::MAX_RECIPIENTS) {
            foreach (array_slice($out, self::MAX_RECIPIENTS) as $extra) {
                $rejected[] = ['number' => $extra, 'reason' => 'only the first ' . self::MAX_RECIPIENTS . ' numbers are used'];
            }
            $out = array_slice($out, 0, self::MAX_RECIPIENTS);
        }

        return ['recipients' => $out, 'rejected' => $rejected];
    }

    /** The numbers customers write to: the agency's WhatsApp number(s). */
    public function publicNumbers(Tenant $tenant): array
    {
        $nums = [(string) $tenant->whatsapp_number];
        try {
            foreach (WhatsappChannel::query()->where('tenant_id', $tenant->id)->pluck('number') as $n) {
                $nums[] = (string) $n;
            }
        } catch (\Throwable $e) {
            // the channels table is optional
        }

        return array_values(array_filter(array_unique($nums)));
    }

    /**
     * @return array{configured:bool,recipients:string[],rejected:array<int,array{number:string,reason:string}>}
     */
    public function status(Tenant $tenant): array
    {
        $p = self::parseRecipients((string) $tenant->setting(self::SETTING, ''), $this->publicNumbers($tenant));

        return ['configured' => $p['recipients'] !== [], 'recipients' => $p['recipients'], 'rejected' => $p['rejected']];
    }

    /** PURE. The alert a member of staff receives. Built from the stored record, never by the model. */
    public static function message(TravelEnquiry $enquiry, string $kind, string $panelUrl = ''): string
    {
        $state = $enquiry->state();
        $who   = trim((string) $enquiry->customer_name);
        $via   = $enquiry->channel === 'web' ? 'website chat' : 'WhatsApp';
        $phone = $enquiry->channel === 'web' ? '' : '+' . $enquiry->customer_phone;
        $lines = [
            ($kind === 'updated' ? '✈️ *Travel enquiry updated* ' : '✈️ *New travel enquiry* ') . $enquiry->reference(),
            'Customer: ' . trim(implode(' · ', array_filter([$who, $phone]))) . ' (' . $via . ')',
        ];
        $contact = EnquiryBrief::contactLine($state);
        if ($contact !== '') {
            $lines[] = 'Contact given: ' . $contact;
        }
        foreach (EnquirySlots::summaryLines($state) as $l) {
            $lines[] = $l;
        }
        if (EnquirySlots::isGroup($state)) {
            $lines[] = 'Group (10 or more travellers): please handle personally.';
        }
        $lines[] = 'No price has been given to the customer. Please prepare the quotation from confirmed rates.';
        if ($panelUrl !== '') {
            $lines[] = 'Open: ' . rtrim($panelUrl, '/') . '/v3#/trip/' . (int) $enquiry->id;
        }

        return implode("\n", $lines);
    }

    /**
     * Send the enquiry to every configured member of staff who has not already had this content.
     *
     * @return array{recipients:int,sent:int,failed:int,no_recipient:bool,already:int}
     */
    public function notify(Tenant $tenant, TravelEnquiry $enquiry, string $kind = 'new'): array
    {
        $status = $this->status($tenant);
        if (! $status['configured']) {
            $this->setStatus($enquiry, 'no_recipient');
            $this->log($enquiry, 'handoff_no_recipient', []);

            return ['recipients' => 0, 'sent' => 0, 'failed' => 0, 'no_recipient' => true, 'already' => 0];
        }

        $hash = self::stateHash($enquiry);
        $text = self::message($enquiry, $kind, (string) config('app.url', ''));
        $sent = 0; $failed = 0; $already = 0;
        foreach ($status['recipients'] as $number) {
            $row = TravelEnquiryNotification::withoutGlobalScopes()->firstOrCreate(
                ['enquiry_id' => $enquiry->id, 'recipient' => $number, 'state_hash' => $hash],
                ['tenant_id' => $tenant->id, 'kind' => $kind, 'status' => 'pending', 'attempts' => 0],
            );
            if ($row->status === 'sent') {
                $already++;
                continue;                                   // this person already has exactly this content
            }
            $this->attempt($tenant, $row, $text) ? $sent++ : $failed++;
        }
        $this->refreshStatus($enquiry);
        $this->log($enquiry, $failed > 0 && $sent === 0 && $already === 0 ? 'handoff_failed' : 'handoff_sent',
            ['kind' => $kind, 'sent' => $sent, 'failed' => $failed, 'recipients' => count($status['recipients'])]);

        return ['recipients' => count($status['recipients']), 'sent' => $sent, 'failed' => $failed, 'no_recipient' => false, 'already' => $already];
    }

    /**
     * Try again: every alert for this enquiry that has not been delivered. If no staff number was set
     * when the customer submitted and one is set now, the enquiry is sent for the first time.
     *
     * @return array{recipients:int,sent:int,failed:int,no_recipient:bool,already:int}
     */
    public function retry(Tenant $tenant, TravelEnquiry $enquiry): array
    {
        $rows = TravelEnquiryNotification::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('enquiry_id', $enquiry->id)->where('status', '!=', 'sent')->get();
        if ($rows->isEmpty()) {
            return $this->notify($tenant, $enquiry, 'new');
        }
        $allowed = $this->status($tenant)['recipients'];
        $text = self::message($enquiry, 'new', (string) config('app.url', ''));
        $sent = 0; $failed = 0;
        foreach ($rows as $row) {
            if (! in_array((string) $row->recipient, $allowed, true)) {
                continue;                                   // no longer a configured staff number: never send
            }
            $this->attempt($tenant, $row, $text) ? $sent++ : $failed++;
        }
        $this->refreshStatus($enquiry);
        $this->log($enquiry, $sent > 0 ? 'handoff_sent' : 'handoff_failed', ['kind' => 'retry', 'sent' => $sent, 'failed' => $failed]);

        return ['recipients' => count($allowed), 'sent' => $sent, 'failed' => $failed, 'no_recipient' => $allowed === [], 'already' => 0];
    }

    /** Retry undelivered alerts across all tenants (scheduled). Returns how many were delivered. */
    public function retryDue(int $limit = 50): int
    {
        $delivered = 0;
        $rows = TravelEnquiryNotification::withoutGlobalScopes()
            ->where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('updated_at', '<', now()->subMinutes(5))->orderBy('id')->limit($limit)->get();
        foreach ($rows->groupBy('enquiry_id') as $enquiryId => $group) {
            $enquiry = TravelEnquiry::withoutGlobalScopes()->find($enquiryId);
            $tenant  = $enquiry ? Tenant::find($enquiry->tenant_id) : null;
            if (! $enquiry || ! $tenant) {
                continue;
            }
            $allowed = $this->status($tenant)['recipients'];
            $text = self::message($enquiry, 'new', (string) config('app.url', ''));
            foreach ($group as $row) {
                if (in_array((string) $row->recipient, $allowed, true) && $this->attempt($tenant, $row, $text)) {
                    $delivered++;
                }
            }
            $this->refreshStatus($enquiry);
        }

        return $delivered;
    }

    /** What the content of an enquiry is, for "has this person already been told exactly this?". */
    public static function stateHash(TravelEnquiry $enquiry): string
    {
        $state = $enquiry->state();
        unset($state['pending']);

        return sha1((string) json_encode(self::canonical($state)));
    }

    /**
     * PURE. The same content in one fixed shape: named fields sorted by name at every depth, lists left
     * in their order (the order of destinations is content). PostgreSQL's jsonb does not keep the key
     * order it was given, so without this the same enquiry could hash differently before and after it
     * is read back, and a member of staff could be alerted twice about the same thing.
     */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'canonical'], $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    // ---------------------------------------------------------------------------------------------

    private function attempt(Tenant $tenant, TravelEnquiryNotification $row, string $text): bool
    {
        $error = null;
        try {
            $instance = (string) $tenant->sendInstance();
            if ($instance === '') {
                $error = 'no WhatsApp number is connected to send from';
            } else {
                $res = app(WhatsAppManager::class)->forTenant($tenant)->sendText($instance, (string) $row->recipient, $text);
                if (is_array($res) && array_key_exists('_ok', $res) && $res['_ok'] === false) {
                    $error = 'WhatsApp refused the message (status ' . (string) ($res['_status'] ?? '?') . ')';
                }
            }
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 180);
        }
        $row->attempts = (int) $row->attempts + 1;
        $row->status = $error === null ? 'sent' : 'failed';
        $row->last_error = $error;
        $row->sent_at = $error === null ? now() : $row->sent_at;
        $row->save();
        if ($error !== null) {
            Log::warning('travel: staff alert failed', ['enquiry' => $row->enquiry_id, 'error' => $error]);
        }

        return $error === null;
    }

    private function refreshStatus(TravelEnquiry $enquiry): void
    {
        $rows = TravelEnquiryNotification::withoutGlobalScopes()->where('enquiry_id', $enquiry->id)->pluck('status');
        $sent = $rows->filter(fn ($s) => $s === 'sent')->count();
        $bad  = $rows->count() - $sent;
        $this->setStatus($enquiry, $rows->isEmpty() ? 'no_recipient' : ($bad === 0 ? 'sent' : ($sent === 0 ? 'failed' : 'partial')));
    }

    private function setStatus(TravelEnquiry $enquiry, string $status): void
    {
        if ($enquiry->handoff_status !== $status) {
            $enquiry->handoff_status = $status;
            $enquiry->save();
        }
    }

    private function log(TravelEnquiry $enquiry, string $type, array $meta): void
    {
        try {
            \App\Models\TravelEnquiryEvent::create([
                'tenant_id' => $enquiry->tenant_id, 'enquiry_id' => $enquiry->id, 'type' => $type,
                'actor' => 'system', 'meta' => $meta ?: null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('travel: handoff event failed: ' . $e->getMessage());
        }
    }
}
