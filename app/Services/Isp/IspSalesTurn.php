<?php

namespace App\Services\Isp;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Sales\Brain\Knowledge;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Travel\Conversation\ReplyGuard;
use App\Support\BotTrace;

/**
 * The customer-facing bot on an Internet-provider business (DishNet South Sudan plan, Phase 3).
 *
 *   promptBlock()  what the model is told: qualify the customer, and use ONLY the Sales Brain's
 *                  PUBLISHED knowledge and the catalogue's prices — never invent a price, package,
 *                  coverage, term or timeline. With nothing published it is told so, plainly.
 *   afterReply()   IspReplyGuard on the model's words: unknown amounts and unpublished coverage
 *                  claims are removed and replaced with a short note; each removal is traced.
 *
 * Drafts, approved-but-unpublished and rejected knowledge never reach this class: it reads through
 * SalesBrain::published() only.
 */
class IspSalesTurn
{
    private const KNOWLEDGE_MAX = 9000;

    public function __construct(private SalesBrain $brain) {}

    public function promptBlock(Tenant $tenant): string
    {
        try {
            $published = $this->brain->published($tenant);
        } catch (\Throwable $e) {
            report($e);
            $published = [];
        }

        return self::rules() . (IspAutoQuote::enabled($tenant) ? self::quoteRules((bool) $tenant->setting('isp_starlink_plan_needs_kit', true)) : self::HANDOFF_RULE) . self::knowledgeBlock($published);
    }

    /**
     * Per customer, per turn: WhatsApp hides this customer's number and we have no number to call them on.
     * The bot then asks for one the first time it says someone will call (CallbackNumber). '' otherwise.
     */
    public static function contactBlock(Tenant $tenant, string $from): string
    {
        try {
            if (! CallbackNumber::isHidden($tenant, $from) || CallbackNumber::callable($tenant, $from) !== null) {
                return '';
            }
        } catch (\Throwable $e) {
            report($e);

            return '';
        }

        return self::CALLBACK_RULE;
    }

    public const CALLBACK_RULE = "\nCUSTOMER'S PHONE NUMBER: WhatsApp hides this customer's phone number, so their salesperson cannot call them from this chat. The first time you say someone will call, contact or visit them (or they ask for a call), also ask ONE short question: the best phone number to reach them on. Ask only once; if they prefer not to give it, carry on in the chat.\n";

    public function afterReply(Tenant $tenant, string $from, string $customerText, string $reply): string
    {
        // A phone number the customer typed, in a chat where WhatsApp hides theirs: the number to call them on.
        try {
            $typed = CallbackNumber::isHidden($tenant, $from)
                ? CallbackNumber::extract($customerText, CallbackNumber::askedRecently($tenant, $from)) : null;
            if ($typed !== null) {
                CallbackNumber::remember($tenant, $from, $typed, 'typed in chat');
            }
        } catch (\Throwable $e) {
            report($e);
        }
        try {
            $published = $this->brain->published($tenant);
            [$amounts, $places] = self::allowed($published);
            $g = IspReplyGuard::clean($reply, array_merge($amounts, $this->cataloguePrices($tenant), ReplyGuard::amounts($customerText)), $places);
            foreach (['isp_price_stripped' => $g['price_hits'], 'isp_cover_stripped' => $g['coverage_hits']] as $stage => $n) {
                if ($n > 0) {
                    // what was removed, so a wrong removal can be seen in the step log (detail holds 300)
                    BotTrace::log($tenant->id, 'isp', $from, $stage, mb_substr('x' . $n . ': ' . implode(' | ', $g['removed']), 0, 290));
                }
            }

            $this->alertPromise($tenant, $from, $customerText, $g['reply']);

            return $g['reply'];
        } catch (\Throwable $e) {
            report($e);

            return $reply;
        }
    }

