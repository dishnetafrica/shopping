<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use App\Models\User;
use App\Services\Money\Fx;
use App\Services\Money\ReportingCurrency;
use App\Services\Settings\SettingsWriteService;

/**
 * Settings — the one screen where the shop configures itself, mirroring the prototype's tabs:
 * Business profile, Currency & reporting, Exchange rates, and Invoice & tax. Each section shows the
 * current values and has an edit form. Everything is written through SettingsWriteService (tenant name
 * column + settings JSON), which the PDF renderers, Fx service and bot already read — no engine changes.
 */
final class SettingsCapability implements ProcessCapability
{
    public function key(): string { return 'settings'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenant;
        $s = fn (string $k, string $d = '') => trim((string) ($t->setting($k, $d) ?? $d));
        $name = (string) ($t->name ?: 'Your business');
        $base = $ctx->baseCurrency();
        $mode = $ctx->reportMode();
        $rc = ReportingCurrency::forContext($ctx);
        $fx = Fx::forTenant($t);
        $ugx = $fx->ratePerUsd('UGX');
        $ssp = $fx->ratePerUsd('SSP');
        $ep = '/api/v1/workspace/settings/_';
        $modeLabel = $mode === 'original' ? 'Original transaction currency' : 'Consolidated to base';
        $email = $s('public_email', $s('email'));
        $loc = trim(implode(', ', array_filter([$s('address'), $s('city'), $s('country')])));

        // --- edit forms (all post to save_settings → SettingsWriteService::apply) ---
        $editProfile = [
            'key' => 'edit_profile', 'label' => 'Edit business profile', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['business_name' => 'text', 'trading_as' => 'text', 'owner_name' => 'text', 'public_phone' => 'text', 'public_email' => 'text', 'address' => 'text', 'city' => 'text', 'country' => 'text', 'tin' => 'text', 'tagline' => 'text', 'logo' => 'text'],
            'prefill' => ['business_name' => $name, 'trading_as' => $s('trading_as'), 'owner_name' => $s('owner_name'), 'public_phone' => $s('public_phone'), 'public_email' => $email, 'address' => $s('address'), 'city' => $s('city'), 'country' => $s('country'), 'tin' => $s('tin'), 'tagline' => $s('tagline'), 'logo' => $s('logo')],
        ];
        $editCurrency = [
            'key' => 'edit_currency', 'label' => 'Currency & reporting', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['base_currency' => 'enum:' . implode(',', SettingsWriteService::BASE_CHOICES), 'report_currency_mode' => 'enum:base,original'],
            'prefill' => ['base_currency' => $base, 'report_currency_mode' => $mode],
        ];
        $editRates = [
            'key' => 'edit_rates', 'label' => 'Exchange rates', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['rate_ugx_per_usd' => 'number', 'rate_ssp_per_usd' => 'number'],
            'prefill' => ['rate_ugx_per_usd' => (int) round($ugx), 'rate_ssp_per_usd' => (int) round($ssp)],
        ];
        $editInvoicing = [
            'key' => 'edit_invoicing', 'label' => 'Invoice & tax', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['invoice_title' => 'text', 'invoice_terms_days' => 'number', 'vat_rate' => 'number', 'bank_details' => 'text', 'invoice_footer' => 'text', 'cashbook_digest_to' => 'text'],
            'labels' => ['cashbook_digest_to' => 'Daily cashbook email (blank = off)'],
            'prefill' => ['invoice_title' => $s('invoice_title', 'INVOICE'), 'invoice_terms_days' => $s('invoice_terms_days', '0'), 'vat_rate' => $s('vat_rate', '0'), 'bank_details' => $s('bank_details'), 'invoice_footer' => $s('invoice_footer'), 'cashbook_digest_to' => $s('cashbook_digest_to')],
        ];
        $editBot = [
            'key' => 'edit_bot', 'label' => 'WhatsApp bot', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['bot_greeting' => 'text', 'bot_fallback_text' => 'text', 'ai_persona' => 'text', 'business_hours' => 'text', 'delivery_areas' => 'text', 'bot_website_link' => 'text', 'faq' => 'text'],
            'prefill' => ['bot_greeting' => $s('bot_greeting'), 'bot_fallback_text' => $s('bot_fallback_text'), 'ai_persona' => $s('ai_persona'), 'business_hours' => $s('business_hours'), 'delivery_areas' => $s('delivery_areas'), 'bot_website_link' => $s('bot_website_link'), 'faq' => $s('faq')],
        ];

        // --- document PDFs (page size / orientation for invoices & receipts) ---
        $editDocuments = [
            'key' => 'edit_documents', 'label' => 'Document PDFs', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['invoice_page_size' => 'enum:A4,A5,Letter', 'invoice_orientation' => 'enum:portrait,landscape', 'receipt_page_size' => 'enum:A4,A5', 'receipt_orientation' => 'enum:portrait,landscape'],
            'labels' => ['invoice_page_size' => 'Invoice page size', 'invoice_orientation' => 'Invoice orientation', 'receipt_page_size' => 'Receipt page size', 'receipt_orientation' => 'Receipt orientation'],
            'prefill' => [
                'invoice_page_size' => $s('invoice_page_size', 'A4') ?: 'A4',
                'invoice_orientation' => $s('invoice_orientation', 'portrait') ?: 'portrait',
                'receipt_page_size' => $s('receipt_page_size', 'A5') ?: 'A5',
                'receipt_orientation' => $s('receipt_orientation', 'landscape') ?: 'landscape',
            ],
        ];
        $documentsCard = ['kind' => 'dl', 'title' => 'Document PDFs', 'rows' => [
            ['Invoice PDF', ($s('invoice_page_size', 'A4') ?: 'A4') . ' · ' . ($s('invoice_orientation', 'portrait') ?: 'portrait')],
            ['Receipt PDF', ($s('receipt_page_size', 'A5') ?: 'A5') . ' · ' . ($s('receipt_orientation', 'landscape') ?: 'landscape')],
            ['Note', 'Page size & layout for the PDFs your customers receive. Defaults match the built-in layout — change only if your printer or filing needs a different size.'],
        ]];

        // --- stock tracking (the real-inventory switch) ---
        $ledgerOn = (bool) $t->setting('stock_ledger', false);
        $liveStockOn = (bool) $t->setting('stock_v2', false);
        $editStock = [
            'key' => 'edit_stock', 'label' => 'Stock tracking', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['stock_ledger' => 'enum:off,on', 'stock_v2' => 'enum:off,on'],
            'labels' => ['stock_ledger' => 'Track real stock (record receipts & sales in the ledger)', 'stock_v2' => 'Show real stock on site & bot (only after counts are trusted)'],
            'prefill' => ['stock_ledger' => $ledgerOn ? 'on' : 'off', 'stock_v2' => $liveStockOn ? 'on' : 'off'],
        ];
        $stockCard = ['kind' => 'dl', 'title' => 'Stock tracking', 'rows' => [
            ['Real stock ledger', $ledgerOn ? 'On — receipts & Juba sales recorded' : 'Off — no real inventory yet'],
            ['Live stock on site/bot', $liveStockOn ? 'On — reads true on-hand' : 'Off — uses catalog availability flag'],
            ['Guidance', $ledgerOn ? ($liveStockOn ? 'Fully live.' : 'Building up — switch on “live stock” once your on-hand counts are trusted.') : 'Turn the ledger on to start accumulating real stock from goods receipts.'],
        ]];

        // --- bot automation toggles (item suggestions, product photos, website chat) ---
        $comboOn = (bool) $t->setting('combo_recommendations', true);
        $photosOn = (bool) $t->setting('send_product_images', true);
        $webChatOn = (bool) $t->setting('web_chat', false);
        $webChatAuto = ((string) $t->setting('web_chat_mode', 'auto')) !== 'off';
        $webChatContact = (bool) $t->setting('web_chat_contact', true);
        $editAutomation = [
            'key' => 'edit_automation', 'label' => 'Bot suggestions, photos & website chat', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => ['combo_recommendations' => 'enum:off,on', 'send_product_images' => 'enum:off,on', 'web_chat' => 'enum:off,on', 'web_chat_mode' => 'enum:auto,off', 'web_chat_contact' => 'enum:off,on'],
            'labels' => ['combo_recommendations' => 'Item suggestions ("Goes well with" / "Often bought together")', 'send_product_images' => 'Send product photos with replies', 'web_chat' => 'Website chat — show a chat bubble on your storefront', 'web_chat_mode' => 'Website chat: bot answers (auto) or collect message only (off)', 'web_chat_contact' => 'Website chat: ask for name & number before chatting'],
            'prefill' => ['combo_recommendations' => $comboOn ? 'on' : 'off', 'send_product_images' => $photosOn ? 'on' : 'off', 'web_chat' => $webChatOn ? 'on' : 'off', 'web_chat_mode' => $webChatAuto ? 'auto' : 'off', 'web_chat_contact' => $webChatContact ? 'on' : 'off'],
        ];
        $automationCard = ['kind' => 'dl', 'title' => 'Bot automation', 'rows' => [
            ['Item suggestions', $comboOn ? 'On — "Goes well with" & combos' : 'Off — no cross-sell suggestions'],
            ['Product photos', $photosOn ? 'On — sends product images' : 'Off — text only'],
            ['Website chat', $webChatOn ? ('On — bubble on your storefront (' . ($webChatAuto ? 'bot answers' : 'collect only') . ')') : 'Off — no chat bubble on the website'],
            ['Note', 'Item suggestions learn from real past orders and can lift basket size; turn off if you don\'t want the bot cross-selling. Website chat puts the same bot on familyshoppers.net.'],
        ]];

        // --- display cards ---
        $profileCard = ['kind' => 'dl', 'title' => 'Business profile', 'rows' => [
            ['Business name', $name],
            ['Trading as', $s('trading_as') ?: '—'],
            ['Owner', $s('owner_name') ?: '—'],
            ['Phone', $s('public_phone') ?: '—'],
            ['Email', $email ?: '—'],
            ['Address', $loc ?: '—'],
            ['TIN', $s('tin') ?: '—'],
        ]];
        $currencyCard = ['kind' => 'dl', 'title' => 'Currency & reporting', 'rows' => [
            ['Base currency', $base],
            ['Report mode', $modeLabel],
            ['Figures shown in', $rc->consolidationCurrency()],
            ['KPI tiles', 'Always consolidated to ' . $rc->consolidationCurrency()],
        ]];
        $ratesCard = ['kind' => 'dl', 'title' => 'Exchange rates (per 1 USD)', 'rows' => [
            ['UGX', number_format($ugx)],
            ['SSP', number_format($ssp)],
            ['Basis', 'Historical: each transaction keeps its own stored rate'],
        ]];
        $invoiceCard = ['kind' => 'dl', 'title' => 'Invoice & tax', 'rows' => [
            ['Invoice title', $s('invoice_title', 'INVOICE')],
            ['Payment terms', ($s('invoice_terms_days', '0') ?: '0') . ' days'],
            ['VAT rate', ($s('vat_rate', '0') ?: '0') . '%'],
            ['Bank details', $s('bank_details') ?: '—'],
            ['Daily cashbook email', $s('cashbook_digest_to') ?: '— off —'],
        ]];
        // --- WhatsApp & bot ---
        $waLinked = trim((string) $t->whatsapp_instance) !== '';
        $waStatus = $waLinked ? 'Connected' : 'Not connected';
        // Connect/manage opens the guided QR-scan screen embedded INSIDE v3 (the shell renders /panel/setup
        // in an iframe under the 'wa-connect' key — the user never leaves v3).
        $connectWa = ['key' => 'connect_whatsapp', 'label' => $waLinked ? 'Manage WhatsApp connection' : 'Connect WhatsApp', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/wa-connect', 'actor' => 'human', 'tone' => $waLinked ? 'default' : 'primary'];
        $connCard = ['kind' => 'dl', 'title' => 'WhatsApp connection', 'rows' => [
            ['Number', (string) ($t->whatsapp_number ?: '—')],
            ['Driver', (string) ($t->whatsapp_driver ?: '—')],
            ['Instance', (string) ($t->whatsapp_instance ?: '—')],
            ['Status', $waStatus],
            ['Setup', $waLinked ? 'Use “Manage WhatsApp connection” to re-scan or switch number.' : 'Use “Connect WhatsApp” above to scan the QR code and go live.'],
        ]];
        $trunc = fn (string $v, int $n = 60) => $v === '' ? '—' : (mb_strlen($v) > $n ? mb_substr($v, 0, $n) . '…' : $v);
        $botCard = ['kind' => 'dl', 'title' => 'Bot messages', 'rows' => [
            ['Greeting', $trunc($s('bot_greeting'))],
            ['Fallback reply', $trunc($s('bot_fallback_text'))],
            ['AI persona', $trunc($s('ai_persona'))],
            ['Business hours', $s('business_hours') ?: '—'],
            ['Delivery areas', $trunc($s('delivery_areas'))],
            ['Website link', $s('bot_website_link') ?: '—'],
        ]];

        // --- team: list live members, add/remove via the panel's proven owner-gated staff endpoints ---
        $meId = (int) $ctx->user->id;
        $members = User::where('tenant_id', $t->id)->orderBy('name')->limit(50)->get();
        $teamRows = $members->map(function ($u) use ($meId) {
            $isMe = (int) $u->id === $meId;
            $btns = $isMe ? [] : [['icon' => 'trash', 'title' => 'Remove member', 'tone' => 'danger', 'post' => '/papi/staff/delete', 'body' => ['id' => (int) $u->id], 'confirm' => 'Remove ' . ($u->name ?: $u->email) . ' from the team? Their login is deleted.']];

            return ['cells' => [
                (string) ($u->name ?: $u->email ?: 'Member') . ($isMe ? ' (you)' : ''),
                ucfirst(str_replace('_', ' ', (string) ($u->role ?: 'user'))),
                (string) ($u->email ?: '—'),
                ['buttons' => $btns],
            ], 'link' => null];
        })->all();
        $teamCard = ['kind' => 'table', 'title' => 'Team members', 'sub' => $members->count() . ' total',
            'columns' => [['label' => 'Member'], ['label' => 'Role'], ['label' => 'Email'], ['label' => '', 'align' => 'right']],
            'rows' => $teamRows, 'empty' => 'No team members yet'];

        $addMember = [
            'key' => 'add_member', 'label' => 'Add member', 'method' => 'POST', 'endpoint' => '/papi/staff/add', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['name' => 'text', 'email' => 'text', 'phone' => 'text', 'role' => 'enum:sales,manager,staff,purchaser,product_staff,field', 'password' => 'text'],
            'prefill' => ['role' => 'sales'],
        ];
        $teamNote = ['kind' => 'dl', 'title' => 'About team logins', 'rows' => [
            ['Sign in', 'By email + password, or WhatsApp number (OTP)'],
            ['Requirements', 'Name, a valid email, and either a WhatsApp number or a 6+ character password'],
            ['Note', 'Only owners can add or remove members'],
        ]];

        // --- v4.69: the wholesale storefront home (ADR-017), owner-editable ------------------------
        // Until now these lived behind SSH + tinker, which meant only a developer could change a
        // homepage photo. The form reuses the existing upload widget ('image' fields post to the
        // shared upload endpoint and store a URL) and the existing save path; the JSON fields are
        // forgiving by construction — WholesaleHome falls back whole on malformed input, so the
        // worst a typo here can do is leave a section on its defaults.
        $sfMode = strtolower($s('storefront_home')) === 'wholesale' ? 'wholesale' : 'classic';
        $editStorefront = [
            'key' => 'edit_storefront', 'label' => 'Storefront homepage', 'method' => 'POST', 'endpoint' => $ep . '/save_settings', 'actor' => 'human', 'tone' => 'default',
            'schema' => [
                'storefront_home'        => 'enum:classic,wholesale',
                'home_hero_image'        => 'image',
                'home_hero_image_mobile' => 'image',
                'home_hero_title'        => 'text',
                'home_hero_title2'       => 'text',
                'home_hero_sub'          => 'text',
                'home_benefits'          => 'textarea',
                'home_delivery_banner'   => 'textarea',
                'bulk_tiers_title'       => 'text',
                'bulk_tiers'             => 'textarea',
                'home_stats'             => 'textarea',
                'theme_accent'           => 'text',
                'theme_accent_dark'      => 'text',
            ],
            'labels' => [
                'storefront_home'        => 'Homepage style (wholesale = the new home)',
                'home_hero_image'        => 'Hero image — desktop (1600×600 works best — any photo is fine, the system converts it)',
                'home_hero_image_mobile' => 'Hero image — mobile (750×750 works best — any photo is fine, the system converts it)',
                'home_hero_title'        => 'Hero headline (blank = "Your Trusted Wholesale Partner")',
                'home_hero_title2'       => 'Hero second line (blank = "in ' . ($s('city') ?: 'your city') . '")',
                'home_hero_sub'          => 'Hero supporting line',
                'home_benefits'          => 'Hero benefits — JSON, e.g. [{"t":"FREE DELIVERY","s":"in Juba","s2":"Min. 5 Cartons"}]',
                'home_delivery_banner'   => 'Delivery banner — JSON [{"t":"FREE DELIVERY IN JUBA","s":"On orders of 5+ cartons"}] (blank = hidden)',
                'bulk_tiers_title'       => 'Volume pricing heading',
                'bulk_tiers'             => 'Volume tiers — JSON [{"t":"5 – 10 cartons","s":"Standard price"}] (blank = hidden)',
                'home_stats'             => 'Numbers strip — JSON [{"t":"450+","s":"Products"}] (blank = hidden; only numbers you can stand behind)',
                'theme_accent'           => 'Brand colour (e.g. #D92D20)',
                'theme_accent_dark'      => 'Brand colour, darker (hover)',
            ],
            'prefill' => [
                'storefront_home'        => $sfMode,
                'home_hero_image'        => $s('home_hero_image'),
                'home_hero_image_mobile' => $s('home_hero_image_mobile'),
                'home_hero_title'        => $s('home_hero_title'),
                'home_hero_title2'       => $s('home_hero_title2'),
                'home_hero_sub'          => $s('home_hero_sub'),
                'home_benefits'          => $s('home_benefits'),
                'home_delivery_banner'   => $s('home_delivery_banner'),
                'bulk_tiers_title'       => $s('bulk_tiers_title'),
                'bulk_tiers'             => $s('bulk_tiers'),
                'home_stats'             => $s('home_stats'),
                'theme_accent'           => $s('theme_accent'),
                'theme_accent_dark'      => $s('theme_accent_dark'),
            ],
        ];
        $sfCard = ['kind' => 'dl', 'title' => 'Storefront homepage', 'rows' => [
            ['Style', $sfMode === 'wholesale' ? 'Wholesale home (hero, business cards, best sellers)' : 'Classic home'],
            ['Hero image — desktop', $s('home_hero_image') ? '✓ uploaded' : '— product shelf fallback'],
            ['Hero image — mobile', $s('home_hero_image_mobile') ? '✓ uploaded' : '— compact text hero'],
            ['Brand colour', $s('theme_accent') ?: '— theme default'],
            ['Delivery banner', $s('home_delivery_banner') ? 'On' : 'Off'],
            ['Volume tiers', $s('bulk_tiers') ? 'On' : 'Off'],
            ['Numbers strip', $s('home_stats') ? 'On' : 'Off'],
            ['Note', 'Image sizes: desktop 1600×600, mobile 750×750. Products only, no text in the image — the headline and buttons are typed above and stay sharp on every screen.'],
        ]];

        $help = ['kind' => 'links', 'title' => 'How reporting currency works', 'items' => [
            ['icon' => 'dollar', 'color' => '#16a34a', 'title' => 'Consolidated', 'sub' => 'Every figure is converted to your base currency so totals add up.', 'right' => ''],
            ['icon' => 'tag', 'color' => '#2563eb', 'title' => 'Original', 'sub' => 'Each figure stays in its own currency; KPI tiles still consolidate.', 'right' => ''],
            ['icon' => 'book', 'color' => '#d97706', 'title' => 'Historical rates', 'sub' => 'Past documents keep the rate stored on them — never re-priced at today\'s rate.', 'right' => ''],
        ]];

        $brief = new Brief(
            level: 'low',
            situation: 'Configure ' . $name . ' — profile, currency, exchange rates and invoicing.',
            why: 'These settings flow into every document and report: quotations, invoices, receipts, the catalogue and price list all use them.',
            risk: ['tone' => 'info', 'label' => $base . ' · ' . ($mode === 'original' ? 'Original' : 'Consolidated')],
        );

        return (new WorkspacePayload)
            ->object(['type' => 'process:settings', 'title' => 'Settings', 'subtitle' => 'Manage your business, currency and invoicing'])
            ->headline($base, 'base currency', 'ok')
            ->brief($brief)
            ->financials([
                ['key' => 'base', 'label' => 'Base currency', 'value' => $base, 'tone' => 'ok'],
                ['key' => 'mode', 'label' => 'Report mode', 'value' => $mode === 'original' ? 'Original' : 'Consolidated'],
                ['key' => 'terms', 'label' => 'Payment terms', 'value' => ($s('invoice_terms_days', '0') ?: '0') . ' days'],
            ])
            ->quickActions([$editProfile, $editCurrency, $editRates, $editInvoicing, $editDocuments, $connectWa, $editBot, $editAutomation, $addMember])
            ->overviewCards([$profileCard, $currencyCard, $invoiceCard])
            ->tabSections([
                'business' => [$profileCard],
                'currency' => [$currencyCard, $ratesCard, $help],
                'invoicing' => [$invoiceCard],
                'documents' => [$documentsCard],
                'inventory' => [$stockCard],
                'storefront' => [$sfCard],
                'whatsapp' => [$connCard, $botCard, $automationCard],
                'team' => [$teamCard, $teamNote],
            ])
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'business', 'label' => 'Business profile'],
                ['key' => 'currency', 'label' => 'Currency & rates'],
                ['key' => 'invoicing', 'label' => 'Invoice & tax'],
                ['key' => 'documents', 'label' => 'Documents'],
                ['key' => 'inventory', 'label' => 'Stock tracking'],
                ['key' => 'storefront', 'label' => 'Storefront'],
                ['key' => 'whatsapp', 'label' => 'WhatsApp & bot'],
                ['key' => 'team', 'label' => 'Team', 'count' => $members->count()],
            ])
            ->actions([$editProfile])
            ->more([$editStorefront, $editStock, $editAutomation, $editCurrency, $editRates, $editInvoicing, $editDocuments, $connectWa, $editBot, $addMember])
            ->meta(['capability' => 'settings', 'ttl' => 20, 'layout' => 'record', 'listKey' => 'settings', 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['base' => $ctx->baseCurrency(), 'mode' => $ctx->reportMode()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        $svc = app(SettingsWriteService::class);

        return match ($action) {
            'save_settings' => $svc->apply($ctx->tenant, $input),
            'mode_base' => $svc->apply($ctx->tenant, ['report_currency_mode' => 'base']),
            'mode_original' => $svc->apply($ctx->tenant, ['report_currency_mode' => 'original']),
            default => ['ok' => false, 'error' => 'unknown_action'],
        };
    }
}
