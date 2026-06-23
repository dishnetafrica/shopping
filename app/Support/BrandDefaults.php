<?php
namespace App\Support;

/**
 * Default brand-site content for manufacturer tenants. Single source of truth shared by
 * StorefrontController::brand() (render fallback) and PanelApiController (so the seller panel
 * pre-fills these instead of showing blanks). A tenant setting always overrides the default.
 */
class BrandDefaults
{
    /** Marketing text fields. */
    public static function text(): array
    {
        return [
            'tagline'         => 'Wholesale paper & tissue · order on WhatsApp',
            'eyebrow'         => 'Made in Uganda',
            'trustLine'       => 'Manufacturer · 100% Virgin Pulp · UNBS & ISO 9001 · Wholesale Trade Pricing',
            'heroTitle'       => 'Paper & tissue,<br>manufactured in Uganda.',
            'heroText'        => 'We manufacture our own brands — virgin-pulp tissue, napkins and copier paper supplied to shops, offices and institutions across the region.',
            'metaDescription' => '',
        ];
    }

    public static function stats(): array
    {
        return [
            ['k' => '3 own brands',     'l' => 'EuroPearl · Angel Soft · Orchid'],
            ['k' => '100% Virgin Pulp', 'l' => 'Premium tissue grade'],
            ['k' => 'UNBS · ISO 9001',  'l' => 'Certified quality'],
            ['k' => 'Kampala & Juba',   'l' => 'Wholesale delivery'],
        ];
    }

    public static function brands(string $accent = '#103A8C'): array
    {
        return [
            ['name' => 'EuroPearl', 'color' => $accent, 'tag' => 'Truly white & very soft — premium virgin tissue',
             'items' => ['Toilet paper — 150 / 200 / 300 sheets, 2-ply', 'Copier paper — A4 80 GSM', 'Thermal & POS rolls'],
             'chips' => ['2-Ply', '100% Virgin', 'Premium']],
            ['name' => 'Angel Soft', 'color' => '#1C7A41', 'tag' => 'A piece of heaven — sophistication at the table',
             'items' => ['Paper serviettes & napkins', 'Virgin, 100 sheets · 300×300mm', '60 packs / carton'],
             'chips' => ['Virgin', 'Soft & Gentle', 'Carton']],
            ['name' => 'Orchid', 'color' => '#9A6A20', 'tag' => 'Everyday value — built for bulk supply',
             'items' => ['Blended economy toilet paper', 'Economy napkins', '100 rolls / carton'],
             'chips' => ['Economy', 'Bulk', 'Carton']],
        ];
    }

    public static function faq(): array
    {
        return [
            ['q' => 'How do I place an order?', 'a' => 'Browse the shop, tap "Add to order" on the items you want, then check out on WhatsApp. We confirm stock and arrange delivery.'],
            ['q' => 'What is the minimum order?', 'a' => 'Wholesale items sell by the carton with a minimum (often 3 cartons). Retail packs are available with no minimum.'],
            ['q' => 'Do you sell small quantities or single packs?', 'a' => 'Yes — we offer retail packs (2 and 4-roll) alongside full cartons for wholesale buyers.'],
            ['q' => 'Do you offer wholesale / trade pricing?', 'a' => 'Yes. Prices are per carton, direct from the factory — no middleman.'],
            ['q' => 'Do you deliver, and where?', 'a' => 'We deliver across Kampala and Juba, and nationwide on request. Delivery time is confirmed when we take your order.'],
            ['q' => 'How do I pay?', 'a' => 'Payment is arranged on WhatsApp when we confirm your order — for example Mobile Money or on delivery.'],
            ['q' => 'Are your products certified?', 'a' => 'Yes — UNBS certified, ISO 9001:2015, made from 100% virgin pulp.'],
            ['q' => 'Can I become a distributor or reseller?', 'a' => 'Yes. Use the "Become a distributor" form, or message us on WhatsApp with your area and the brands you want to carry.'],
            ['q' => 'Do you supply offices and institutions?', 'a' => 'Yes — we supply shops, offices, schools and institutions with bulk carton pricing and reliable repeat supply.'],
        ];
    }
}
