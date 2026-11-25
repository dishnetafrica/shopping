<?php

namespace App\Services\WhatsApp;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappChannel;
use App\Services\Sales\SalesOwnership;
use Illuminate\Support\Facades\Cache;

/**
 * The business's WhatsApp numbers, managed from V3 (DishNet, 25 Nov 2026: "where can I add the second salesperson's
 * WhatsApp in V3?"). The classic Setup page's multi-number card is switched off, and connecting Aida's number took
 * server commands. Here: list the numbers (status, who each belongs to), add one, get a link code, set its owner.
 *
 * Linking is by an 8-character code typed on the phone (WhatsApp → Linked devices → Link a device → Link with phone
 * number instead), so V3 needs no QR image. A code lasts a few minutes; "New link code" gets another.
 *
 * Every number of the business and its salespeople's phones go on the bot's mute list, so a staff alert sent from one
 * line to another is never answered by the bot (two lines replying to each other).
 */
class WhatsAppLines
{
    public const CODE_MINUTES = 3;

    public function __construct(private EvolutionAdmin $evo, private SalesOwnership $owners) {}

    /**
     * @return list<array{instance:string,number:string,main:bool,state:string,status:string,owner:string,code:?string}>
     */
    public function lines(Tenant $t): array
    {
        $t->syncPrimaryChannel();
        $out = [];
        foreach (WhatsappChannel::where('tenant_id', $t->id)->where('active', true)->orderByDesc('is_primary')->orderBy('id')->get() as $c) {
            $info = $c->driver === 'evolution' && $this->evo->configured()
                ? Cache::remember("wa_line_info:{$c->instance}", 60, fn () => $this->evo->instanceInfo((string) $c->instance))
                : [];
            $live = preg_replace('/\D/', '', (string) ($info['number'] ?? '')) ?? '';
            if ($live !== '' && $live !== (string) $c->number) {
                $c->number = $live;                        // the linked phone is the truth (as the classic screen did)
                $c->save();
            }
            if ($live !== '' && $c->is_primary && $live !== (string) $t->whatsapp_number) {
                $t->whatsapp_number = $live;               // the connection card and contact links read it (Codex on #181)
                $t->save();
            }
            $state = $c->driver === 'evolution' ? strtolower((string) ($info['state'] ?? 'unknown')) : 'n/a';
            $owner = $c->owner_user_id ? (string) (User::where('tenant_id', $t->id)->whereKey($c->owner_user_id)->value('name') ?? '') : '';
            $out[] = [
                'instance' => (string) $c->instance,
                'number' => (string) ($c->number ?: ''),
                'main' => (bool) $c->is_primary,
                'state' => $state,
                'status' => self::status($state),
                'owner' => $owner !== '' ? $owner : 'The business',
                'code' => Cache::get(self::codeKey($t->id, (string) $c->instance)),
            ];
        }

        return $out;
    }

    /** Add a number: a new line for this business, its webhook, who it belongs to, and a link code. */
    public function add(Tenant $t, string $number, string $label, ?int $ownerId): array
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        if (strlen($digits) < 9) {
            return ['ok' => false, 'message' => 'Enter the full WhatsApp number with the country code, e.g. 211921443004.'];
        }
        if (! $this->evo->configured()) {
            return ['ok' => false, 'message' => 'WhatsApp (Evolution) is not configured on this server.'];
        }
        if (WhatsappChannel::where('tenant_id', $t->id)->where('number', $digits)->where('active', true)->exists()) {
            return ['ok' => false, 'message' => "+{$digits} is already one of your numbers. Use “New link code” to link it again."];
        }
        $owner = null;
        if ($ownerId) {
            $owner = $this->salesperson($t, $ownerId);
            if (! $owner) {
                return ['ok' => false, 'message' => 'A number can belong only to a salesperson (role Sales, with a WhatsApp number).'];
            }
        }

        $instance = self::nextInstance((int) $t->id, WhatsappChannel::where('instance', 'like', 'shopbot_t' . $t->id . '_%')->pluck('instance')->all());
        $this->evo->createIfMissing($instance);
        $this->evo->setWebhook($instance, $this->webhookUrl($t));
        $c = WhatsappChannel::create([
            'tenant_id' => $t->id, 'instance' => $instance, 'number' => $digits, 'driver' => 'evolution',
            'label' => mb_substr(trim($label) !== '' ? trim($label) : ($owner?->name ?? 'Number'), 0, 40),
            'is_primary' => false, 'active' => true,
        ]);
        $c->owner_user_id = $owner?->id;
        $c->save();
        $this->muteOwnNumbers($t, [$digits, (string) $owner?->phone]);

        $code = $this->code($t, $instance, $digits);

