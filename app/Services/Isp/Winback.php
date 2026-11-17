<?php

namespace App\Services\Isp;

/**
 * PURE. Reads one old WhatsApp conversation of an Internet-provider business and says how a relationship
 * manager should chase it (DishNet, 17 Nov 2026: "how we can reach old numbers as relationship manager so
 * we can chase them"; the line's history from before ShopBot holds about 700 such conversations).
 *
 *   classify()  what the customer wanted (Starlink / Fiber), how warm they are, whether we left them
 *               unanswered, or why not to chase them at all (asked us to stop, an existing customer's
 *               fault — that is support's, not sales').
 *   opener()    the first message their salesperson sends: personal, short, honest about the delay, and
 *               with NO amount in it — prices have changed since, so the up-to-date figures come from the
 *               catalogue in a quotation (IspAutoQuote), never from an old chat.
 *
 * Nothing here sends anything: the salesperson reads the opener and sends it, one customer at a time.
 */
final class Winback
{
    public const SEGMENTS = [
        'hot'               => 'Was ready to buy, visit or be called',
        'price_changed'     => 'Asked when prices were different',
        'wanted_cheaper'    => 'Wanted a lower price',
        'warm'              => 'Asked about a service or price',
        'cold'              => 'Only clicked an advert or said hello',
        'existing_customer' => 'Already a customer (paid, or reported a fault)',
    ];

    /** Score per segment: the order a salesperson works the list. */
    private const SCORE = ['hot' => 80, 'existing_customer' => 70, 'price_changed' => 60, 'wanted_cheaper' => 55, 'warm' => 50, 'cold' => 20];

    private const RX = [
        'stop'      => '/\b(stop|unsubscribe|not interested|no longer interested|don\'?t (?:message|text|call|contact) me|remove my number|wrong number)\b/u',
        'support'   => '/\b((?:network|internet|connection|wi-?fi) is (?:really |very |so )?(?:down|slow|bad|off)|no signal|not connecting|can\'?t (?:connect|browse|load|provide internet)|reset|red light|no internet|not working|stopped working|disconnect(?:ed|ing)?|offline|slow (?:internet|network|connection|speed)|internet is slow|is very slow|wi-?fi password|change (?:the |my )?password|reset (?:the |my )?router|my (?:kit|dish|starlink|fib(?:er|re)|account|subscription|router)|subscription (?:expired|ended|is over)|renew(?:al)?)\b/u',
        'paid'      => '/\b(i (?:have )?paid|already paid|sent (?:the |you )?money|payment (?:done|sent|made|is done)|transferred|here is the receipt|proof of payment)\b/u',
        // OUR office: "where is your office", "office hours" — not "fiber for my office"
        'visit'     => '/\b((?:your|the|dishnet\'?s?) office|office (?:location|address|hours)|visit you|come (?:to|over)|where are you (?:located|found)?|your (?:location|address)|where is your)\b/u',
        'callback'  => '/\b(call me|call back|callback|phone me|ring me|give me a call|your (?:phone|contact) number|speak to (?:an? )?(?:agent|person|someone|human|staff))\b/u',
        'buy'       => '/\b(i want|i need|i would like|i\'d like|buy|purchase|order|install(?:ation)?|ready to|how (?:do|can) i (?:get|pay|buy)|i (?:will|would|can) pay|proceed|go ahead|book)\b/u',
        'price'     => '/(\b(price|prices|cost|how much|charges?|fees?|rates?|quotation|quote|proforma|usd|ssp|dollars?)\b|\$)/u',
        'old_price' => '/(\b299\b|\$\s?80\b|\b80\s?(?:usd|dollars?|\$))/u',
        'cheaper'   => '/(\$\s?30\b|\b30\s?(?:usd|dollars?|\$)|\bcheap(?:er)?\b|\bdiscount\b|\breduce\b|\btoo (?:much|expensive)\b|\bexpensive\b)/u',
        'starlink'  => '/\b(starlink|star link|dish|kit|mini|roam|satellite)\b/u',
        'fiber'     => '/\b(fib(?:er|re)|mbps|cable internet|wired internet)\b/u',
        // forwarded scams and chain messages (live examples: "I got 150,000 SSP! … https://…", "Canada needs 380,000 immigrants")
        'spam'      => '/(https?:\/\/(?!(?:maps\.app\.goo\.gl|goo\.gl\/maps|maps\.google|www\.google\.com\/maps))|\bapply today\b|\byou are qualified\b|\byou should try it\b|\bclick (?:the|this) link\b)/u',
        'greeting'  => '/^(hi+|hello+|hey|hy|good (?:morning|afternoon|evening|day)|how are (?:you|u)(?: doing)?|ok(?:ay)?|yes|no|thanks?(?: you)?|fine|sure|h+)[\s.!?,]*$/u',
        'ad'        => '/^(hello!? )?(can i get more info(?:rmation)? on this|i\'?m interested in this|what services do you offer|is this available|more info|info please)[.!?]*$/u',
    ];

