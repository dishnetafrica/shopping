<?php

namespace App\Support;

/**
 * Layout schema for the multi-page Settings hub (v3 → Settings). Declares the left-menu SECTIONS, each
 * with grouped FIELDS. This is a UI/layout + read-mapping layer only — every write goes through the one
 * existing write path, App\Services\Settings\SettingsWriteService::apply, whose typed allow-lists are the
 * source of truth for validation. Field `key`s here are the INPUT keys that service understands.
 *
 * Adding a setting later = add a field here (and, if it's a new key, to SettingsWriteService's allow-list).
 */
final class SettingsSchema
{
    public static function sections(): array
    {
        return [
            ['key' => 'business', 'label' => 'Business profile', 'icon' => 'user', 'groups' => [
                ['label' => 'Identity', 'fields' => [
                    ['key' => 'business_name', 'type' => 'text', 'label' => 'Business name'],
                    ['key' => 'trading_as',    'type' => 'text', 'label' => 'Trading as'],
                    ['key' => 'owner_name',     'type' => 'text', 'label' => 'Owner name'],
                ]],
                ['label' => 'Contact', 'fields' => [
                    ['key' => 'public_phone', 'type' => 'text', 'label' => 'Public phone'],
                    ['key' => 'public_email', 'type' => 'text', 'label' => 'Public email'],
                    ['key' => 'public_website', 'type' => 'text', 'label' => 'Website', 'help' => 'e.g. familyshoppers.net — shown on the catalogue (clickable) so customers can also order online.'],
                    ['key' => 'address',      'type' => 'text', 'label' => 'Address'],
                    ['key' => 'city',         'type' => 'text', 'label' => 'City'],
                    ['key' => 'country',      'type' => 'text', 'label' => 'Country'],
                    ['key' => 'tin',          'type' => 'text', 'label' => 'TIN / Tax ID'],
                ]],
                ['label' => 'Branding', 'fields' => [
                    ['key' => 'tagline', 'type' => 'text', 'label' => 'Tagline'],
                    ['key' => 'logo',    'type' => 'image', 'label' => 'Logo', 'help' => 'Upload a PNG/JPG or paste a link. Shown on documents and the catalogue.'],
                ]],
                ['label' => 'AI knowledge', 'help' => 'How much of the product list the AI carries in its head during chats.', 'fields' => [
                    ['key' => 'ai_catalogue_limit', 'type' => 'number', 'label' => 'Products the AI knows', 'help' => 'The AI is given this many products (alphabetical) as ground truth in every chat. Set it ABOVE your product count (you have ~351 → use 400). Max 600.'],
                ]],
                ['label' => 'Delivery', 'help' => 'What the bot AND the catalogue say about delivery and transport.', 'fields' => [
                    ['key' => 'delivery_note', 'type' => 'textarea', 'label' => 'Delivery policy', 'help' => 'One or two sentences, e.g. "All prices include free delivery within Juba. Outside Juba, our team confirms transport cost when you order." Leave blank to auto-build from the towns below.'],
                    ['key' => 'free_delivery_towns', 'type' => 'text', 'label' => 'Free-delivery areas', 'help' => 'Comma-separated. Default: Juba, Konyo Konyo, Custom, Jebel, Munuki, Gudele, Hai, Malakia, Gumbo, Sherikat, Thongping, Nimra Talata, Suk'],
                    ['key' => 'delivery_from_city', 'type' => 'text', 'label' => 'Delivering from', 'help' => 'Default: Juba'],
                ]],
                ['label' => 'Catalogue', 'help' => 'Artwork and highlights for the photo catalogue PDF customers receive on WhatsApp.', 'fields' => [
                    ['key' => 'catalog_cover_image', 'type' => 'image', 'label' => 'Cover photo', 'help' => 'Portrait photo (about 4:5, no text in the image) for the front page.'],
                    ['key' => 'catalog_promo_image', 'type' => 'image', 'label' => 'Promo photo', 'help' => 'Wide photo for the "Need a product you don\'t see?" band (optional).'],
                    ['key' => 'catalog_best_sellers', 'type' => 'text', 'label' => 'Best sellers', 'help' => 'Comma-separated SKUs or exact product names → green BEST SELLER badge.'],
                    ['key' => 'catalog_hot_deals', 'type' => 'text', 'label' => 'Hot deals', 'help' => 'Comma-separated SKUs or exact names → orange HOT DEAL badge.'],
                    ['key' => 'catalog_audience', 'type' => 'text', 'label' => 'Cover checklist', 'help' => 'Comma list, e.g. Hotels,Restaurants,Supermarkets,Retailers.'],
                ]],
            ]],
            ['key' => 'currency', 'label' => 'Currency & rates', 'icon' => 'dollar', 'groups' => [
                ['label' => 'Reporting', 'help' => 'Your accounting currency and how figures are shown.', 'fields' => [
                    ['key' => 'base_currency',        'type' => 'enum', 'label' => 'Base currency', 'options' => ['USD', 'UGX', 'SSP'], 'default' => 'USD'],
                    ['key' => 'currency_basis',       'type' => 'enum', 'label' => 'Sell in', 'options' => ['native', 'converted'], 'default' => 'converted', 'optionLabels' => ['native' => 'Base currency (USD) — no conversion', 'converted' => 'Local currency (UGX)'], 'help' => 'Native = prices, orders, invoices & payments are all in your base currency. Choose this if you sell in USD.'],
                    ['key' => 'report_currency_mode', 'type' => 'enum', 'label' => 'Report mode', 'options' => ['base', 'original'], 'default' => 'base', 'optionLabels' => ['base' => 'Consolidated to base', 'original' => 'Original currency']],
                ]],
                ['label' => 'Exchange rates (per 1 USD)', 'help' => 'Used to convert local-currency amounts for reporting.', 'fields' => [
                    ['key' => 'rate_ugx_per_usd', 'type' => 'number', 'label' => 'UGX per USD'],
                    ['key' => 'rate_ssp_per_usd', 'type' => 'number', 'label' => 'SSP per USD'],
                ]],
            ]],
            ['key' => 'invoicing', 'label' => 'Invoice & tax', 'icon' => 'invoice', 'groups' => [
                ['label' => 'VAT / Sales tax', 'help' => 'VAT is never hardcoded — set your own rate (or turn it off). Uganda 18%, Kenya 16%, zero-rated: all just configuration.', 'fields' => [
                    ['key' => 'vat_enabled',        'type' => 'bool',   'label' => 'Charge VAT / sales tax'],
                    ['key' => 'vat_rate',           'type' => 'number', 'label' => 'VAT rate', 'unit' => '%', 'default' => 0],
                    ['key' => 'prices_include_vat', 'type' => 'bool',   'label' => 'Catalogue prices already include VAT'],
                    ['key' => 'vat_label',          'type' => 'text',   'label' => 'Tax label', 'default' => 'VAT'],
                    ['key' => 'vat_number',         'type' => 'text',   'label' => 'Tax registration no. (shown on invoices)'],
                ]],
                ['label' => 'Invoice defaults', 'fields' => [
                    ['key' => 'invoice_title',      'type' => 'text',     'label' => 'Invoice title', 'default' => 'INVOICE'],
                    ['key' => 'invoice_terms_days', 'type' => 'number',   'label' => 'Payment terms', 'unit' => 'days', 'default' => 0],
                    ['key' => 'bank_details',       'type' => 'textarea', 'label' => 'Bank details'],
                    ['key' => 'invoice_footer',     'type' => 'textarea', 'label' => 'Invoice footer'],
                ]],
            ]],
            ['key' => 'documents', 'label' => 'Documents', 'icon' => 'doc', 'groups' => [
                ['label' => 'PDF page setup', 'help' => 'Page size & layout for the PDFs your customers receive. Defaults match the built-in layout.', 'fields' => [
                    ['key' => 'invoice_page_size',   'type' => 'enum', 'label' => 'Invoice page size', 'options' => ['A4', 'A5', 'Letter'], 'default' => 'A4'],
                    ['key' => 'invoice_orientation', 'type' => 'enum', 'label' => 'Invoice orientation', 'options' => ['portrait', 'landscape'], 'default' => 'portrait'],
                    ['key' => 'receipt_page_size',   'type' => 'enum', 'label' => 'Receipt page size', 'options' => ['A4', 'A5'], 'default' => 'A5'],
                    ['key' => 'receipt_orientation', 'type' => 'enum', 'label' => 'Receipt orientation', 'options' => ['portrait', 'landscape'], 'default' => 'landscape'],
                ]],
            ]],
            ['key' => 'inventory', 'label' => 'Stock tracking', 'icon' => 'warehouse', 'groups' => [
                ['label' => 'Real inventory', 'help' => 'Turn on once your on-hand counts are trusted.', 'fields' => [
                    ['key' => 'stock_ledger', 'type' => 'bool', 'label' => 'Track real stock (record receipts & sales)'],
                    ['key' => 'stock_v2',     'type' => 'bool', 'label' => 'Show live stock on site & bot'],
                ]],
            ]],
            ['key' => 'whatsapp', 'label' => 'WhatsApp & bot', 'icon' => 'mail', 'groups' => [
                ['label' => 'Bot messages', 'help' => 'What your WhatsApp assistant says.', 'fields' => [
                    ['key' => 'bot_greeting',      'type' => 'textarea', 'label' => 'Greeting'],
                    ['key' => 'bot_fallback_text', 'type' => 'textarea', 'label' => 'Fallback reply'],
                    ['key' => 'ai_persona',        'type' => 'textarea', 'label' => 'AI persona'],
                    ['key' => 'business_hours',    'type' => 'text',     'label' => 'Business hours'],
                    ['key' => 'bot_website_link',  'type' => 'text',     'label' => 'Website link'],
                    ['key' => 'faq',               'type' => 'textarea', 'label' => 'FAQ'],
                ]],
                // MOQ enforcement (v4.6) — the engine has always supported this (OrderCalculator +
                // AiBrain read moq_mode), but the switch only lived in the one-time onboarding wizard.
                ['label' => 'Selling rules', 'help' => 'How the assistant treats each product\'s minimum order quantity (the MOQ field on the product).', 'fields' => [
                    ['key' => 'moq_mode', 'type' => 'enum', 'label' => 'Minimum order quantities', 'options' => ['off', 'roundup'], 'default' => 'off',
                     'optionLabels' => ['off' => 'Advisory — quote any quantity, mention the minimum', 'roundup' => 'Enforced — round below-minimum requests up to the MOQ'],
                     'help' => 'Enforced: when a customer asks for less than a product\'s MOQ, the quote is raised to the MOQ, the line says "min order N — set to N", and the AI is instructed never to confirm a smaller quantity. Only products with the MOQ field filled are enforced (Products → edit, or import column "min order").'],
                ]],
            ]],
            ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'groups' => [
                ['label' => 'Daily digest', 'help' => 'A summary of the day sent to you.', 'fields' => [
                    ['key' => 'digest_enabled',     'type' => 'bool',   'label' => 'Send daily digest'],
                    ['key' => 'digest_hour',        'type' => 'number', 'label' => 'Send at hour', 'unit' => '24h', 'default' => 18],
                    ['key' => 'cashbook_digest_to', 'type' => 'text',   'label' => 'Daily cashbook email', 'help' => 'Blank = off'],
                ]],
                ['label' => 'Task reminders', 'help' => 'WhatsApp each staff member a digest of their own open tasks twice a day (9am & 2pm, Juba time). Only people with open tasks and a phone number are messaged.', 'fields' => [
                    ['key' => 'task_reminders', 'type' => 'bool', 'label' => 'Send task reminders to assignees'],
                ]],
                ['label' => 'Stock alerts', 'fields' => [
                    ['key' => 'low_stock_threshold', 'type' => 'number', 'label' => 'Low-stock threshold', 'unit' => 'units', 'default' => 5],
                    ['key' => 'reorder_days',        'type' => 'number', 'label' => 'Reorder lead time', 'unit' => 'days', 'default' => 30],
                ]],
            ]],
            ['key' => 'storefront', 'label' => 'Storefront', 'icon' => 'doc', 'groups' => [
                ['label' => 'Bike models on the website', 'help' => 'Turn a model ON to show it on your customer website, OFF to hide it. Changes take effect as soon as you save. Tip: keep at least one model on.', 'fields' => [
                    ['key' => 'show_model_boxer100',   'type' => 'bool', 'label' => 'Boxer 100 KS',  'default' => true],
                    ['key' => 'show_model_boxer125hd', 'type' => 'bool', 'label' => 'Boxer 125 HD',  'default' => true],
                    ['key' => 'show_model_boxer150',   'type' => 'bool', 'label' => 'Boxer BM150',   'default' => true],
                    ['key' => 'show_model_boxer125x',  'type' => 'bool', 'label' => 'Boxer BM125X',  'default' => true],
                    ['key' => 'show_model_ct125hd',    'type' => 'bool', 'label' => 'CT 125 HD',     'default' => true],
                    ['key' => 'show_model_boxer150x',  'type' => 'bool', 'label' => 'Boxer BM150 X', 'default' => true],
                ]],
                ['label' => 'Availability', 'help' => 'For a parts business — sell only what you actually have.', 'fields' => [
                    ['key' => 'sf_hide_oos', 'type' => 'bool', 'label' => 'Show only parts in stock (hide out-of-stock)', 'default' => false, 'help' => 'When ON, any part with 0 stock is hidden from listings, search and browse. Parts with no stock number set (untracked) still show as “to order”.'],
                ]],
                // v4.6.1 — which host goes into links the system SENDS (quotation review, order tracking).
                ['label' => 'Custom domain', 'help' => 'Which web address customers see in WhatsApp links (quotation review, order tracking).', 'fields' => [
                    ['key' => 'custom_domain_live', 'type' => 'bool', 'label' => 'My own domain points to CloudBSS', 'default' => false,
                     'help' => 'Turn ON only after your domain (e.g. familyshoppers.net) is actually connected to CloudBSS hosting — links will then use it. While OFF, links use the platform address, which always works. If your domain hosts a separate website, keep this OFF or every sent link breaks.'],
                ]],
                ['label' => 'Trust & delivery promises', 'help' => 'Shown on your product pages and homepage to reassure customers before they buy. Leave blank to use sensible defaults.', 'fields' => [
                    ['key' => 'sf_genuine',      'type' => 'text', 'label' => 'Genuine-parts promise', 'help' => 'e.g. 100% genuine Bajaj — verify the hologram, or your money back.'],
                    ['key' => 'sf_delivery_eta', 'type' => 'text', 'label' => 'Delivery time', 'help' => 'e.g. Same-day in town · 24–48h upcountry'],
                    ['key' => 'sf_pay_methods',  'type' => 'text', 'label' => 'Payment methods', 'help' => 'e.g. Mobile Money · Pay on Delivery'],
                    ['key' => 'sf_warranty',     'type' => 'text', 'label' => 'Warranty', 'help' => 'e.g. 1-year genuine-parts warranty'],
                    ['key' => 'sf_returns',      'type' => 'text', 'label' => 'Returns policy', 'help' => 'e.g. 7-day returns on unfitted parts'],
                ]],
            ]],
            ['key' => 'delivery', 'label' => 'Delivery', 'icon' => 'truck', 'groups' => [
                ['label' => 'Delivery', 'fields' => [
                    ['key' => 'delivery_areas', 'type' => 'textarea', 'label' => 'Delivery areas'],
                    ['key' => 'delivery_note',  'type' => 'textarea', 'label' => 'Delivery note', 'help' => 'Shown to customers at checkout.'],
                    ['key' => 'min_order',      'type' => 'number',   'label' => 'Minimum order value', 'default' => 0],
                    ['key' => 'moq_note',       'type' => 'text',     'label' => 'MOQ note'],
                ]],
            ]],
            ['key' => 'payments', 'label' => 'Payments', 'icon' => 'card', 'groups' => [
                ['label' => 'Accepted payments', 'help' => 'How customers can pay.', 'fields' => [
                    ['key' => 'pay_on_delivery', 'type' => 'bool', 'label' => 'Offer pay on delivery'],
                    ['key' => 'pay_unify',       'type' => 'bool', 'label' => 'Unified payment handling'],
                ]],
                // POS till controls (v3.9) — discount authorisation + shift discipline.
                ['label' => 'POS till', 'help' => 'Controls for the supermarket checkout.', 'fields' => [
                    ['key' => 'pos_discount_limit', 'type' => 'number', 'label' => 'Discount limit without manager (%)', 'default' => 5,
                     'help' => 'A POS discount above this percent of the sale needs the manager PIN. 0 = every discount needs the PIN; leave the PIN empty to disable the check.'],
                    ['key' => 'pos_manager_pin', 'type' => 'text', 'label' => 'Manager PIN (4–8 digits)',
                     'help' => 'Asked at the till for big discounts. Only the owner sees this page.'],
                ]],
            ]],
            ['key' => 'team', 'label' => 'Team', 'icon' => 'user', 'special' => 'team'],
        ];
    }

    /** Flat list of every field spec across sections (for value resolution + defaults). */
    public static function fields(): array
    {
        $out = [];
        foreach (self::sections() as $sec) {
            foreach (($sec['groups'] ?? []) as $g) {
                foreach (($g['fields'] ?? []) as $f) {
                    $out[$f['key']] = $f;
                }
            }
        }

        return $out;
    }
}