        return ['ok' => true, 'message' => $code
            ? "Added +{$digits}. On that phone: WhatsApp → Linked devices → Link a device → Link with phone number instead, and type {$code}."
            : "Added +{$digits}. No link code came back: use “New link code”, or scan the QR for {$instance} in the Evolution manager."];
    }

    /** A new link code for one of the numbers (to link it again). */
    public function newCode(Tenant $t, string $instance): array
    {
        $c = WhatsappChannel::where('tenant_id', $t->id)->where('instance', $instance)->first();
        if (! $c || (string) $c->number === '') {
            return ['ok' => false, 'message' => 'Choose one of your numbers.'];
        }
        Cache::forget("wa_line_info:{$instance}");
        $code = $this->code($t, $instance, (string) $c->number);

        return $code
            ? ['ok' => true, 'message' => "Link code for +{$c->number}: {$code} (shown on this screen for " . self::CODE_MINUTES . ' minutes).']
            : ['ok' => false, 'message' => "No link code came back for +{$c->number}. It may already be linked, or scan the QR in the Evolution manager."];
    }

    /** Who the number belongs to: a salesperson, or the business (null). */
    public function setOwner(Tenant $t, string $instance, ?int $ownerId): array
    {
        $c = WhatsappChannel::where('tenant_id', $t->id)->where('instance', $instance)->first();
        if (! $c) {
            return ['ok' => false, 'message' => 'Choose one of your numbers.'];
        }
        $owner = $ownerId ? $this->salesperson($t, $ownerId) : null;
        if ($ownerId && ! $owner) {
            return ['ok' => false, 'message' => 'A number can belong only to a salesperson (role Sales, with a WhatsApp number).'];
        }
        $c->owner_user_id = $owner?->id;
        $c->save();
        if ($owner) {
            $this->muteOwnNumbers($t, [(string) $owner->phone]);
        }

        return ['ok' => true, 'message' => $owner
            ? '+' . $c->number . " now belongs to {$owner->name}: new customers who write to it become {$owner->name}'s."
            : '+' . $c->number . ' now belongs to the business.'];
    }

    /** PURE. What the owner reads for a line's state. */
    public static function status(string $state): string
    {
        return match ($state) {
            'open' => 'Connected',
            'connecting' => 'Waiting for the phone',
            'close', 'closed', 'missing' => 'Not linked',
            'n/a' => 'Official API',
            default => 'Unknown',
        };
    }

    /** PURE. The next free line name: shopbot_t31_2, _3, … */
    public static function nextInstance(int $tenantId, array $taken): string
    {
        $n = 2;
        while (in_array("shopbot_t{$tenantId}_{$n}", $taken, true)) {
            $n++;
        }

        return "shopbot_t{$tenantId}_{$n}";
    }

    /** PURE. The leading id of a pick-list choice ("66 — Aida" → 66; "The business" → null). */
    public static function pickedId(?string $choice): ?int
    {
        return preg_match('/^\s*(\d+)/', (string) $choice, $m) ? (int) $m[1] : null;
    }

    private static function codeKey(int $tenantId, string $instance): string
    {
        return "wa_link_code:{$tenantId}:{$instance}";
    }

    private function code(Tenant $t, string $instance, string $number): ?string
    {
        $code = $this->evo->pairingCode($instance, $number);
        if ($code !== null) {
            Cache::put(self::codeKey((int) $t->id, $instance), $code, now()->addMinutes(self::CODE_MINUTES));
        }

        return $code;
    }

    private function salesperson(Tenant $t, int $id): ?User
    {
        return collect($this->owners->salespeople($t))->firstWhere('id', $id);
    }

    /** Where the main line sends its events — the new line uses the same address. */
    private function webhookUrl(Tenant $t): string
    {
        $w = $this->evo->getWebhook((string) $t->whatsapp_instance);
        $url = (string) (data_get($w, 'url') ?: data_get($w, 'webhook.url') ?: '');

        return $url !== '' ? $url : url('/api/webhook/whatsapp/evolution');
    }

    /** The business's own numbers (and its salespeople's phones) never get bot replies: no line answers another. */
    private function muteOwnNumbers(Tenant $t, array $numbers): void
    {
        $d = fn ($p) => preg_replace('/\D/', '', (string) $p) ?? '';
        // Every salesperson, not only this number's owner: any of them may answer an alert (Codex on #181).
        $own = array_merge($numbers, WhatsappChannel::where('tenant_id', $t->id)->where('active', true)->pluck('number')->all(),
            array_map(fn ($u) => (string) $u->phone, $this->owners->salespeople($t)));
        $muted = array_values(array_unique(array_filter(array_map($d, array_merge((array) $t->setting('bot_muted', []), $own)), fn ($p) => strlen($p) >= 9)));
        $t->putSetting('bot_muted', $muted);
    }
}