    /** Our own alerts to staff (the old n8n bot): a chat full of these is a staff phone, not a customer. */
    private const STAFF_ALERT = '/(WANTS TO VISIT OFFICE|CALLBACK REQUESTED|LOCATION SHARED|PAYMENT SENT|HOT LEAD|BOT PROMISED FOLLOW-UP|NEW TICKET|NEW ORDER|New enquiry from your customer|please action)/u';

    /**
     * @param list<array{0:int,1:bool,2:string}> $rows  [unix time, sent by us, text] in any order
     * @return array{skip:?string,segment:?string,service:string,score:int,unanswered:bool,last_in:int,signals:string[],summary:string}
     */
    public static function classify(array $rows, int $now): array
    {
        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);
        $in = array_values(array_filter($rows, fn ($r) => ! $r[1] && trim((string) $r[2]) !== '' && ! str_starts_with(trim((string) $r[2]), '[')));
        $lastIn = $in ? (int) end($in)[0] : 0;
        $last = $rows ? end($rows) : null;
        $unanswered = $last !== null && ! $last[1];
        $out = ['skip' => null, 'segment' => null, 'service' => 'unsure', 'score' => 0, 'unanswered' => $unanswered, 'last_in' => $lastIn, 'signals' => [], 'summary' => ''];
        if (! $in) {
            $out['skip'] = 'no_customer_text';

            return $out;
        }
        foreach ($rows as $r) {
            if ($r[1] && preg_match(self::STAFF_ALERT, (string) $r[2])) {
                $out['skip'] = 'staff_phone';               // we sent this number staff alerts: mute it, never chase it

                return $out;
            }
        }

        $sig = [];
        $adOnly = true;
        $spamOnly = true;
        foreach ($in as $r) {
            $t = mb_strtolower(trim((string) $r[2]));
            if (preg_match(self::RX['spam'], $t)) {
                continue;                                   // a forwarded scam says nothing about what they want
            }
            $spamOnly = false;
            foreach (self::RX as $k => $rx) {
                if (! in_array($k, ['ad', 'spam', 'greeting'], true) && preg_match($rx, $t)) {
                    $sig[$k] = true;
                }
            }
            if (! preg_match(self::RX['ad'], $t) && ! preg_match(self::RX['greeting'], $t)) {
                $adOnly = false;                            // they told us something of their own
            }
        }
        if ($spamOnly) {
            $out['skip'] = 'spam_only';

            return $out;
        }
        // "stop" counts only when it is the customer's LAST word to us, not an old "stop the old plan".
        $lastText = mb_strtolower(trim((string) end($in)[2]));
        if (preg_match(self::RX['stop'], $lastText)) {
            $out['skip'] = 'asked_to_stop';
        }
        unset($sig['stop']);
        $out['signals'] = array_keys($sig);
        $out['service'] = isset($sig['starlink'], $sig['fiber']) ? 'both' : (isset($sig['starlink']) ? 'starlink' : (isset($sig['fiber']) ? 'fiber' : 'unsure'));

