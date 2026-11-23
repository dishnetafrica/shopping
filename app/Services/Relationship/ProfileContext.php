<?php

namespace App\Services\Relationship;

use App\Models\CustomerProfile;
use App\Models\Tenant;

/**
 * V4.0 — the read side of the Memory Loop: a compact CUSTOMER PROFILE block for
 * AI context (AiBrain now; SalesCadence drafting in V4.1). Empty string when the
 * flag is off or nothing is known — callers just concatenate, zero risk.
 */
class ProfileContext
{
    public static function block(Tenant $tenant, string $phone): string
    {
        try {
            if (! (bool) $tenant->setting('relationship_memory', false)) return '';
            // Internet providers (DishNet, 23 Nov 2026): the same memory, worded for a service, not a delivery.
            $isp = \App\Support\Vertical::of($tenant) === \App\Support\Vertical::ISP;
            $p = CustomerProfile::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('phone', preg_replace('/[^0-9]/', '', $phone))
                ->first();
            if (! $p && $isp) {
                return '';                                  // the INTERNET SALES rules already say how to qualify
            }
            if (! $p) {
                // BRAND-NEW customer — the first-impressions moment. In Juba trade the
                // relationship IS the sale: knowing their name and shop/area turns an
                // anonymous number into a customer we can deliver to and greet properly.
                return "\n\nNEW CUSTOMER — we know nothing about them yet.\n"
                    ."- FIRST fully answer whatever they asked. THEN, in the same or next reply, get acquainted "
                    ."with ONE short warm line combining name + shop/area, e.g.: \"By the way, who do I have the "
                    ."pleasure of serving? Which shop and area — so we deliver right \u{1F60A}\"\n"
                    ."- ONE ask only. Never before answering. Never if they seem upset or in a hurry. "
                    ."If they ignore it, do NOT ask again this conversation — the details will come naturally with the first order.";
            }

            $bits = [];
            if ($p->name)  $bits[] = 'Name: '.$p->name;
            if ($p->segment) $bits[] = 'Segment: '.$p->segment;
            // v4.9 dealer programme 4a: the AI must KNOW this customer trades at dealer prices — and
            // that the calculator (not the AI) computes them. Guarded: attribute is null pre-migration.
            if (isset($p->dealer_tier) && in_array((string) $p->dealer_tier, ['dealer', 'wholesale'], true)) {
                $pct = \App\Services\Pricing\PriceResolver::pctFor($tenant, $p);
                $bits[] = 'DEALER STATUS: ' . strtoupper((string) $p->dealer_tier) . ' — this customer buys at trade prices ('
                    . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '% off list, protected by a margin floor). '
                    . 'The order calculator applies their price automatically — quote ITS numbers, never invent further discounts, '
                    . 'and treat them as trade: talk stock, lead times and repeat orders, not retail persuasion.';
            }
            if ($p->stage) $bits[] = 'Relationship stage: '.$p->stage;
            if ($p->lang)  $bits[] = 'Preferred language: '.$p->lang;
            foreach ((array) ($p->facts ?? []) as $k => $v) {
                if ($isp && $k === IspMemory::SITE) continue;   // shown below, as the place to confirm
                if (is_array($v)) $v = implode(', ', array_map('strval', $v));
                $bits[] = str_replace('_', ' ', $k).': '.mb_substr((string) $v, 0, 120);
                if (count($bits) >= 14) break;
            }
            $playbook = [
                'NEW' => 'First impressions: helpful and unhurried; learn what they need.',
                'INTERESTED' => 'Give specifics and a clear next step; offer a quotation.',
                'QUOTED' => 'Reference the open quotation; remove obstacles, do not re-pitch.',
                'NEGOTIATING' => 'Stay on the open discussion; no new discounts without a human.',
                'WAITING_BUDGET' => 'Zero pressure; keep the door open warmly.',
                'PURCHASED' => 'Aftercare tone: delivery, satisfaction, small next suggestion.',
                'REPEAT' => 'Familiar and efficient; they know us — skip the basics.',
                'VIP' => 'Gratitude and priority; flag anything unusual to a human early.',
                'DORMANT' => 'Warm and light; no guilt, no pressure, one easy re-entry.',
                'LOST' => 'Courteous and minimal; do not sell.',
            ][strtoupper((string) $p->stage)] ?? null;
            if ($isp) $playbook = IspMemory::playbook((string) $p->stage, $playbook);
            if ($playbook) $bits[] = 'Playbook for this stage: '.$playbook;

            // Progressive profiling: surface what we STILL don't know, so the bot can
            // close ONE gap per conversation at a natural moment — never an interrogation.
            // v4.39 — where we last delivered. This block used to touch `address` ONLY to test
            // whether it was empty, so the extractor could spend months harvesting "Shop 307,
            // Konyo Konyo" out of chat and the bot would still ask every single time. Offer it
            // back and let them correct it; a customer confirming an address is faster, and far
            // less irritating, than a customer typing one.
            $where = trim((string) $p->address) ?: trim((string) $p->city);
            if ($isp) {
                // Only a place they gave for the SERVICE — never the record's general city or address.
                $site = trim((string) (((array) ($p->facts ?? []))[IspMemory::SITE] ?? ''));
                if ($site !== '') {
                    $bits[] = IspMemory::locationLine($site);
                }
                $gaps = IspMemory::gaps($p->name, $site);
                $bits[] = $gaps ? IspMemory::gapsLine($gaps) : 'Greet and refer to them BY NAME — being remembered is half the relationship.';
                if ($p->ai_summary) $bits[] = "Relationship summary:\n".$p->ai_summary;
                $bits[] = IspMemory::NO_OLD_PRICES;

                return "\n\nCUSTOMER PROFILE (from past interactions — use naturally, never recite):\n".implode("\n", $bits);
            }
            if ($where !== '') {
                $bits[] = 'We have delivered to them at: ' . $where
                    . '. When delivery comes up, OFFER this back for confirmation ("same place — ' . $where . '?") '
                    . 'rather than asking where they are. If they name somewhere different, use the new one.';
            }

            $gaps = [];
            if (! trim((string) $p->name)) $gaps[] = 'their name';
            if (! trim((string) $p->address) && ! trim((string) $p->city)) $gaps[] = 'their shop + area (needed for delivery)';
            if ($gaps) {
                $bits[] = 'STILL UNKNOWN: '.implode(' · ', $gaps)
                    .'. Weave ONE natural question for ONE of these at the right moment — '
                    .'delivery talk is the perfect moment for the shop/area. Never interrogate, never ask before answering them, '
                    .'and one ignored ask = drop it for this conversation.';
            } else {
                $bits[] = 'Greet and refer to them BY NAME — being remembered is half the relationship in this market.';
            }
            if ($p->ai_summary) $bits[] = "Relationship summary:\n".$p->ai_summary;
            if (! $bits) return '';

            return "\n\nCUSTOMER PROFILE (from past interactions — use naturally, never recite):\n".implode("\n", $bits);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
