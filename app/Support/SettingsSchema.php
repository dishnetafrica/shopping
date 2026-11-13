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
                    // v4.30 — until now only a master admin could set this, so a shop had no way to
                    // say what it sells. It decides which settings and menu entries are worth showing.
                    ['key' => 'vertical', 'type' => 'enum', 'label' => 'What kind of business is this?',
                     'options' => \App\Support\Vertical::ALL, 'optionLabels' => \App\Support\Vertical::LABELS,
                     'default' => \App\Support\Vertical::GROCERY,
                     'help' => 'Settings and menu entries that belong to other kinds of business are hidden — anything you have already set up stays visible whatever you pick. It also changes how your storefront and the WhatsApp bot behave, so only change it if it is genuinely wrong.'],
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
                    // v4.38 — towns we know of but do not deliver free to. Anything matching NEITHER
                    // list is treated as "we do not recognise this place" and the bot asks which town
                    // it is in, instead of telling the customer their delivery is chargeable.
                    ['key' => 'known_delivery_towns', 'type' => 'text', 'label' => 'Other towns we know', 'help' => 'Comma-separated towns we can quote transport to. A destination in neither list makes the bot ask which town it is in, rather than assume it is far away. Default covers South Sudan, Uganda and the regional cities.'],
                    ['key' => 'delivery_from_city', 'type' => 'text', 'label' => 'Delivering from', 'help' => 'Default: Juba'],
                ]],
                ['label' => 'Catalogue', 'help' => 'Artwork and highlights for the photo catalogue PDF customers receive on WhatsApp.', 'fields' => [
                    ['key' => 'catalog_cover_image', 'type' => 'image', 'label' => 'Cover photo', 'help' => 'Portrait photo (about 4:5, no text in the image) for the front page.'],
                    ['key' => 'catalog_promo_image', 'type' => 'image', 'label' => 'Promo photo', 'help' => 'Wide photo for the "Need a product you don\'t see?" band (optional).'],
                    ['key' => 'catalog_best_sellers', 'type' => 'text', 'label' => 'Best sellers', 'help' => 'Comma-separated SKUs or exact product names → green BEST SELLER badge.'],
                    ['key' => 'catalog_hot_deals', 'type' => 'text', 'label' => 'Hot deals', 'help' => 'Comma-separated SKUs or exact names → orange HOT DEAL badge.'],
                    ['key' => 'catalog_audience', 'type' => 'text', 'label' => 'Cover checklist', 'help' => 'Comma list, e.g. Hotels,Restaurants,Supermarkets,Retailers.'],
                    // v4.7.7 — trust badge + value-prop tags (catalogue review).
                    ['key' => 'catalog_badge', 'type' => 'text', 'label' => 'Trust badge', 'help' => 'Short badge on the cover & back page, e.g. "✓ Genuine OEM-quality parts" or "✓ Wholesale prices, sealed cartons". Only claim Genuine once your supplier confirms it in writing.'],
                    ['key' => 'catalog_cover_tags', 'type' => 'text', 'label' => 'Cover tags', 'help' => 'One line under the title, e.g. "Wholesale Distributor · East Africa Coverage · Fast Delivery".'],
                ]],
            ]],
            ['key' => 'currency', 'label' => 'Currency & rates', 'icon' => 'dollar', 'groups' => [
                ['label' => 'Reporting', 'help' => 'Your accounting currency and how figures are shown.', 'fields' => [
                    ['key' => 'base_currency',        'type' => 'enum', 'label' => 'Base currency', 'options' => ['USD', 'UGX', 'SSP'], 'default' => 'USD'],
                    ['key' => 'currency_basis',       'type' => 'enum', 'label' => 'Sell in', 'options' => ['native', 'converted'], 'default' => 'converted', 'optionLabels' => ['native' => 'Base currency (USD) — no conversion', 'converted' => 'Local currency (UGX)'], 'help' => 'Native = prices, orders, invoices & payments are all in your base currency. Choose this if you sell in USD.'],
                    ['key' => 'report_currency_mode', 'type' => 'enum', 'label' => 'Report mode', 'options' => ['base', 'original'], 'default' => 'base', 'optionLabels' => ['base' => 'Consolidated to base', 'original' => 'Original currency']],
                ]],
                // v4.26 — the director-loan terms were hardcoded inside the Books report file; an owner
                // could never see or change their own rate. They are settings now.
                ['label' => 'Director loan', 'help' => 'Money a director or partner lent the business, shown as a liability on the Books screen with interest as a finance cost. Anyone named here has their capital treated as a LOAN to be repaid, not as owner\'s equity.', 'fields' => [
                    // KNOWN LEAK (not fixed here on purpose): this default is one real person's name,
                    // so every other shop's Director-loan setting reads "Bhavin". The Books page has the
                    // SAME hardcoded fallback twice more — PanelApiController::situationReportPage and
                    // situation-report.html — so blanking it here alone would zero a live loan balance
                    // rather than fix anything. It needs doing in one deliberate pass across all three.
                    ['key' => 'books.loan_holders', 'type' => 'text', 'label' => 'Lender name(s)', 'default' => 'Bhavin', 'help' => 'Comma-separated. Cash they put in counts as a loan; cash paid back to them (with "loan" or "repay" in the note) reduces it.'],
                    ['key' => 'books.loan_rate', 'type' => 'number', 'label' => 'Interest per month', 'unit' => 'fraction', 'default' => 0.03, 'help' => 'A FRACTION, not a percentage: 0.03 = 3% per month (≈42.6% a year compounded). Put 0.0025 for 3% a year. Interest accrues only on what is still outstanding, so repaying reduces it.'],
                    ['key' => 'books.stock_usd', 'type' => 'number', 'label' => 'Inventory value (USD)', 'default' => 0, 'help' => 'Manual stock value for the balance sheet until stock tracks from purchases.'],
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
                // v4.45 — quotation numbers you can read, say on the phone, and put in order.
                ['label' => 'Quotation numbering', 'help' => 'Quotation numbers have been a date plus four random characters (ORD-Q260806-ZH9Z) — impossible to read, impossible to put in order, and it was random collisions that forced the duplicate clean-up in v4.38. Switching to In order gives you QUO-2026-0001, QUO-2026-0002 … the same way your invoices (INV-2026-0001) and credit notes already work. Quotations already issued keep the numbers your customers were given; the new series starts at 1 and applies from the moment you switch.', 'fields' => [
                    ['key' => 'quote_number_mode', 'type' => 'enum', 'label' => 'Quotation numbers', 'options' => ['legacy', 'sequential'], 'default' => 'legacy',
                     'help' => 'legacy = ORD-Q260806-ZH9Z (date + random). sequential = QUO-2026-0001, counting up. Nothing already issued changes either way.'],
                    ['key' => 'quote_prefix', 'type' => 'text', 'label' => 'Quotation prefix', 'default' => 'QUO',
                     'help' => 'The letters before the year, e.g. QUO gives QUO-2026-0001. Only used when numbering is set to sequential. Keep it different from your invoice prefix so the two can never be confused.'],
                    ['key' => 'quote_number_reset', 'type' => 'enum', 'label' => 'Start again each year', 'options' => ['year', 'plain'], 'default' => 'year',
                     'help' => 'year = QUO-2026-0001 and the count restarts every January. plain = QUO-0001 running forever, never resetting.'],
                ]],
                // The paragraphs printed at the foot of every PDF quotation. Without these set, the
                // quote falls back to generic defaults ("Ex-stock Kampala", "100% payment before
                // dispatch") — wrong for most shops. Blank hides that paragraph.
                ['label' => 'Quotation terms', 'help' => 'The terms printed on every PDF quotation. Set them to YOUR real delivery, payment and pricing terms — otherwise a generic default is used. Leave a field blank to hide that line.', 'fields' => [
                    ['key' => 'quote_delivery',      'type' => 'textarea', 'label' => 'Delivery terms', 'help' => 'e.g. Ex-stock Juba. 24–48h upcountry.'],
                    ['key' => 'quote_terms_payment', 'type' => 'textarea', 'label' => 'Payment terms', 'help' => 'e.g. 50% advance with the order, balance on delivery.'],
                    ['key' => 'quote_pricing',       'type' => 'textarea', 'label' => 'Pricing terms', 'help' => 'e.g. Prices in USD, valid 14 days, subject to change without notice.'],
                    ['key' => 'quote_cancellation',  'type' => 'textarea', 'label' => 'Cancellation terms'],
                    ['key' => 'quote_payment_terms', 'type' => 'text',     'label' => 'Short payment line (in the summary box)', 'help' => 'A short version, e.g. "50% advance · balance on delivery".'],
                    ['key' => 'quote_prepared_by',   'type' => 'text',     'label' => 'Prepared by (name on the quote)'],
                ]],
                ['label' => 'Purchase orders (LPO)', 'help' => 'The Local Purchase Order you send suppliers. A product photo next to each line helps the supplier confirm the exact item (useful for parts); turn it off for plain black-and-white printing.', 'fields' => [
                    ['key' => 'lpo_photos', 'type' => 'bool', 'label' => 'Show product photos on the LPO', 'default' => true],
                    ['key' => 'lpo_terms',  'type' => 'textarea', 'label' => 'Default terms & conditions', 'help' => 'Printed on every LPO (blank = none). You can still override the terms on an individual LPO.'],
                ]],
                // v4.46 — customers were asking for stamped invoices and receipts, and staff were
                // stamping printouts by hand. Three of these documents already reserved a box for it.
                ['label' => 'Company stamp', 'help' => 'Upload your stamp once and it prints on the documents your customers receive, in the box each one already reserves for it. Use a PNG with a TRANSPARENT background — a stamp saved on a white square prints as a white box over the page. Leave the image empty and nothing is stamped, whatever the switches below say.', 'fields' => [
                    ['key' => 'stamp_image', 'type' => 'image', 'label' => 'Stamp image',
                     'help' => 'A square PNG with a transparent background works best. Around 1000×1000 is plenty.'],
                    ['key' => 'stamp_size', 'type' => 'number', 'label' => 'Printed size', 'unit' => 'px', 'default' => 150,
                     'help' => 'Roughly 150 suits an A4 invoice. Receipts, quotations and delivery notes scale down from this automatically to fit their own boxes.'],
                    ['key' => 'stamp_on_invoice',       'type' => 'bool', 'label' => 'Stamp invoices',        'default' => true],
                    ['key' => 'stamp_on_receipt',       'type' => 'bool', 'label' => 'Stamp receipts',        'default' => true],
                    ['key' => 'stamp_on_quotation',     'type' => 'bool', 'label' => 'Stamp quotations',      'default' => true],
                    ['key' => 'stamp_on_delivery_note', 'type' => 'bool', 'label' => 'Stamp delivery notes',  'default' => true],
                    ['key' => 'stamp_on_credit_note',   'type' => 'bool', 'label' => 'Stamp credit notes',    'default' => true],
                ]],
                ['label' => 'PDF page setup', 'help' => 'Page size & layout for the PDFs your customers receive. Defaults match the built-in layout.', 'fields' => [
                    ['key' => 'invoice_page_size',   'type' => 'enum', 'label' => 'Invoice page size', 'options' => ['A4', 'A5', 'Letter'], 'default' => 'A4'],
                    ['key' => 'invoice_orientation', 'type' => 'enum', 'label' => 'Invoice orientation', 'options' => ['portrait', 'landscape'], 'default' => 'portrait'],
                    ['key' => 'receipt_page_size',   'type' => 'enum', 'label' => 'Receipt page size', 'options' => ['A4', 'A5'], 'default' => 'A5'],
                    ['key' => 'receipt_orientation', 'type' => 'enum', 'label' => 'Receipt orientation', 'options' => ['portrait', 'landscape'], 'default' => 'landscape'],
                ]],
                // Printed on the Commercial Invoice and Packing List a clearing agent uses to clear
                // a cross-border shipment. Set once; every customs document reuses them. Exporter
                // fields override the general business name/address/TIN when the goods ship from a
                // different entity (e.g. a Kenya supplier invoicing a Uganda buyer).
                ['label' => 'Exporter / Customs profile', 'help' => 'Printed on your Commercial Invoice and Packing List for customs clearance. Leave a field blank to fall back to your business name / address / tax number.', 'fields' => [
                    ['key' => 'exporter_name',        'type' => 'text',     'label' => 'Exporter name', 'help' => 'If the goods are invoiced by a different entity from your business name.'],
                    ['key' => 'exporter_address',     'type' => 'textarea', 'label' => 'Exporter address'],
                    ['key' => 'exporter_tax_id',      'type' => 'text',     'label' => 'Exporter tax / customs PIN', 'help' => 'e.g. KRA PIN (Kenya) or TIN. Customs requires the exporter tax number.'],
                    ['key' => 'customs_incoterms',    'type' => 'enum',     'label' => 'Default Incoterms', 'options' => ['EXW', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DDP'], 'default' => 'FOB'],
                    ['key' => 'customs_country_export', 'type' => 'text',   'label' => 'Country of export', 'help' => 'Where the shipment is dispatched from, e.g. Kenya.'],
                    ['key' => 'customs_default_origin', 'type' => 'text',   'label' => 'Default country of origin', 'help' => 'Used per line when a product has no origin set, e.g. the manufacturer country.'],
                    ['key' => 'customs_declaration',  'type' => 'textarea', 'label' => 'Customs declaration text', 'help' => 'e.g. "We certify this invoice is true and correct and the goods are of the stated origin."'],
                    ['key' => 'customs_terms',        'type' => 'textarea', 'label' => 'Customs document footer terms'],
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
                    ['key' => 'bot_coalesce_seconds', 'type' => 'number', 'label' => 'Wait before replying (seconds)', 'default' => 4,
                     'help' => 'Customers often send two or three quick messages in a row. The bot waits this many seconds and answers the whole burst ONCE instead of replying to every line. 0 = answer each message immediately. Max 10.'],
                ]],
                // MOQ enforcement (v4.6) — the engine has always supported this (OrderCalculator +
                // AiBrain read moq_mode), but the switch only lived in the one-time onboarding wizard.
                // v4.32 — nothing used to stop a broadcast reaching anyone in the audience.
                ['label' => 'Never message these numbers', 'help' => 'A do-not-message list for campaigns and broadcasts. Anyone here is skipped by every campaign — the classic WhatsApp blast, AI Campaign and the Email Center. It does NOT stop the bot replying to them if they message you first; that is Chats → mute.', 'fields' => [
                    ['key' => 'campaign_exclude', 'type' => 'textarea', 'label' => 'Numbers to exclude', 'help' => 'One per line, or separated by commas. Any format works — 0772 123 456, +256772123456, 256-772-123456 all match the same person, so you do not have to know how the number was saved. Leave blank for none.'],
                    ['key' => 'campaign_exclude_staff', 'type' => 'bool', 'label' => 'Never message my own team', 'default' => true, 'help' => 'Skips your own WhatsApp line, your owner-alert numbers and anyone on the order-notification list. ON by default: broadcasting to your own staff looks careless to them and quietly skews every reply figure you then read.'],
                ]],
                ['label' => 'Marketing safety limits', 'help' => 'Conservative, configurable ceilings for WhatsApp marketing broadcasts. These are INTERNAL safety controls — not WhatsApp-guaranteed-safe numbers. Raise them slowly, and only after healthy delivery and low opt-outs; the system never raises them on its own.', 'fields' => [
                    ['key' => 'marketing_daily_limit', 'type' => 'int', 'label' => 'Max marketing messages per day', 'default' => 200, 'help' => 'The most WhatsApp marketing messages this shop will send in one day, across every campaign. Start low. Increasing it is always a deliberate change here.'],
                    ['key' => 'marketing_min_interval_days', 'type' => 'int', 'label' => 'Minimum days between marketing to one customer', 'default' => 3, 'help' => 'A customer is never marketed to again inside this many days, even across different campaigns. Set 0 to turn the per-customer frequency guard off.'],
                ]],
                ['label' => 'Customer re-engagement (Phase 1 — planning only)', 'help' => 'The daily re-engagement engine scores past customers and builds a "who to contact today" plan for you to review on the Customer Engagement screen. Phase 1 SENDS NOTHING — it only plans. Sending stays off until you enable it and the consent/template work is done.', 'fields' => [
                    ['key' => 'engagement_enabled', 'type' => 'bool', 'label' => 'Build a daily re-engagement plan', 'default' => false, 'help' => 'When ON, the nightly job scores your past customers and fills the Customer Engagement plan for the day. This never sends a message on its own — it only prepares the list for your review.'],
                    ['key' => 'engagement_daily_target', 'type' => 'int', 'label' => 'Customers to contact per day (target)', 'default' => 40, 'help' => 'How many of the highest-scoring eligible customers to put on each day\'s list. 35–40 is a sensible start for a few hundred customers; the most it will ever pick is 50.'],
                    ['key' => 'engagement_cooldown_days', 'type' => 'int', 'label' => 'Minimum days before contacting the same customer again', 'default' => 14, 'help' => 'A customer picked today will not be picked again for this many days, so the list rotates through everyone instead of hammering the same people. 14–21 days is the recommended range.'],
                    ['key' => 'engagement_send_enabled', 'type' => 'bool', 'default' => false, 'label' => 'Actually SEND the daily re-engagement messages', 'help' => 'OFF by default. When ON, ShopBot sends a small, paced batch each day to the OPTED-IN customers on the plan — only ever to people who explicitly opted in, within all your marketing safety limits. On an unofficial WhatsApp number, marketing carries a real risk of the number being restricted; keep the daily cap low.'],
                    ['key' => 'engagement_send_daily_cap', 'type' => 'int', 'default' => 15, 'label' => 'Most re-engagement messages to send per day', 'help' => 'A conservative ceiling for the re-engagement engine specifically (on top of your overall marketing daily limit). Start at 10–15 and raise slowly only after healthy delivery and low opt-outs.'],
                    ['key' => 'engagement_send_batch', 'type' => 'int', 'default' => 2, 'label' => 'Messages sent per batch', 'help' => 'How many messages go out each time the sender runs (it runs several times through the day). Small batches spread the sends out, which is safer for the number.'],
                ]],
                ['label' => 'Selling rules', 'help' => 'How the assistant treats each product\'s minimum order quantity (the MOQ field on the product).', 'fields' => [
                    ['key' => 'moq_mode', 'type' => 'enum', 'label' => 'Minimum order quantities', 'options' => ['off', 'roundup'], 'default' => 'off',
                     'optionLabels' => ['off' => 'Advisory — quote any quantity, mention the minimum', 'roundup' => 'Enforced — round below-minimum requests up to the MOQ'],
                     'help' => 'Enforced: when a customer asks for less than a product\'s MOQ, the quote is raised to the MOQ, the line says "min order N — set to N", and the AI is instructed never to confirm a smaller quantity. Only products with the MOQ field filled are enforced (Products → edit, or import column "min order").'],
                ]],
                // Travel Vertical (M1) — shown only to travel agencies.
                ['label' => 'Travel desk', 'only' => ['travel'], 'help' => 'How your travel assistant takes flight enquiries. It collects the trip details, never states a fare and never asks for passport details; your team prices each trip from the Trips screen.', 'fields' => [
                    ['key' => 'travel_home_airport', 'type' => 'text', 'label' => 'Where most customers fly from', 'help' => 'For example "Entebbe". The assistant offers it as the likely departure point but always asks the customer to confirm.'],
                    ['key' => 'travel_services', 'type' => 'textarea', 'label' => 'Services you offer', 'help' => 'For example: air tickets, hotel bookings, safaris, visa processing, airport transfers, travel insurance. The assistant only offers what is listed here.'],
                    ['key' => 'travel_booking_terms', 'type' => 'textarea', 'label' => 'Booking and payment terms', 'help' => 'Deposit, how customers pay, and when a booking is confirmed. The assistant explains these in your words and never invents an amount or a deadline.'],
                    ['key' => 'travel_cancellation_policy', 'type' => 'textarea', 'label' => 'Changes and cancellations', 'help' => 'Your rules for changing or cancelling a booking. Requests on an existing ticket are always passed to your team.'],
                    ['key' => 'travel_abandon_hours', 'type' => 'int', 'default' => 48, 'label' => 'Hours of silence before an enquiry counts as "no reply"', 'help' => 'Used on the Trips screen to highlight enquiries where the customer stopped answering.'],
                    ['key' => 'travel_use_history', 'type' => 'bool', 'default' => true, 'label' => 'Recognise returning customers', 'help' => 'When ON, the assistant can see a customer\'s earlier trip requests with your agency and offer the same details again. It always asks before reusing them. Turn OFF to have every conversation start from nothing.'],
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
                // v4.34 — the other half of the evidence archive: gaps you never see never close.
                ['label' => 'Weekly receipt reminder', 'help' => 'Every Monday morning, a WhatsApp listing the payments that still have no receipt or invoice attached, biggest first. It goes to your owner-alert numbers. If nothing is missing, nothing is sent — so a message arriving always means there is something to do. Attach the documents in Finance → Evidence.', 'fields' => [
                    ['key' => 'evidence_digest', 'type' => 'bool', 'label' => 'Send the weekly receipt reminder', 'default' => true,
                     'help' => 'ON by default. Turn it off and the Evidence screen still works — you simply have to remember to look.'],
                    ['key' => 'evidence_digest_min', 'type' => 'number', 'label' => 'Only remind me about payments above', 'unit' => 'USD', 'default' => 0,
                     'help' => '0 = every payment. Set it to 50 and small cash items stop appearing, so the list stays short enough to actually work through.'],
                    ['key' => 'evidence_digest_numbers', 'type' => 'text', 'label' => 'Also send to', 'help' => 'Extra numbers, comma separated. Your owner-alert numbers always receive it. Blank = just them.'],
                ]],
                ['label' => 'Stock alerts', 'fields' => [
                    ['key' => 'low_stock_threshold', 'type' => 'number', 'label' => 'Low-stock threshold', 'unit' => 'units', 'default' => 5],
                    ['key' => 'reorder_days',        'type' => 'number', 'label' => 'Reorder lead time', 'unit' => 'days', 'default' => 30],
                ]],
                // v4.25 — the customer's side of billing: they should never have to ask what they owe.
                ['label' => 'Customer billing messages', 'help' => 'WhatsApp your customer automatically when you invoice them and when you receive their money. Each message carries the PDF plus a plain summary — what it is for, what is paid, and what is still due. Sent to the phone number on the invoice or payment.', 'fields' => [
                    ['key' => 'notify_customer_invoice', 'type' => 'bool', 'label' => 'Send the invoice when it is issued', 'default' => true, 'help' => 'Invoice PDF + total, anything already paid, the balance, the due date and how to pay. Sent once per invoice.'],
                    ['key' => 'notify_customer_receipt', 'type' => 'bool', 'label' => 'Send a receipt when payment is received', 'default' => true, 'help' => 'Receipt PDF + what they just paid, what they have paid in total against that invoice, and the balance still due (or "fully paid"). Works for part payments and advances.'],
                ]],
                // v4.43 — the message that settles the argument at the truck.
                ['label' => 'Order confirmation', 'help' => 'When you confirm an order, WhatsApp the customer exactly what they are getting — every item, quantity and unit price, the total, the delivery address and the payment terms — and ask them to confirm it. The order screen then shows whether they agreed, and warns you if the order was changed afterwards. This replaces the old one-line "your order is confirmed" message (and any custom wording you set for that status).', 'fields' => [
                    ['key' => 'notify_order_confirmation', 'type' => 'bool', 'label' => 'Send the full order for the customer to check', 'default' => true,
                     'help' => 'ON by default. Turn it off and the customer goes back to receiving only the order number — which is nothing they can be held to.'],
                    ['key' => 'order_confirm_link', 'type' => 'bool', 'label' => 'Include a one-tap confirm link', 'default' => true,
                     'help' => 'Adds a link that records the date and time they agreed. Replying YES on WhatsApp counts the same, so turning this off does not stop confirmations — it only removes the button.'],
                    ['key' => 'order_confirm_terms', 'type' => 'text', 'label' => 'Payment wording on the confirmation',
                     'help' => 'e.g. "cash on delivery" or "30 days from invoice". Leave blank and each order says what it already has against it — "due on delivery", or the amount paid and the balance.'],
                ]],
            ]],
            ['key' => 'storefront', 'label' => 'Storefront', 'icon' => 'doc', 'groups' => [
                // v4.69.1 — the wholesale homepage (ADR-017), on the settings screen people actually
                // open. The workspace-capability form shipped first and nobody could find it: the
                // panel's Settings menu routes HERE (/papi/settings-hub), not to the workspace
                // settings surface. Same keys, same single write path (SettingsWriteService), and
                // the image fields ride the existing branding upload — any schema field typed
                // 'image' is automatically uploadable (the v4.16 rule).
                ['label' => 'Your homepage (wholesale look)', 'help' => 'The new wholesale homepage: hero with your headline and photo, Shop-by-Business cards, best sellers with carton prices and minimum orders. Switch it on here, upload the hero artwork, and edit every line of copy. Blank fields fall back to sensible text — and a typo in a JSON field can never break the shop; that section just shows its default or hides.', 'fields' => [
                    ['key' => 'storefront_home', 'type' => 'enum', 'label' => 'Homepage style', 'options' => ['classic', 'wholesale'], 'default' => 'classic',
                     'optionLabels' => ['classic' => 'Classic — the original homepage', 'wholesale' => 'Wholesale — hero, business cards, carton-first best sellers'],
                     'help' => 'Switching back to Classic only changes the layout — your photos and text stay saved for when you switch again.'],
                    ['key' => 'home_hero_image', 'type' => 'image', 'label' => 'Hero photo — desktop (1600×600 works best — any photo is fine, the system converts it)',
                     'help' => 'Products only — no headline, prices or buttons inside the image; the text is typed and stays sharp on every screen. Leave clean space on the LEFT where the headline sits. Blank = a shelf of your top products stands in.'],
                    ['key' => 'home_hero_image_mobile', 'type' => 'image', 'label' => 'Hero photo — mobile (750×750 works best — any photo is fine, the system converts it)',
                     'help' => 'Shown below the hero buttons on phones. Blank = a compact text-only hero, which also looks fine.'],
                    ['key' => 'home_hero_title', 'type' => 'text', 'label' => 'Hero headline', 'help' => 'Blank = "Your Trusted Wholesale Partner".'],
                    ['key' => 'home_hero_title2', 'type' => 'text', 'label' => 'Hero second line (the red one)', 'help' => 'Blank = "in" + your city from Business profile.'],
                    ['key' => 'home_hero_title3', 'type' => 'text', 'label' => 'Hero third line', 'help' => 'e.g. "for Every Business". Blank = no third line.'],
                    ['key' => 'home_hero_sub', 'type' => 'text', 'label' => 'Hero supporting line'],
                    ['key' => 'home_benefits', 'type' => 'textarea', 'label' => 'Hero benefits (JSON)',
                     'help' => 'Up to four, e.g. [{"t":"FREE DELIVERY","s":"in Juba","s2":"Min. 5 Cartons"},{"t":"WHOLESALE PRICES","s":"Best deals for your business"}]'],
                    ['key' => 'home_delivery_banner', 'type' => 'textarea', 'label' => 'Free-delivery banner (JSON, blank = hidden)',
                     'help' => 'One entry, e.g. [{"t":"FREE DELIVERY IN JUBA","s":"On orders of 5 cartons or more"}]'],
                    ['key' => 'bulk_tiers_title', 'type' => 'text', 'label' => 'Volume pricing heading', 'default' => 'Buy More, Save More'],
                    ['key' => 'bulk_tiers', 'type' => 'textarea', 'label' => 'Volume tiers (JSON, blank = hidden)',
                     'help' => 'Only claims you honour, e.g. [{"t":"5 – 10 cartons","s":"Standard price"},{"t":"20+ cartons","s":"Best price on WhatsApp"}]. Put a % here only when the discount really applies at checkout.'],
                    ['key' => 'home_stats', 'type' => 'textarea', 'label' => 'Numbers strip (JSON, blank = hidden)',
                     'help' => 'Only numbers you can stand behind, e.g. [{"t":"450+","s":"Products"},{"t":"24h","s":"Delivery in Juba"}]'],
                    ['key' => 'home_topbar', 'type' => 'textarea', 'label' => 'Top bar chips (JSON)', 'help' => 'Up to four short claims, e.g. [{"t":"Wholesale prices"},{"t":"Bulk orders"},{"t":"Reliable delivery"}]'],
                    ['key' => 'home_delivering', 'type' => 'text', 'label' => 'Delivering line (top bar)', 'help' => 'e.g. "Delivering in Uganda & South Sudan". Blank = hidden.'],
                    ['key' => 'home_popular_searches', 'type' => 'text', 'label' => 'Popular searches (under the search bar)', 'help' => 'Comma-separated, e.g. Rice, Cooking Oil, Aluminium Foil, Hotel Supplies. Blank = your top categories are shown instead.'],
                    ['key' => 'home_about', 'type' => 'textarea', 'label' => 'About Us page', 'help' => 'A few short paragraphs. Blank = no About page in the menu. Contact page details come from Business profile (phone, email, address).'],
                    ['key' => 'theme_accent', 'type' => 'text', 'label' => 'Brand colour', 'help' => 'e.g. #D92D20 — colours every button and accent across the whole shop.'],
                    ['key' => 'theme_accent_dark', 'type' => 'text', 'label' => 'Brand colour, darker', 'help' => 'Hover/active shade, e.g. #B42318.'],
                ]],
                ['label' => 'Bike models on the website', 'only' => ['auto_parts'], 'help' => 'Turn a model ON to show it on your customer website, OFF to hide it. Changes take effect as soon as you save. Tip: keep at least one model on. To set ALL the photos in one go, open /papi/bike-photos and upload the bike photo pack zip.', 'fields' => [
                    ['key' => 'show_model_boxer100',   'type' => 'bool', 'label' => 'Boxer 100 KS',  'default' => true],
                    ['key' => 'show_model_boxer125hd', 'type' => 'bool', 'label' => 'Boxer 125 HD',  'default' => true],
                    ['key' => 'show_model_boxer150',   'type' => 'bool', 'label' => 'Boxer BM150',   'default' => true],
                    ['key' => 'show_model_boxer125x',  'type' => 'bool', 'label' => 'Boxer BM125X',  'default' => true],
                    ['key' => 'show_model_ct125hd',    'type' => 'bool', 'label' => 'CT 125 HD',     'default' => true],
                    ['key' => 'show_model_boxer150x',  'type' => 'bool', 'label' => 'Boxer BM150 X', 'default' => true],
                    ['key' => 'show_model_tvshlx125',  'type' => 'bool', 'label' => 'TVS HLX 125',   'default' => true],   // v4.7.5
                    // v4.7.8: upload a photo to replace the built-in TVS HLX 125 shot (blank = built-in photo).
                    ['key' => 'model_image_tvshlx125', 'type' => 'image', 'label' => 'TVS HLX 125 photo', 'help' => 'Shown on the homepage card, bike picker and model page. Leave empty to use the built-in photo.'],
                    // v4.15: manage EVERY bike photo from here — your upload wins over the built-in
                    // photo on the website AND in the PDF catalogue; blank = built-in. If an uploaded
                    // photo link ever dies, the site falls back to the built-in automatically.
                    ['key' => 'model_image_hero',       'type' => 'image', 'label' => 'Homepage hero photo', 'help' => 'The big motorcycle photo at the top of your website and on the catalogue cover. Leave empty to use the built-in shot.'],
                    ['key' => 'model_image_boxer100',   'type' => 'image', 'label' => 'Boxer 100 KS photo'],
                    ['key' => 'model_image_boxer125hd', 'type' => 'image', 'label' => 'Boxer 125 HD photo'],
                    ['key' => 'model_image_boxer150',   'type' => 'image', 'label' => 'Boxer BM150 photo'],
                    ['key' => 'model_image_boxer125x',  'type' => 'image', 'label' => 'Boxer BM125X photo'],
                    ['key' => 'model_image_ct125hd',    'type' => 'image', 'label' => 'CT 125 HD photo'],
                    ['key' => 'model_image_boxer150x',  'type' => 'image', 'label' => 'Boxer BM150 X photo'],
                ]],
                // v4.9 — Dealer programme 4a. The bot quotes these prices automatically for customers
                // marked as dealers on their profile; the storefront joins in drop 4b.
                // v4.29 — product research. Fills BLANK fields only; anything a person typed is never touched.
                ['label' => 'Research my products', 'help' => 'Let the AI fill in product details you have not entered — brand, size, country of origin, and a written description. It only ever fills fields that are EMPTY; anything you have typed yourself is never changed. Where a product has a barcode the details come from the barcode database, so they are fact rather than guesswork; anything it is unsure of waits for you on Products → Suggestions.', 'fields' => [
                    ['key' => 'research_enabled', 'type' => 'bool', 'label' => 'Research products for me', 'default' => false, 'help' => 'Turns on the "Look up" button on the product form, and allows the catalogue-wide pass (php artisan products:research) to run for this shop. OFF = nothing is researched and nothing is sent to the AI.'],
                    ['key' => 'research_descriptions', 'type' => 'bool', 'label' => 'Write product descriptions', 'default' => true, 'help' => 'Writes ORIGINAL copy from the confirmed facts — the size, the case, your city — never the manufacturer\'s marketing text. That matters: identical text copied by every shop is not something Google shows, so a copied description is a page nobody finds.'],
                    ['key' => 'research_daily_cap', 'type' => 'number', 'label' => 'Daily research limit', 'default' => 400, 'help' => 'Maximum lookups per day for this shop, so a large catalogue pass cannot run up an unexpected AI bill. Reached = research pauses until tomorrow; nothing else is affected.'],
                ]],
                // v4.19 — TenderBot: the AI procurement department. Upload page + daily forum watcher.
                // Trading/supply businesses bid for tenders; a restaurant or a snack stall does not.
                ['label' => 'Tenders (TenderBot)', 'only' => ['grocery', 'manufacturer', 'auto_parts', 'solar'], 'help' => 'Your AI bid manager: it reads tender documents, scores the opportunity, checks compliance and WhatsApps you the executive summary. Switch it on below and a "Tenders" entry appears in your left menu (under Sales) — that is the upload & analysis screen.', 'fields' => [
                    ['key' => 'tender_enabled', 'type' => 'bool', 'label' => 'Watch for tenders', 'default' => false, 'help' => 'When ON, TenderBot checks the forum below twice a day by itself and analyses anything new — and "Tenders" appears in your left menu.'],
                    ['key' => 'tender_watch_url', 'type' => 'text', 'label' => 'Tender forum to watch', 'default' => 'https://comms.southsudanngoforum.org/c/tenders/8', 'help' => 'A Discourse category URL. New posts here are analysed automatically.'],
                    ['key' => 'tender_profile', 'type' => 'textarea', 'label' => 'Business profile for classification', 'help' => 'What your company sells/does, listed by division. TenderBot classifies every tender into YOUR division names (or "Both" / "Ignore") — so write them the way you want to read them, e.g. "Division A (Food & FMCG Supply): rice, sugar, cooking oil…". Leave empty for the built-in ISP + General Trading profile.'],
                    ['key' => 'tender_company_docs', 'type' => 'textarea', 'label' => 'Company documents on file', 'help' => 'One per line, e.g. "Tax Clearance — valid to Dec 2026". TenderBot marks each tender\'s requirements Available / Need Renewal / Missing against this list.'],
                    ['key' => 'tender_notify', 'type' => 'bool', 'label' => 'WhatsApp me each analysis', 'default' => true],
                    // v4.20 — the Procurement Task Engine: analysis action-items become real tasks.
                    ['key' => 'tender_tasks', 'type' => 'bool', 'label' => 'Create tasks from each analysis', 'default' => true, 'help' => 'Each tender\'s action plan becomes real tasks in your task inbox (with WhatsApp nudges, reminders and escalation).'],
                    // v4.22 — most forum notices are photographed/scanned pages with no text at all.
                    ['key' => 'tender_ocr', 'type' => 'bool', 'label' => 'Read scanned documents (OCR)', 'default' => true, 'help' => 'Many tender PDFs are scans, with no text a computer can read. When ON, TenderBot photographs the pages and has the AI read them (first 15 pages per document). Costs a little AI credit, only for documents that cannot be read normally. OFF = those tenders come back with "not read".'],
                    ['key' => 'tender_margin_pct', 'type' => 'number', 'label' => 'Bid margin', 'unit' => '%', 'default' => 20, 'help' => 'Added on top of a supplier\'s cost to work out your bid price on the Items-to-price board. You can override it on any single line.'],
                    // v4.23 — the buyers who actually walk the market.
                    ['key' => 'tender_pricing_team', 'type' => 'text', 'label' => 'Who sources prices', 'help' => 'Team members who go and find prices, separated by commas — e.g. "Savan, Jayesh". Each tender\'s items are split between them by category (one takes stationery, another sports) and each gets ONE task with their list on WhatsApp. They must exist under Settings → Team. Leave empty to switch this off.'],
                    ['key' => 'tender_sourcing_market', 'type' => 'text', 'label' => 'Where they check prices', 'default' => 'Kampala market', 'help' => 'Named in the task, e.g. "Kampala market" or "Juba, Konyo Konyo".'],
                ]],
                ['label' => 'Dealer programme', 'only' => ['grocery', 'manufacturer', 'auto_parts', 'solar'], 'help' => 'Discounts for customers marked as Dealer/Wholesale on their profile. The margin floor is a hard guard: no dealer price ever goes below cost + floor %.', 'fields' => [
                    ['key' => 'dealer_pct',            'type' => 'number', 'label' => 'Dealer tier discount', 'unit' => '%', 'default' => 10, 'help' => 'Off the retail price.'],
                    ['key' => 'wholesale_pct',         'type' => 'number', 'label' => 'Wholesale tier discount', 'unit' => '%', 'default' => 15],
                    ['key' => 'dealer_min_margin_pct', 'type' => 'number', 'label' => 'Margin floor over cost', 'unit' => '%', 'default' => 5, 'help' => 'Prices clamp here instead of selling at a loss.'],
                ]],
                // Per-piece / loose selling — one carton item can be bought either by the carton
                // (wholesale) or by the single piece (retail = carton unit price + markup). The pricer
                // (storefront repriceCart + the WhatsApp bot's OrderCalculator) derives the piece price;
                // these two settings only switch it on and set the markup.
                ['label' => 'Per-piece (loose) selling', 'only' => ['grocery', 'manufacturer', 'auto_parts', 'solar'], 'help' => 'Let customers order a carton item by the single piece, priced at the carton unit price plus a retail markup. The carton price stays for bulk buyers. Works on the storefront and the WhatsApp bot.', 'fields' => [
                    ['key' => 'loose_enabled',    'type' => 'bool',   'label' => 'Allow ordering by the piece', 'default' => false],
                    ['key' => 'loose_markup_pct', 'type' => 'number', 'label' => 'Per-piece markup over carton price', 'unit' => '%', 'default' => 20, 'help' => 'e.g. 20 means a single piece costs 20% more than its share of the carton. A carton of 400 at $312.43 works out to about $0.94 per piece at 20%.'],
                ]],
                ['label' => 'Availability', 'only' => ['auto_parts'], 'help' => 'For a parts business — sell only what you actually have.', 'fields' => [
                    ['key' => 'sf_hide_oos', 'type' => 'bool', 'label' => 'Show only parts in stock (hide out-of-stock)', 'default' => false, 'help' => 'When ON, any part with 0 stock is hidden from listings, search and browse. Parts with no stock number set (untracked) still show as “to order”.'],
                    // v4.10.5 — parts-first suppliers (boraboda) have no exploded-diagram plates; hide the whole surface by default.
                    ['key' => 'sf_diagrams', 'type' => 'bool', 'label' => 'Show exploded diagrams', 'default' => false, 'help' => 'OFF hides diagrams everywhere (menu, search tab, product & model links). Turn ON only when you have real diagram plates for your catalogue.'],
                ]],
                // v4.6.1 — which host goes into links the system SENDS (quotation review, order tracking).
                ['label' => 'Custom domain', 'help' => 'Serve your shop on your own web address, and use it in the links customers see (quotation review, order tracking).', 'fields' => [
                    ['key' => 'custom_domain', 'type' => 'text', 'label' => 'Your domain', 'placeholder' => 'gslafrica.net',
                     'help' => 'Enter your domain with no https:// and no path (e.g. gslafrica.net). Point its DNS A-record at CloudBSS first; HTTPS is issued automatically. Once saved, your shop opens at that address. Leave blank to use the platform address.'],
                    ['key' => 'custom_domain_live', 'type' => 'bool', 'label' => 'My own domain points to CloudBSS', 'default' => false,
                     'help' => 'Turn ON only after your domain (e.g. familyshoppers.net) is actually connected to CloudBSS hosting — sent links will then use it. While OFF, links use the platform address, which always works. If your domain hosts a separate website, keep this OFF or every sent link breaks.'],
                ]],
                ['label' => 'Trust & delivery promises', 'only' => ['auto_parts'], 'help' => 'Shown on your product pages and homepage to reassure customers before they buy. Leave blank to use sensible defaults.', 'fields' => [
                    ['key' => 'sf_genuine',      'type' => 'text', 'label' => 'Genuine-parts promise', 'help' => 'e.g. 100% genuine Bajaj — verify the hologram, or your money back.'],
                    ['key' => 'sf_delivery_eta', 'type' => 'text', 'label' => 'Delivery time', 'help' => 'e.g. Same-day in town · 24–48h upcountry'],
                    ['key' => 'sf_pay_methods',  'type' => 'text', 'label' => 'Payment methods', 'help' => 'e.g. Mobile Money · Pay on Delivery'],
                    ['key' => 'sf_warranty',     'type' => 'text', 'label' => 'Warranty', 'help' => 'e.g. 1-year genuine-parts warranty'],
                    ['key' => 'sf_returns',      'type' => 'text', 'label' => 'Returns policy', 'help' => 'e.g. 7-day returns on unfitted parts'],
                ]],
            ]],
            ['key' => 'delivery', 'label' => 'Delivery', 'icon' => 'truck', 'groups' => [
                // v4.30: `delivery_note` used to appear HERE as well as in Business profile → Delivery.
                // Two inputs, one key. On the mobile settings screen every section renders at once, so
                // both landed in the same payload and whichever came second silently won — you could
                // edit the policy, save, and watch your edit vanish. One field now, in Business profile.
                ['label' => 'Delivery', 'help' => 'The delivery wording customers read is under Business profile → Delivery.', 'fields' => [
                    ['key' => 'delivery_areas', 'type' => 'textarea', 'label' => 'Delivery areas'],
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