        $recent = $lastIn >= $now - 120 * 86400;
        $segment = match (true) {
            isset($sig['paid']) || isset($sig['support']) => 'existing_customer',
            (isset($sig['buy']) || isset($sig['visit']) || isset($sig['callback'])) && $recent => 'hot',
            isset($sig['old_price']) => 'price_changed',
            isset($sig['cheaper']) => 'wanted_cheaper',
            $adOnly => 'cold',                              // only greetings and the advert's own question
            default => 'warm',
        };
        $out['segment'] = $segment;
        // More recent = higher; left unanswered = higher (we owe them).
        $ageDays = max(0, intdiv($now - $lastIn, 86400));
        $out['score'] = max(1, min(100, self::SCORE[$segment] + ($unanswered ? 10 : 0) - intdiv($ageDays, 30) * 3));
        $out['summary'] = self::summary($in);

        return $out;
    }

    /** PURE. The salesperson's first message. No amounts — the current prices go in a quotation. */
    public static function opener(string $segment, string $service, bool $unanswered, ?string $customerName, string $salesName, string $company): string
    {
        $first = trim((string) preg_replace('/\s.*/u', '', trim((string) $customerName)));
        $hi = 'Hello' . ($first !== '' && preg_match('/^\p{L}[\p{L}\'-]{1,30}$/u', $first) ? ' ' . $first : '') . ', this is ' . trim($salesName) . ' from ' . trim($company) . '.';
        $sorry = $unanswered ? ' I am sorry we did not get back to you earlier.' : '';
        $what = match ($service) {
            'starlink' => 'Starlink',
            'fiber'    => 'Fiber internet',
            'both'     => 'Starlink and Fiber',
            default    => 'internet',
        };

        $body = match ($segment) {
            'hot'               => " You were asking about getting {$what} with us. Are you still looking? I can send you a quotation today and arrange the installation.",
            'price_changed'     => " When you asked about {$what} earlier, our prices were different. They have changed since then: would you like me to send you today's prices in a quotation?",
            'wanted_cheaper'    => " You were looking for a lower monthly price. Our plans have changed since then: may I send you the current options so you can compare?",
            'warm'              => " You asked us about {$what} a while ago. Are you still looking? I would be happy to recommend the right plan for you and send you a quotation.",
            'existing_customer' => " I am now your account manager. How is your internet service? If you need anything, a renewal, a new plan or help with a fault, tell me and I will sort it out.",
            default             => " Thank you for contacting us about our internet services. Are you looking for internet for your home or your business? I can help you choose between Starlink and Fiber.",
        };

        return $hi . ($segment === 'existing_customer' ? '' : $sorry) . $body;
    }

    /**
     * PURE. Spread each salesperson's list over days, best first: at most $perDay a day each.
     * @param array<string,array{owner:int,score:int}> $leads phone => owner and score
     * @return array<string,int> phone => day offset (0 = today)
     */
    public static function schedule(array $leads, int $perDay): array
    {
        $perDay = max(1, $perDay);
        $byOwner = [];
        foreach ($leads as $phone => $l) {
            $byOwner[$l['owner']][(string) $phone] = $l['score'];
        }
        $out = [];
        foreach ($byOwner as $list) {
            uksort($list, fn ($a, $b) => [$list[$b], $a] <=> [$list[$a], $b]);   // score high first; phone breaks ties
            $i = 0;
            foreach (array_keys($list) as $phone) {
                $out[(string) $phone] = intdiv($i++, $perDay);
            }
        }

        return $out;
    }

    /** What the customer said, shortest useful form, for the lead card. */
    private static function summary(array $in): string
    {
        $parts = [];
        foreach (array_slice($in, -4) as $r) {
            $t = trim(preg_replace('/\s+/u', ' ', (string) $r[2]) ?? '');
            if ($t !== '') {
                $parts[] = '"' . mb_substr($t, 0, 120) . '"';
            }
        }

        return mb_substr(implode(' · ', $parts), 0, 600);
    }
}
