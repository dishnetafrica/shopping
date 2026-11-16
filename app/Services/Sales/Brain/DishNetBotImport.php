<?php

namespace App\Services\Sales\Brain;

/**
 * DishNet's current sales information as written in the prompt of its n8n WhatsApp sales bot
 * ("DishNet Sales Bot v4 — 0923400000"), supplied by DishNet on 16 Nov 2026.
 *
 * Imported for review ONLY:
 *   - priced items become catalogue products that are SWITCHED OFF (active = false): no quotation,
 *     bot reply or storefront uses them until a manager switches each one on;
 *   - everything else becomes Sales Brain DRAFTS with the questions management must settle. Where
 *     this text disagrees with the UISP quote template, the disagreement is an open question, so the
 *     draft cannot be approved until management decides.
 * Prices live in the catalogue only; the knowledge text says which package suits whom, so the same
 * price is never kept in two places. Staff personal numbers in the n8n workflow are NOT imported.
 */
final class DishNetBotImport
{
    public const SOURCE = 'DishNet n8n sales bot v4 prompt (supplied 16 Nov 2026) — draft, not approved';

    private const Q_UPTIME = 'Uptime wording: this text says "we target 99.9% uptime — not a guarantee"; the UISP quote template says "99.5% guarantee". Which figure, and is it a target or a guarantee?';
    private const Q_EQUIPMENT = 'Equipment: this text SELLS the Starlink kit (Mini, Standard) and says the fibre installation fee includes the Wi-Fi router; the UISP quote template says all devices and routers stay DishNet property and must be returned. Sold or leased — per product?';
    private const Q_INSTALL_TIME = 'Installation time: this text says typically 2–3 days in Juba (fibre same-day once stock and coverage are confirmed), within a week outside Juba, and within two weeks for hotspots; the UISP template says 2–5 days after payment. Which applies?';
    private const Q_ROOF = 'Installation cost: this text says Starlink installation is free in Juba (outside Juba the customer pays technician travel and accommodation); the UISP template says roof/pole work is extra. Both, or which?';
    private const Q_PRIORITY = 'Starlink Priority plans have no price in this text. Give the plans and monthly prices (or keep Priority as "Sales quotes it").';
    private const Q_FIRST = 'Juba recommendation: a note at the top of the n8n prompt says "for now keep selling Starlink in Juba instead of fiber, we have stock", while the playbook says "in Juba, fibre first". Which should the bot and salespeople follow now?';
    private const Q_EXCISE = 'Tax: the UISP template says prices include 26% excise duty; this text does not mention tax. Are catalogue prices inclusive of excise/VAT?';
    private const Q_RELOCATION = 'Moving the service: this text routes it to Sales case by case; the UISP template says a $75 relocation fee with 7 days notice. Which?';
    private const Q_RATE = 'Exchange rate: the n8n prompt fixes "1 USD = 8200 SSP". Who keeps the rate current? If nobody, it should not be stated in chat.';
    private const Q_COVERAGE = 'Confirm this list matches https://dishnetafrica.com/coverage.html today. "Covered" means the bot will tell a customer fibre is available there.';
    private const Q_HANDOVER = 'Handover promise: this text tells the customer "our Sales team will reach out from +211 923 400 000 within the hour". Can the team keep "within the hour" (including evenings and Sundays)?';