    /**
     * The bot told the customer someone will check, confirm or get back: make sure a person knows.
     * Live, 17 Nov 2026: "I will confirm the prices … with the team. Please hold on" — and nobody was
     * told. (The old n8n bot sent its "BOT PROMISED FOLLOW-UP" alert for exactly this.) Goes to the
     * customer's salesperson, or the owner alert numbers when there is none; at most once per customer
     * per 2 hours. A quotation line is left to IspAutoQuote, which alerts on its own.
     */
    private function alertPromise(Tenant $tenant, string $from, string $customerText, string $reply): void
    {
        if (stripos($reply, '<<QUOTE') !== false || ! self::promisesFollowUp($reply)) {
            return;
        }
        if (! \Illuminate\Support\Facades\Cache::add("isp_promise:{$tenant->id}:{$from}", 1, now()->addHours(2))) {
            return;
        }
        $owner = app(\App\Services\Sales\SalesOwnership::class)->ownerForEnquiry($tenant, $from);
        $digits = fn (string $s) => preg_replace('/\D/', '', $s) ?? '';
        $to = $owner && $digits((string) $owner->phone) !== '' ? [$digits((string) $owner->phone)] : $tenant->ownerAlertNumbers();
        $visible = trim((string) preg_replace('/<<[A-Z_]+.*?>>/s', '', $reply));
        $opens = self::opensAt($tenant);
        $text = "\u{1F514} *Bot promised a follow-up* — " . CallbackNumber::label($tenant, $from) . "\n"
            . 'Customer: "' . mb_substr(IspQuotePlan::plain($customerText), 0, 200) . "\"\n"
            . 'Bot: "' . mb_substr($visible, 0, 250) . "\"\n"
            . 'Please contact them' . ($opens !== null ? " when the office opens ({$opens}) \u{1F319}" : '') . ($owner ? '' : ' (no salesperson assigned yet)') . '.';
        foreach ($to as $n) {
            \App\Jobs\NotifyOwner::dispatch($tenant->id, $text, (string) $n);
        }
        BotTrace::log($tenant->id, 'isp', $from, 'isp_promise_alert', $owner ? 'to user ' . $owner->id : 'to owner numbers');
    }

    /** PURE. Does the reply promise that someone will check, confirm, contact or get back to the customer? */
    public static function promisesFollowUp(string $reply): bool
    {
        $r = mb_strtolower(IspQuotePlan::plain(trim((string) preg_replace('/<<[A-Z_]+.*?>>/s', '', $reply))));

        return (bool) preg_match('/\b(i will|i\'ll|we will|we\'ll|let me|our team will|the team will|(?:your|our|the) (?:sales ?person|sales team|team|colleague) will)\b.{0,60}\b(confirm|check|get back|contact|call|reach out|follow up|send you|let you know|prepare)\b'
            . '|\b(please hold on|hold on a moment|get back to you|confirm (?:it |this |that )?with the team|check with (?:the|our) team)\b/u', $r);
    }

    /** PURE. The standing rules for an Internet-provider sales conversation. */
    public static function rules(): string
    {
        return "INTERNET SALES (this business sells Starlink and Fiber internet; a named salesperson owns each customer and follows up):\n"
            . "- QUALIFY before recommending, ONE question at a time, using what the customer already said: which service (Starlink, Fiber, or not sure), WHERE exactly (area and a landmark), home or business, and how many people or devices will use it and for what.\n"
            . "- ONLY APPROVED INFORMATION: prices, packages, speeds, installation fees, contract terms, coverage and timelines come ONLY from APPROVED SALES KNOWLEDGE and PRODUCTS. If something is not written there, do not guess and do not use general knowledge about other countries or providers: say their salesperson will confirm it.\n"
            . "- COVERAGE: say a place is covered ONLY if COVERAGE below lists it as covered. Anywhere else, say a site survey is needed to confirm. Never say \"we cover your area\" otherwise.\n"
            . "- Never promise a discount, free installation, a speed, an uptime figure or an installation date that is not written below.\n"
            . "- OFFICE HOURS: when CURRENT TIME says \"Open right now: NO\", never say anyone will call, reply or visit now, shortly or today. Say their salesperson will contact them when the office opens, giving the day and time from \"Next opening\" (e.g. \"tomorrow at 9:00 AM\"). You keep answering their questions meanwhile.\n";
    }

