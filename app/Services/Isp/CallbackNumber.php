<?php

namespace App\Services\Isp;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Support\BotTrace;
use Illuminate\Support\Facades\Cache;

/**
 * A number a salesperson can actually call (DishNet, 18 Nov 2026: "when it sends a message to sales or
 * support staff they don't get real numbers").
 *
 * WhatsApp hides many customers' phone numbers: the business sees a "hidden id" (LID), and ShopBot keys
 * the chat by its digits — 2,331 of DishNet's 2,372 past incoming messages came from such chats. Those
 * digits are not a phone number, so an alert saying "+234239681409272, please call them" cannot be acted on.
 *
 *   - a chat is HIDDEN when the customer's messages arrive from an "@lid" id (stored on the conversation as
 *     state.wa_hidden), or when WhatsApp only accepted our message addressed to "@lid" (EvolutionGateway).
 *   - their CALLABLE number is the profile's alt_phone: filled (only when empty, so a number staff typed is
 *     never replaced) from the real number WhatsApp sometimes attaches to the message, or from a phone number
 *     the customer types in the chat — the bot asks for one when it says someone will call (IspSalesTurn).
 *   - label() is what every salesperson alert shows: the real number, or plainly "WhatsApp hides this
 *     customer's number — reply in the ShopBot inbox".
 */
class CallbackNumber
{
    /** PURE. A phone number the customer typed ("my number is 0912 345 678", "+211 912 345 678"), digits only. */
    public static function extract(string $text): ?string
    {
        if (! preg_match_all('/(?<![\d$])(\+|00)?(\d[\d \-.()]{7,20}\d)(?!\d)/u', $text, $m, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($m as $hit) {
            $plus = $hit[1] !== '';
            $digits = preg_replace('/\D/', '', $hit[2]) ?? '';
            $len = strlen($digits);
            // A local number (0 + 8–10 digits), or an international one (+ / 00 / a long number with its country code).
            $local = $digits[0] === '0' && $len >= 9 && $len <= 11;
            $intl = ($plus || $len >= 11) && $len >= 10 && $len <= 13 && $digits[0] !== '0';
            if ($local || $intl) {
                return $digits;
            }
        }

        return null;
    }

    /** PURE. How a number reads in an alert: "+211912345678", or a local "0912345678" as typed. */
    public static function display(string $digits): string
    {
        return str_starts_with($digits, '0') ? $digits : '+' . $digits;
    }

    /** PURE. The alert line for a customer. */
    public static function labelFor(string $chatId, bool $hidden, ?string $callable): string
    {
        if (! $hidden) {
            return self::display($chatId);
        }
        if ($callable !== null && $callable !== '') {
            return self::display($callable) . ' (WhatsApp hides their number in the chat — call this one)';
        }

        return "WhatsApp hides this customer's number — reply to them in the ShopBot inbox (chat " . $chatId . ')';
    }

    public static function isHidden(Tenant $t, string $chatId): bool
    {
        $chatId = self::digits($chatId);
        if ($chatId === '') {
            return false;
        }
        if (Cache::has('wa_lid:' . $chatId)) {
            return true;
        }
        $state = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $chatId)->value('state');
        $state = is_string($state) ? (json_decode($state, true) ?: []) : (array) $state;

        return ! empty($state['wa_hidden']);
    }

    /** The number staff can call: the profile's alt_phone for a hidden chat, else the chat number itself. */
    public static function callable(Tenant $t, string $chatId): ?string
    {
        $alt = self::digits((string) CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', self::digits($chatId))->value('alt_phone'));
        if (strlen($alt) >= 8) {
            return $alt;
        }

        return self::isHidden($t, $chatId) ? null : self::digits($chatId);
    }

    public static function label(Tenant $t, string $chatId): string
    {
        try {
            $hidden = self::isHidden($t, $chatId);

            return self::labelFor(self::digits($chatId), $hidden, $hidden ? self::callable($t, $chatId) : null);
        } catch (\Throwable $e) {
            report($e);

            return '+' . self::digits($chatId);
        }
    }

    /**
     * A customer's message as the provider parsed it (EvolutionGateway: 'hidden', 'phone_alt'): remember a
     * hidden chat and the real number WhatsApp attached. Internet providers only; never breaks the message.
     */
    public static function fromIncoming(Tenant $t, Conversation $convo, array $incoming): void
    {
        if (empty($incoming['hidden']) || ! \App\Services\Sales\SalesOwnership::enabled($t)) {
            return;
        }
        try {
            self::markHidden($convo);
            if ((string) ($incoming['phone_alt'] ?? '') !== '') {
                self::remember($t, (string) ($incoming['from'] ?? ''), (string) $incoming['phone_alt'], 'whatsapp');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** A message arrived from a hidden id: remember it (once) on the conversation. */
    public static function markHidden(Conversation $convo): void
    {
        $state = is_array($convo->state) ? $convo->state : [];
        if (empty($state['wa_hidden'])) {
            $state['wa_hidden'] = true;
            $convo->state = $state;
            $convo->save();
        }
    }

    /** Save a callable number for this customer, unless one is already on the profile. Returns true when saved. */
    public static function remember(Tenant $t, string $chatId, string $number, string $source): bool
    {
        $chatId = self::digits($chatId);
        $number = self::digits($number);
        if ($chatId === '' || strlen($number) < 8 || $number === $chatId) {
            return false;
        }
        $p = CustomerProfile::withoutGlobalScopes()->firstOrNew(['tenant_id' => $t->id, 'phone' => $chatId]);
        if (strlen(self::digits((string) $p->alt_phone)) >= 8) {
            return false;                                   // never replace a number staff or the customer gave
        }
        $p->alt_phone = $number;
        $p->save();
        BotTrace::log($t->id, 'isp', $chatId, 'callback_number_saved', $source);

        return true;
    }

    private static function digits(string $s): string
    {
        return preg_replace('/\D/', '', $s) ?? '';
    }
}