    /** Catalogue items with a price in the text. Priority (no price) is not created. */
    public static function products(): array
    {
        return [
            ['name' => 'Fiber Basic 20 Mbps', 'price' => 50, 'billing' => 'monthly', 'service_line' => 'fiber', 'category' => 'Fiber plans', 'description' => 'Up to 20 Mbps, symmetric, unlimited (fair use applies). Juba only.'],
            ['name' => 'Fiber Standard 40 Mbps', 'price' => 75, 'billing' => 'monthly', 'service_line' => 'fiber', 'category' => 'Fiber plans', 'description' => 'Up to 40 Mbps, symmetric, unlimited (fair use applies). Juba only.'],
            ['name' => 'Fiber Premium 60 Mbps', 'price' => 100, 'billing' => 'monthly', 'service_line' => 'fiber', 'category' => 'Fiber plans', 'description' => 'Up to 60 Mbps, symmetric, unlimited (fair use applies). Juba only.'],
            ['name' => 'Fiber Installation (includes Wi-Fi router)', 'price' => 150, 'billing' => 'one_off', 'service_line' => 'fiber', 'category' => 'Installation', 'description' => 'One-time fibre installation, same for all three plans, includes the Wi-Fi router.'],
            ['name' => 'Starlink Mini (Gen 4)', 'price' => 400, 'billing' => 'one_off', 'service_line' => 'starlink', 'category' => 'Starlink hardware', 'description' => 'Individuals, portable use, smaller and most small-business setups. Suggested first.'],
            ['name' => 'Starlink Standard', 'price' => 550, 'billing' => 'one_off', 'service_line' => 'starlink', 'category' => 'Starlink hardware', 'description' => 'Households, offices, hotspot setups with 30+ users.'],
            ['name' => 'Starlink Residential Plan', 'price' => 50, 'billing' => 'monthly', 'service_line' => 'starlink', 'category' => 'Starlink plans', 'description' => 'Unlimited. Default plan: personal use, small offices, shops, cafés and hotspots up to about 50 active users.'],
            ['name' => 'Starlink Roam Plan', 'price' => 80, 'billing' => 'monthly', 'service_line' => 'starlink', 'category' => 'Starlink plans', 'description' => 'For roaming / moving between locations. Separate from Residential.'],
            ['name' => 'Starlink Car Adaptor with cable', 'price' => 160, 'billing' => 'one_off', 'service_line' => 'starlink', 'category' => 'Starlink accessories', 'description' => 'For roaming / mobile use.'],
            ['name' => 'Anker SOLIX C200 DC Power Bank Station', 'price' => 330, 'billing' => 'one_off', 'service_line' => 'starlink', 'category' => 'Starlink accessories', 'description' => 'Power bank for the Starlink Mini.'],
            ['name' => 'MikroTik RB951Ui-2HnD Router', 'price' => 150, 'billing' => 'one_off', 'service_line' => null, 'category' => 'Networking', 'description' => 'SOHO router, built-in 2.4GHz Wi-Fi, 5 ports, PoE-out, USB for a 3G/4G modem. Standard client router for homes and small offices.'],
            ['name' => 'Ubiquiti NanoStation M2', 'price' => 150, 'billing' => 'one_off', 'service_line' => null, 'category' => 'Networking', 'description' => 'Outdoor range extension.'],
            ['name' => 'Public IP Address', 'price' => 20, 'billing' => 'monthly', 'service_line' => null, 'category' => 'Add-ons', 'description' => 'For CCTV, remote access, hosting.'],
        ];
    }

    /** Juba fibre coverage, as listed in the text (33 places). Never the bare word "Juba". */
    public static function fiberAreas(): array
    {
        $areas = [
            // Central Juba
            'Juba Town' => ['juba town'], 'Ministries Complex' => ['ministries complex', 'ministries'], 'Hai Jalaba' => ['hai jalaba', 'jalaba'],
            'Hai Buluk' => ['hai buluk', 'buluk'], 'Hai Amarat' => ['hai amarat', 'amarat'], 'Hai Thuora' => ['hai thuora', 'thuora'],
            'Hai Malakal' => ['hai malakal'], 'Hai Malakia' => ['hai malakia', 'malakia'], 'Hai Ghabat' => ['hai ghabat', 'ghabat'],
            'Newsite Bilpam' => ['newsite bilpam', 'bilpam', 'newsite'], 'Atlabara' => ['atlabara'],
            // Residential
            'Nyakuron' => ['nyakuron'], 'Konyo Konyo' => ['konyo konyo'], 'Gudele One' => ['gudele one', 'gudele 1'], 'Gudele Two' => ['gudele two', 'gudele 2'],
            'Tongping / Thongpiny' => ['tongping', 'thongpiny'], 'Kator' => ['kator'], 'Lologo' => ['lologo'], 'Hai Tijeria' => ['hai tijeria', 'tijeria'],
            'Hai Mabil' => ['hai mabil', 'mabil'], 'Hai Cinema' => ['hai cinema'], 'Hai Neema' => ['hai neema'], 'Hai Kuwait' => ['hai kuwait'],
            // Landmarks and commercial
            'Juba Airport (Matar)' => ['juba airport', 'matar'], 'Juba Nile Bridge' => ['nile bridge'], 'Konyo Konyo Market' => ['konyo konyo market'],
            'Equatoria Tower' => ['equatoria tower'], 'John Garang Mausoleum' => ['john garang mausoleum', 'garang mausoleum'], 'Gumbo' => ['gumbo'], 'Sherikat' => ['sherikat'],
            // Riverfront
            'Jebel Dinka' => ['jebel dinka'], 'Hai Nimra Talata' => ['nimra talata'], 'Gezira' => ['gezira'],
        ];
        $out = [];
        foreach ($areas as $name => $kw) {
            $out[] = ['name' => $name, 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => $kw, 'note' => ''];
        }

        return $out;
    }

