<?php

namespace App\Support;

/**
 * Shared "sound like a person" text helpers for customer-facing messages. Pure functions —
 * no framework, no DB — so they are directly harness-testable and safe to call anywhere
 * (jobs, commands, controllers).
 *
 * firstName() is the canonical copy of the greeting logic introduced in SalesCadenceCommand
 * (v4.5.3): WhatsApp contact names arrive as "Abdifatah Ahmed Shop No 48" or "HOTEL DE JUBA" —
 * greeting with the raw string is a robot-tell. SalesCadenceCommand delegates here (v4.6.2),
 * so cadence follow-ups and order-status notifications greet identically.
 */
final class HumanText
{
    /** First name only, label tokens stripped, SHOUTING softened ("ABDI HASSAN STORE" → "Abdi"). '' when nothing usable. */
    public static function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $out = [];
        foreach (preg_split('/\s+/u', $name) as $w) {
            if (preg_match('/^(shop|store|stall|duka|no\.?|nr\.?|#|\p{N})/iu', $w)) {
                break;   // "Shop No 48" and friends are labels, not names
            }
            $out[] = $w;
            if (count($out) >= 2) {
                break;
            }
        }

        $first = $out ? $out[0] : $name;   // first name alone reads warmest
        if (preg_match('/^\p{Lu}{3,}$/u', $first)) {
            $first = mb_convert_case(mb_strtolower($first), MB_CASE_TITLE, 'UTF-8');   // "ABDI" → "Abdi"
        }

        return $first;
    }
}
