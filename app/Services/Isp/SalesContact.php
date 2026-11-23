<?php

namespace App\Services\Isp;

/**
 * PURE. A customer who asks for the salesperson gets THEIR salesperson's name and number, not the general
 * sales line (DishNet, 23 Nov 2026 — "yes, give it"). Live, 21 Nov: a customer ready to pay cash wrote
 * "Connect me to the sales person" and was given the office line; their own salesperson never heard.
 *
 * The bot is told who the customer's salesperson is (rule()); when the customer asks for them and the reply
 * does not carry that number, it is added (ensure()); and the salesperson is alerted (IspSalesTurn).
 * The quotation already prints the same name and number ("Justus (+211…), your salesperson, will call you").
 */
final class SalesContact
{
    /** "connect me to the sales person", "can I talk to someone", "call me", "your number please" */
    private const ASKS = '/\b(sales ?(?:person|man|woman|rep(?:resentative)?|agent|team|officer|executive|department)|connect me|talk to (?:a |an |the |your )?(?:person|someone|somebody|human|agent|staff|manager|sales)|speak (?:to|with) (?:a |an |the |your )?(?:person|someone|somebody|human|agent|staff|manager|sales)|call me|call back|give me a call|phone me|your (?:phone|contact|mobile|whatsapp) number|number (?:to|i can) call|contact (?:of|for) (?:the )?sales)\b/u';

    /** PURE. Did the customer ask for a salesperson, a person to talk to, or a number to call? */
    public static function asks(string $customerText): bool
    {
        return (bool) preg_match(self::ASKS, mb_strtolower(IspQuotePlan::plain($customerText)));
    }

    /** PURE. "+211926134489" from whatever is stored. '' when there is no usable number. */
    public static function phone(?string $stored): string
    {
        $d = preg_replace('/\D/', '', (string) $stored) ?? '';

        return strlen($d) >= 9 ? '+' . $d : '';
    }

    /** PURE. What the customer reads. */
    public static function line(string $name, string $phone, ?string $opensAt = null): string
    {
        return "\u{1F4DE} Your salesperson is *" . trim($name) . "* ({$phone}): you can call or WhatsApp them directly"
            . ($opensAt !== null ? " when the office opens ({$opensAt})" : '') . '.';
    }

    /** PURE. Per turn, what the model is told about this customer's salesperson. */
    public static function rule(string $name, string $phone): string
    {
        return "\nTHIS CUSTOMER'S SALESPERSON: " . trim($name) . " ({$phone}). When they ask for the salesperson, the sales team, a person to talk to, or a number to call about buying, give THIS name and number — not the general sales line. (A problem with an existing service is still Technical Support: see SUPPORT.)\n";
    }

    /**
     * PURE. The reply with the salesperson's number added when the customer asked for them and the reply
     * does not carry it. Unchanged otherwise, and when the reply sends them to Technical Support.
     */
    public static function ensure(string $reply, string $customerText, string $name, string $phone, ?string $opensAt = null): string
    {
        if ($phone === '' || ! self::asks($customerText) || SupportContact::sendsToSupport($reply)) {
            return $reply;
        }
        $digits = fn (string $s) => preg_replace('/\D/', '', $s) ?? '';
        if (str_contains($digits($reply), substr($digits($phone), -9))) {
            return $reply;
        }
        $add = self::line($name, $phone, $opensAt);
        $pos = strpos($reply, '<<');                       // a hidden <<…>> line must stay last

        return $pos === false
            ? rtrim($reply) . "\n\n" . $add
            : rtrim(substr($reply, 0, $pos)) . "\n\n" . $add . "\n" . substr($reply, $pos);
    }
}