    /** Sales Brain drafts: [topic, key, title, body or data, questions]. */
    public static function knowledge(): array
    {
        return [
            ['company', 'dishnet', 'About DishNet Africa', <<<'TXT'
DishNet Africa Ltd is an internet service provider operating in South Sudan since 2013, and an authorized Starlink reseller for South Sudan (https://dishnetafrica.com/about.html).
Office: Tomping Sector 4, American Embassy Road, opposite Pope Francis Roundabout, Juba. Walk-in Mon–Sat 9 AM – 6 PM (closed Sundays).
Email info@dishnetafrica.com · Website https://dishnetafrica.com
Outside Juba there is no separate office: sales, support and installation are handled centrally by WhatsApp, phone and email; technicians travel from Juba.
Departments: Sales +211 923 400 000 (Mon–Sat 9 AM – 6 PM) · Technical Support +211 921 443 002 (Mon–Sun 7 AM – 10 PM) · Accounts +211 921 443 009 (Mon–Sat 9 AM – 6 PM).
Useful pages: fiber https://dishnetafrica.com/fiber.html · coverage https://dishnetafrica.com/coverage.html · hotspot https://dishnetafrica.com/hotspot.html · reseller https://dishnetafrica.com/reseller.html · why DishNet https://dishnetafrica.com/why-dishnet.html · pay https://dishnetafrica.com/pay.html · app https://dishnetafrica.com/get-the-app.html · tutorials https://dishnetafrica.com/tutorials.html · FAQ https://dishnetafrica.com/faq.html
TXT, []],

            ['fiber', 'service', 'Fiber service', <<<'TXT'
Fiber is available in Juba only (30+ neighbourhoods; see Coverage). Outside Juba, recommend Starlink instead.
Three plans by speed — Basic 20 Mbps, Standard 40 Mbps, Premium 60 Mbps — prices are in the catalogue. Unlimited data (fair use applies). Symmetric: upload equals download.
Installation is one-time, the same for all three plans, and includes the Wi-Fi router.
Uptime: we target 99.9% — it is not a guarantee; refer to the terms.
Typically same-day installation in covered Juba areas once stock and coverage are confirmed. On-site work takes 2–4 hours. Latency typically 5–20 ms within Juba.
TXT, [self::Q_UPTIME, self::Q_EQUIPMENT, self::Q_INSTALL_TIME]],

            ['starlink', 'service', 'Starlink service', <<<'TXT'
Hardware: suggest the Starlink Mini first (lower price, portable, fits individuals and most small-business setups). Suggest the Standard only if the customer asks for it, describes heavy multi-user use (large household, office with many simultaneous video calls) or a hotspot expecting 30+ concurrent users.
Plans: Residential is the default for almost everyone — personal use, small offices, shops, cafés and hotspots up to about 50 active users, in Juba or outside. Priority plans only for 50+ active users or heavy commercial multi-streaming, or if the customer asks; for a "business plan" question, first ask how many users. Roam is a separate plan for roaming / moving between locations (with the car adaptor for mobile use). Residential is tied to the service address, which can be updated.
Installation: free in Juba. Outside Juba: free service installation; the customer provides the technician's round-trip travel and accommodation. On-site work takes 1–2 hours.
Works anywhere with a clear view of the sky; dense canopy or deep valleys may need a site assessment. Reaches all 10 South Sudan states where the sky is clear. Latency typically 25–50 ms.
TXT, [self::Q_EQUIPMENT, self::Q_ROOF, self::Q_PRIORITY, self::Q_INSTALL_TIME]],

            ['packages', 'which-package', 'Which package suits whom', <<<'TXT'
First find out: Juba or outside Juba? (Don't ask in the first message; engage with their question first, and don't ask if the area is already clear.)
In Juba: offer fibre first — cheaper, faster, more stable, unlimited. Fiber Standard 40 Mbps suits most homes and setups; Premium 60 Mbps for 30+ users. After one or two attempts, if they prefer Starlink, move to Starlink Mini + Residential.
Outside Juba: Starlink Mini (or Standard for heavy use / 30+ user hotspot) + Residential plan. Priority only for 50+ active users or heavy commercial use.
Hotspot / small business: the key question is how many active users. Most small hotspots have 15–25 active users at a time — Residential handles that comfortably.
Add-ons (routers, NanoStation, Public IP, car adaptor, power bank) only when finalising an order, never up front.
Products not in the catalogue (laptops, printers, CCTV, other brands): never give a price — Sales will confirm price and availability.
TXT, [self::Q_FIRST, self::Q_EXCISE]],

            ['coverage', 'fiber', 'Juba fibre coverage', ['areas' => self::fiberAreas()], [self::Q_COVERAGE]],

            ['qualification', 'questions', 'Qualifying questions', <<<'TXT'
Ask one question at a time, only what you still need:
1. Juba or outside Juba — then the area and the nearest landmark.
2. Home or business; for a business, the type (café, shop, office, hotel, school, NGO, hotspot).
3. How many people / active users at the same time, and what they mainly use it for.
4. For hotspots: expected active users. For resellers: Fiber, Starlink or both, and their business and location.
If the area is not on the coverage list: ask for the closest landmark or street; Sales confirms.
TXT, []],

            ['faq', 'general', 'Common questions', <<<'TXT'
Upload speed on fibre? Symmetric — the same as download (Fiber 40 = 40 down + 40 up).
Latency? Fibre typically 5–20 ms within Juba; Starlink typically 25–50 ms.
Static / public IP? Yes — the Public IP add-on (monthly), for CCTV, remote access, hosting.
Will Starlink work in a remote area? Anywhere with clear sky; dense canopy or deep valleys may need a site assessment.
Can I move Starlink between locations? Roam plan yes; Residential is tied to the service address but it can be updated.
Free trial? No. Hardware is tested at installation/delivery.
NGO, diplomat or corporate discounts? Same pricing for everyone; we serve NGOs and embassies at standard pricing.
Authorized Starlink reseller? Yes — https://dishnetafrica.com/about.html
How long is the installation visit? Fibre 2–4 hours, Starlink 1–2 hours.
Residential for a business or hotspot? Yes for most small setups up to about 50 active users; Priority for larger operations.
Hotspot voucher system? Yes — DishNet provides the voucher system, dashboard and configuration; the operator sells vouchers and collects payment. Operators see active users, voucher sales and usage.
Questions we never answer in chat — route to the team: cancellation, refunds, grace period after expiry, mid-cycle plan change, balance and invoices → Accounts +211 921 443 009; annual prepay discount, weekend/evening installation, transferring service to a new address → Sales +211 923 400 000.
TXT, [self::Q_RELOCATION]],

            ['objections', 'common', 'Objections and difficult moments', <<<'TXT'
Discount request: our prices are the same for every customer; help them pick the most cost-effective plan for their use.
A competitor is mentioned: don't compare or disparage; explain what DishNet offers.
"Why not buy directly from Starlink?": they can — https://dishnetafrica.com/why-dishnet.html. Short version: importing hardware to South Sudan is slow, payment cards are often rejected, and there is no local warranty support.
Angry customer / complaint: apologise, ask which service and since when, pass it to the right team at once; never promise a specific outcome.
Hotspot earnings: only ever illustrative — they depend on paying users, price and collection; never promise an amount; Sales models the specific case.
Reseller commission: negotiated on joining; never quote percentages or amounts in chat.
TXT, []],

            ['payment', 'methods', 'How customers pay', <<<'TXT'
Payment methods: cash (USD or SSP), bank transfer, Visa/MasterCard — all listed at https://dishnetafrica.com/pay.html, or pay at the office.
Never share bank details, account numbers or card instructions in chat; send the payment page or Accounts (+211 921 443 009).
Quote SSP only if the customer asks to pay in SSP.
Scam warning: DishNet never asks for payment to a personal mobile-money number or in gift cards. If a customer mentions such a request, tell them not to send anything.
Customer says they paid but the service is suspended: ask for the payment date and method and pass it to Accounts at once.
TXT, [self::Q_RATE]],

            ['followup', 'order-details', 'Taking an order and handing over', <<<'TXT'
When the customer is ready, collect one detail at a time: full name; phone (confirm it is the number messaging); location (area in Juba, or town); product (fibre plan, or Starlink kit + plan); preferred installation date/time; payment method (cash USD, cash SSP, bank transfer, card). For businesses, hotspots and resellers also: business name, type, expected active users.
Never ask for ID/passport (Sales handles it at delivery) or card details in chat.
Never promise an installation date; give the typical timing only.
Then confirm and hand over: our Sales team will reach out from +211 923 400 000 to confirm the installation timing.
TXT, [self::Q_HANDOVER, self::Q_INSTALL_TIME]],

            ['scripts', 'wording', 'How to word claims', <<<'TXT'
Say "up to 220 Mbps (typical speeds vary by location)", never "220 Mbps delivered".
Say "we target 99.9% uptime", never "99.9% uptime guarantee".
Say "Starlink generally provides reliable service in South Sudan, but no internet is 100%".
Say "reaches all 10 South Sudan states where the sky is clear", never "100% country coverage" or "works in every village".
Say "typically same-day installation in Juba once stock and coverage are confirmed", never "same-day installation".
Say "free installation in Juba; outside Juba free service installation, the customer covers technician travel", never an unqualified "free installation".
Hotspot earnings are always "indicative — actual earnings depend on users and pricing".
TXT, [self::Q_UPTIME]],

            ['technical', 'networking', 'Routers and networking hardware', <<<'TXT'
MikroTik (DishNet configures it at installation; advanced configuration questions go to Support/Sales):
- RB951 (RB951Ui-2HnD): SOHO router, built-in 2.4GHz Wi-Fi, 5 ports, PoE-out, USB for a 3G/4G modem — our standard client router for homes and small offices.
- hEX RB750Gr3: 5-port gigabit wired router, no Wi-Fi — for full gigabit on fibre or Starlink, strong firewall/QoS, or when the customer already has access points.
- L009UiGS-RM: rack-mount office router, 8 gigabit ports + SFP, dual-core — offices and larger sites; multi-WAN failover (e.g. fibre primary + Starlink backup).
- L009UiGS-2HaxD: the same in a desktop body with built-in 2.4GHz ax Wi-Fi.
Choosing: simple home/small office with Wi-Fi → RB951 (or a Ruijie router); gigabit wired-first → hEX; two internet lines with automatic failover or an SFP hand-off → L009. MikroTik Wi-Fi is basic 2.4GHz: for real coverage pair a MikroTik router with Ruijie ceiling access points.
Ruijie / Reyee (sold and installed by DishNet for homes, offices, hotels, cafés, hotspots): indoor routers, ceiling and wall access points and PoE switches for multi-room/multi-floor Wi-Fi; RG-RAP6262(G) outdoor Wi-Fi 6 AX1800 omni access point, IP68 — compounds, courtyards, outdoor hotspots. Installation is quoted by Sales.
Only items in the catalogue have a price; anything else — Sales confirms.
TXT, ['Prices for the hEX RB750Gr3, L009 models and the Ruijie range are in the n8n "dn_products" table, which was not in the file supplied. Export it so they can be added to the catalogue, or confirm they stay "Sales quotes it".']],
        ];
    }
}
