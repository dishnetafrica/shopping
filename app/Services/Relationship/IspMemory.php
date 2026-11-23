<?php

namespace App\Services\Relationship;

/**
 * PURE. Customer memory (the Memory Loop: RelationshipExtractor writes, ProfileContext reads) worded for an
 * Internet provider (DishNet, 23 Nov 2026: "can the bot remember the questions asked in previous chats, so it
 * replies properly, like a human?").
 *
 * The shop wording asks "which shop and area — so we deliver" and remembers "where we delivered". An Internet
 * customer is remembered by what a salesperson needs: the service they want (Starlink / Fiber), where exactly
 * (area + landmark), home or business, how many users, what they are waiting for — and never a price: prices
 * come only from the catalogue and the published sales knowledge, and an old chat may hold an old price.
 */
final class IspMemory
{
    /** PURE. The analyst's instructions for an Internet provider (same JSON as the shop version). */
    public static function extractorPrompt(string $known): string
    {
        return <<<SYS
You are the relationship analyst for an Internet provider in South Sudan that sells Starlink and Fiber internet over WhatsApp (customers often write Juba Arabic, Arabic or mixed English). Analyze the NEW portion of one customer conversation and reply with ONLY valid JSON, no markdown:
{
 "customer": {
   "customer_name": "their name if stated or clearly inferable, else \"\"", "shop_area": "WHERE the service is wanted: area + a landmark if mentioned (e.g. \"Gudele 2, near the roundabout\"), else \"\"", "facts": {  // ONLY newly learned/changed facts, snake_case keys. Useful keys: service_interest (starlink|fiber|both|unsure), plan_or_kit_interest, home_or_business, users_or_devices, current_internet, existing_customer (yes|no), support_issue, decision_role, payment_behavior, preferred_contact_hours, current_goal, next_expected_action, current_objection
   },
   "stage_proposal": "INTERESTED|NEGOTIATING|WAITING_BUDGET or empty string",  // ONLY these three, only if the conversation clearly shows it
   "sentiment": "positive|neutral|negative",
   "summary": "5-8 short lines merging the PREVIOUS summary with what is new: what they want, where, for whom, what was agreed or promised, what they are waiting for. NEVER a price, amount, discount or plan price — those change and come only from the catalogue."
 },
 "review": {  // judge OUR handling, not the customer
   "all_questions_answered": true|false,
   "missed_items": [ {"type":"unsent_quote|ignored_question|partial_answer|no_recommendation|unaddressed_complaint|repeated_question|callback_requested", "evidence":"short quote from the transcript", "suggested_action":"one line"} ],
   "promises": [ {"text":"what WE promised", "due_hint":"today|tomorrow|this_week|unspecified"} ],
   "ended": "resolved|abandoned|handed_to_human",
   "quality": "good|needs_attention|poor",
   "quality_reasons": ["short reason", "..."]
 }
}
Categorical values only — never invent numbers, and never write any money amount anywhere. Empty arrays/strings when nothing applies.
KNOWN SO FAR: {$known}
SYS;
    }

    /** PURE. Where the service is wanted, remembered from earlier chats — confirm it, never ask again. */
    public static function locationLine(string $where): string
    {
        return 'Where they want the service (from earlier chats): ' . $where
            . '. When coverage or installation comes up, CONFIRM it ("still for ' . $where . '?") rather than asking again. If they name somewhere different, use the new place.';
    }

    /** PURE. What is still unknown, for ONE natural question at the right moment. */
    public static function gapsLine(array $gaps): string
    {
        return 'STILL UNKNOWN: ' . implode(' · ', $gaps)
            . '. Ask for ONE of these only when it is needed (coverage, quotation or installation), never before answering them, never as an interrogation; one ignored ask = drop it for this conversation.';
    }

    /** PURE. Which gaps matter for an Internet customer. */
    public static function gaps(?string $name, ?string $where): array
    {
        $g = [];
        if (trim((string) $name) === '') {
            $g[] = 'their name';
        }
        if (trim((string) $where) === '') {
            $g[] = 'where they want the service (area + a landmark)';
        }

        return $g;
    }

    /** The fact that holds where the service is wanted — not the CRM's general city or address (Codex on #179). */
    public const SITE = 'service_location';

    /** PURE. The stage playbook, where the shop wording does not fit an Internet provider (Codex on #179). */
    public static function playbook(string $stage, ?string $shop): ?string
    {
        return match (strtoupper($stage)) {
            'PURCHASED' => 'Existing customer: make sure their service works well; any problem goes to Technical Support; offer extra equipment or an upgrade only if they ask.',
            'REPEAT'    => 'Existing customer who came back: familiar and efficient; they know us, skip the basics.',
            default     => $shop,
        };
    }

        /** PURE. The memory never overrides today's prices or plans. */
    public const NO_OLD_PRICES = 'This memory never sets a price or a plan: an older chat may mention one that has changed or been discontinued. Prices and plans come ONLY from PRODUCTS and APPROVED SALES KNOWLEDGE.';
}