    /**
     * When the office next opens, as the customer reads it ("tomorrow at 9:00 AM"), or null when it is
     * open now or its hours are not set or not understood (then nothing about timing is said).
     * Reads the business_hours and timezone settings through TimeContext.
     */
    public static function opensAt(Tenant $tenant, ?\DateTimeImmutable $now = null): ?string
    {
        try {
            $tz = \App\Services\Bot\TimeContext::zone((string) $tenant->setting('timezone', ''), (string) $tenant->setting('city', ''));
            $now = ($now ?? now()->toDateTimeImmutable())->setTimezone(new \DateTimeZone($tz));
            $week = \App\Services\Bot\TimeContext::parseHours((string) $tenant->setting('business_hours', ''));
            $next = \App\Services\Bot\TimeContext::nextOpen($week, $now);

            return ($next === null || $next == $now) ? null : self::opensPhrase($next, $now);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** PURE. "today at 9:00 AM" / "tomorrow at 9:00 AM" / "on Monday at 9:00 AM". */
    public static function opensPhrase(\DateTimeImmutable $next, \DateTimeImmutable $now): string
    {
        $days = (int) $now->setTime(0, 0)->diff($next->setTime(0, 0))->format('%a');
        $day = match ($days) {
            0 => 'today',
            1 => 'tomorrow',
            default => 'on ' . $next->format('l'),
        };

        return $day . ' at ' . $next->format('g:i A');
    }

    /** Used when automatic quotations are switched off (isp_auto_quote = false). */
    public const HANDOFF_RULE = "- When the customer is ready to go ahead (a quotation, a site visit, payment), tell them their salesperson will take it from here.\n";

    /**
     * PURE. How the model asks the system for a quotation (IspAutoQuote prices and sends it). Behave like
     * a good salesperson: recommend, offer the quotation in writing, never invent its figures.
     */
    public static function quoteRules(bool $planNeedsKit = true): string
    {
        // DishNet, 21 Nov 2026: "we have to submit the quotation with hardware, Standard kit or Mini kit —
        // we don't offer subscription only". IspQuotePlan refuses a plan without a kit as well.
        $starlink = $planNeedsKit
            ? 'Starlink = a KIT (the Mini or the Standard from PRODUCTS) AND the monthly plan, ALWAYS both: we do not offer the subscription on its own. Ask which kit if they have not chosen (suggest the Mini first). If they say they already have a kit or want only the subscription, say politely that our Starlink plans come only with a kit from us, and offer the Mini.'
            : 'Starlink = the kit AND the monthly plan they chose (ask which plan if unsure; if they already own a kit, only the plan).';

        return "QUOTATION (the system prices it from PRODUCTS and sends the PDF from the customer's salesperson — you never write one):\n"
            . "- Once you know what fits the customer and where they are, RECOMMEND one option in a sentence (why it suits them) and OFFER a written quotation: \"Shall I send you a quotation?\"\n"
            . "- Ask for their name first if you do not have it — it goes on the quotation.\n"
            . "- When they ask for a quotation, a proforma or the price in writing, or say yes to your offer, write ONE short visible sentence (e.g. \"Sure — here is your quotation 📄\") and append the hidden line <<QUOTE {\"customer_name\":\"<their name as they gave it>\",\"items\":[{\"name\":\"<exact PRODUCT name>\",\"qty\":1}]}>> as the VERY LAST thing in your reply.\n"
            . "- Items: use the EXACT names from PRODUCTS. {$starlink} Fiber = the monthly plan (its installation is added automatically). Add a router, adaptor, power bank or public IP only if they asked for it.\n"
            . "- Do NOT write the line for Starlink Priority, more than 5 kits, 50 or more users, a custom network or anything not in PRODUCTS: say their salesperson will prepare a custom quotation.\n"
            . "- 50 OR MORE USERS (people, devices, clients) on Starlink: do NOT recommend the Residential plan; say a business (Priority) plan is needed and their salesperson will prepare a custom quotation.\n"
            . "- ACCESSORIES: if they ask about equipment that IS in PRODUCTS (for example a router), give its exact price and offer an updated quotation that includes it. For anything NOT in PRODUCTS (cables, access points, installation work), say their salesperson will quote it — never say you will check yourself.\n"
            . "- Never type a quotation, its total or a quotation number yourself: the PDF shows the exact one-time and monthly amounts.\n"
            . "- After the quotation: answer questions about it. If the price is a concern, do not offer a discount; explain what is included and that their salesperson will call to go through the options.\n";
    }

    /** PURE. The published knowledge, as the model reads it. */
    public static function knowledgeBlock(array $published): string
    {
        $coverage = [];
        $text = '';
        foreach ($published as $p) {
            if (in_array($p['topic'], Knowledge::INTERNAL, true)) {
                continue;                                   // training questions are never customer knowledge
            }
            if ($p['topic'] === 'coverage') {
                foreach ((array) ($p['data']['areas'] ?? []) as $a) {
                    $coverage[] = $a;
                }
                continue;
            }
            $label = Knowledge::TOPICS[$p['topic']] ?? $p['topic'];
            $body = $p['topic'] === 'terms' ? Knowledge::termsText((array) $p['data']) : trim((string) $p['body']);
            $text .= "## {$label}: {$p['title']}\n{$body}\n";
        }
        if ($text === '' && ! $coverage) {
            return "APPROVED SALES KNOWLEDGE: none is published yet. Do not state any price, package, coverage or term. Take the customer's needs and exact location, and tell them their salesperson will contact them with the details.\n";
        }
        if (mb_strlen($text) > self::KNOWLEDGE_MAX) {
            $text = mb_substr($text, 0, self::KNOWLEDGE_MAX) . "\n(…more is held by the sales team; for anything not shown here, the salesperson will confirm.)\n";
        }
        $out = "APPROVED SALES KNOWLEDGE (published by management for this market; use nothing else):\n" . ($text !== '' ? $text : "(no text published yet)\n");

        $names = ['covered' => 'Covered', 'survey' => 'Site survey needed', 'none' => 'Not served'];
        if ($coverage) {
            $out .= "COVERAGE (any place not listed here: site survey needed):\n";
            foreach (Knowledge::SERVICE_LINES as $line) {
                foreach ($names as $status => $label) {
                    $places = array_map(fn ($a) => $a['name'] . ($a['keywords'] ? ' (' . implode(', ', $a['keywords']) . ')' : ''),
                        array_values(array_filter($coverage, fn ($a) => $a['service_line'] === $line && $a['status'] === $status)));
                    if ($places) {
                        $out .= '- ' . ucfirst($line) . " — {$label}: " . implode('; ', $places) . "\n";
                    }
                }
            }
        } else {
            $out .= "COVERAGE: no areas are published yet: for every place, a site survey is needed to confirm.\n";
        }

        return $out;
    }

    /**
     * PURE. What the guard lets through: amounts written in the published knowledge, and the places
     * published as covered.
     * @return array{0: float[], 1: string[]}
     */
    public static function allowed(array $published): array
    {
        $amounts = [];
        $places = [];
        foreach ($published as $p) {
            if (in_array($p['topic'], Knowledge::INTERNAL, true)) {
                continue;
            }
            if ($p['topic'] === 'coverage') {
                foreach ((array) ($p['data']['areas'] ?? []) as $a) {
                    if (($a['status'] ?? '') === 'covered') {
                        $places[] = mb_strtolower((string) $a['name']);
                        foreach ((array) ($a['keywords'] ?? []) as $k) {
                            $places[] = mb_strtolower((string) $k);
                        }
                    }
                }
                continue;
            }
            $body = $p['topic'] === 'terms' ? Knowledge::termsText((array) $p['data']) : (string) $p['body'];
            $amounts = array_merge($amounts, ReplyGuard::amounts($body));
        }

        return [array_values(array_unique($amounts, SORT_REGULAR)), array_values(array_unique(array_filter($places)))];
    }

    private function cataloguePrices(Tenant $tenant): array
    {
        return Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->where('price', '>', 0)
            ->limit(500)->pluck('price')->map(fn ($p) => (float) $p)->unique()->values()->all();
    }
}
