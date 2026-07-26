<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Solar Project Advisor — the knowledge library content.
 *
 * A reusable body of sales knowledge: customer FAQs, short educational explainers, objection-handling
 * responses, and ready-to-send WhatsApp messages. Data-only and generic in shape — another industry
 * ships its own content set behind the same {@see KnowledgeLibrary} service. Each entry:
 *   key, type (faq|education|objection|message), category, title, body, whatsapp (optional), tags[].
 */
class SolarKnowledge
{
    public const TYPES = ['faq', 'education', 'objection', 'message'];

    public const ENTRIES = [
        // ── FAQs ─────────────────────────────────────────────────────────────────────────────────
        ['key' => 'faq_battery_life', 'type' => 'faq', 'category' => 'Batteries',
            'title' => 'How long do the batteries last?',
            'body' => 'A quality LiFePO4 battery typically lasts 8–10 years (about 4,000–6,000 charge cycles). Older lead-acid types last 2–4 years. We size the bank so it is not stressed, which extends its life.',
            'tags' => ['battery', 'lifespan', 'lifepo4', 'warranty']],
        ['key' => 'faq_cloudy', 'type' => 'faq', 'category' => 'Technical',
            'title' => 'Do the panels work on cloudy or rainy days?',
            'body' => 'Yes, but at reduced output — panels still generate on overcast days, just less. We size the system and battery for the location so you keep power through cloudy spells and at night.',
            'tags' => ['weather', 'clouds', 'output', 'sun hours']],
        ['key' => 'faq_savings', 'type' => 'faq', 'category' => 'Cost & savings',
            'title' => 'How much will I save?',
            'body' => 'It depends on your current spend on grid power and generator fuel. Most customers offset a large share of their bill; a hybrid system pays back over a few years and then runs largely for free. We estimate your specific savings from your consumption during discovery.',
            'tags' => ['savings', 'payback', 'roi', 'bill']],
        ['key' => 'faq_appliances', 'type' => 'faq', 'category' => 'Technical',
            'title' => 'Can it run my fridge, TV and pump?',
            'body' => 'Yes — as long as the system is sized for those loads. Motors (fridges, pumps) need extra headroom for start-up surge, which our sizing engine accounts for. We list exactly which appliances your system covers.',
            'tags' => ['appliances', 'fridge', 'pump', 'surge', 'inverter']],
        ['key' => 'faq_install_time', 'type' => 'faq', 'category' => 'Installation',
            'title' => 'How long does installation take?',
            'body' => 'A typical home or small-business install is completed in 1–2 days after a short site survey. Larger commercial or institutional projects take longer and are scheduled against agreed milestones.',
            'tags' => ['installation', 'timeline', 'survey']],
        ['key' => 'faq_maintenance', 'type' => 'faq', 'category' => 'Maintenance',
            'title' => 'What maintenance does it need?',
            'body' => 'Very little — keep the panels clean and clear of shade, and check connections periodically. LiFePO4 batteries are essentially maintenance-free. We offer service visits if you prefer us to handle it.',
            'tags' => ['maintenance', 'cleaning', 'service']],
        ['key' => 'faq_warranty', 'type' => 'faq', 'category' => 'Cost & savings',
            'title' => 'What warranty do I get?',
            'body' => 'Each component carries its manufacturer and supplier warranty (listed in your proposal), and our installation workmanship is warranted for 12 months.',
            'tags' => ['warranty', 'guarantee']],
        ['key' => 'faq_grid_hybrid', 'type' => 'faq', 'category' => 'Technical',
            'title' => 'What is the difference between off-grid and hybrid?',
            'body' => 'Off-grid runs entirely on solar and battery with no grid connection. Hybrid uses solar first, tops up from the grid when needed, and provides backup during outages — usually the most cost-effective where grid power exists but is unreliable.',
            'tags' => ['off-grid', 'hybrid', 'grid-tie']],

        // ── Educational ──────────────────────────────────────────────────────────────────────────
        ['key' => 'edu_how_solar_works', 'type' => 'education', 'category' => 'Technical',
            'title' => 'How a solar power system works',
            'body' => 'Panels convert sunlight to DC electricity. A charge controller feeds it to a battery bank, and an inverter converts DC to the AC your appliances use. By day you run on solar and charge the battery; at night or during outages you run on the stored energy.',
            'tags' => ['basics', 'how it works', 'components']],
        ['key' => 'edu_battery_types', 'type' => 'education', 'category' => 'Batteries',
            'title' => 'LiFePO4 vs lead-acid batteries',
            'body' => 'LiFePO4 lasts far longer (8–10 years), can be discharged deeper (~80–90%), and is maintenance-free — a better value over its life despite a higher upfront cost. Lead-acid is cheaper to buy but shorter-lived and should only be discharged ~50%.',
            'tags' => ['battery', 'lifepo4', 'lead-acid', 'depth of discharge']],
        ['key' => 'edu_sizing', 'type' => 'education', 'category' => 'Technical',
            'title' => 'How systems are sized',
            'body' => 'We add up your appliances and their daily run-hours to get daily energy, then size panels to replace that energy within the local sun hours, the battery to carry your critical loads for the backup hours you need, and the inverter for your peak demand — all with a safety margin and room to expand.',
            'tags' => ['sizing', 'load', 'design']],
        ['key' => 'edu_backup_autonomy', 'type' => 'education', 'category' => 'Technical',
            'title' => 'What "backup hours" really means',
            'body' => 'Backup (autonomy) is how long your critical appliances can run from the battery with no sun or grid. More backup hours means a bigger battery bank. We focus the battery on the loads that truly must stay on to keep it affordable.',
            'tags' => ['backup', 'autonomy', 'battery sizing']],

        // ── Objection handling ─────────────────────────────────────────────────────────────────────
        ['key' => 'obj_expensive', 'type' => 'objection', 'category' => 'Sales',
            'title' => 'It is too expensive',
            'body' => 'Frame it against what they already spend: compare the upfront cost to years of grid bills and generator fuel. Offer a phased system (start with critical loads, expand later) and, if available, financing or a smaller starter package.',
            'whatsapp' => 'I understand budget matters. Many customers find the system pays for itself in a few years versus fuel and grid bills — and we can start with a smaller package covering just your essentials and expand later. Shall I show you a phased option?',
            'tags' => ['price', 'budget', 'cost', 'financing']],
        ['key' => 'obj_wait_prices', 'type' => 'objection', 'category' => 'Sales',
            'title' => 'I will wait for prices to drop',
            'body' => 'Every month of waiting is another month of paying full grid and fuel costs. Prices are relatively stable, while the savings start the day the system is switched on. Waiting usually costs more than it saves.',
            'whatsapp' => 'That is fair — but while you wait you keep paying full power and fuel bills. The savings start the day we switch it on, so waiting often costs more than it saves. Want me to show the monthly savings?',
            'tags' => ['wait', 'timing', 'price drop']],
        ['key' => 'obj_sun', 'type' => 'objection', 'category' => 'Sales',
            'title' => 'The sun is not always out',
            'body' => 'Systems are designed for local conditions, including cloudy days, and the battery carries you through the night and dull spells. Panels still produce in overcast weather. We size for a realistic sun-hour figure, not a perfect day.',
            'whatsapp' => 'Great question — we size for real local weather, including cloudy days, and the battery keeps you powered at night and through dull spells. You will not be left without power.',
            'tags' => ['sun', 'weather', 'reliability']],
        ['key' => 'obj_battery_fail', 'type' => 'objection', 'category' => 'Sales',
            'title' => 'I heard batteries fail quickly',
            'body' => 'That reputation comes from cheap lead-acid batteries pushed too hard. We use LiFePO4, which lasts 8–10 years, and we size the bank so it is never over-stressed. It also comes with a warranty.',
            'whatsapp' => 'That is usually true of cheap lead-acid batteries. We use LiFePO4 (8–10 year life), sized so it is never over-stressed, and it is warranted. Very different from the old batteries.',
            'tags' => ['battery', 'reliability', 'lifepo4']],
        ['key' => 'obj_generator', 'type' => 'objection', 'category' => 'Sales',
            'title' => 'A generator is cheaper',
            'body' => 'A generator is cheaper to buy but expensive to run — fuel, servicing, noise and fumes, and it only works while running. Solar has almost no running cost, is silent, and powers you day and night. Many customers keep the generator only as a rare backup.',
            'whatsapp' => 'A generator is cheaper upfront, but fuel and servicing add up fast — and it is noisy. Solar runs almost free, silently, day and night. Most customers keep the generator just as an occasional backup. Want a cost comparison?',
            'tags' => ['generator', 'fuel', 'running cost']],
        ['key' => 'obj_roof', 'type' => 'objection', 'category' => 'Sales',
            'title' => 'I do not have enough roof space',
            'body' => 'Panels can also be ground-mounted or placed on a nearby structure, and modern panels are more powerful per square metre. We confirm the layout at the site survey — space is rarely the blocker people expect.',
            'whatsapp' => 'No problem — panels can go on the roof, a ground mount, or a nearby structure, and today\'s panels are compact and powerful. We confirm the best layout at a quick site visit.',
            'tags' => ['roof', 'space', 'mounting']],

        // ── WhatsApp-ready messages ─────────────────────────────────────────────────────────────────
        ['key' => 'msg_intro', 'type' => 'message', 'category' => 'Sales',
            'title' => 'First contact / introduction',
            'body' => 'A warm opener for a new solar enquiry.',
            'whatsapp' => 'Hello! Thank you for your interest in going solar. To recommend the right system, may I ask a few quick questions about what you would like to power and how often you have outages?',
            'tags' => ['intro', 'greeting', 'lead']],
        ['key' => 'msg_quote_follow', 'type' => 'message', 'category' => 'Sales',
            'title' => 'Quote follow-up',
            'body' => 'A gentle nudge after sending a proposal.',
            'whatsapp' => 'Hi, just checking in on the solar proposal I sent. Happy to walk you through any part of it or adjust the system to fit your budget. When would be a good time to talk?',
            'tags' => ['follow-up', 'quote', 'proposal']],
        ['key' => 'msg_site_visit', 'type' => 'message', 'category' => 'Installation',
            'title' => 'Schedule a site visit',
            'body' => 'Arrange the survey that confirms the design.',
            'whatsapp' => 'To finalise your system we do a short, free site survey to confirm layout and quantities. It takes under an hour. Which day this week works for you?',
            'tags' => ['site visit', 'survey', 'scheduling']],
        ['key' => 'msg_financing', 'type' => 'message', 'category' => 'Cost & savings',
            'title' => 'Payment options',
            'body' => 'Open the door on budget with flexible terms.',
            'whatsapp' => 'We have flexible payment options and can also phase the installation so you start with your essentials and expand later. Would a smaller starter package or a payment plan suit you better?',
            'tags' => ['payment', 'financing', 'budget']],
        ['key' => 'msg_after_sale', 'type' => 'message', 'category' => 'Maintenance',
            'title' => 'After-sale check-in',
            'body' => 'Build loyalty and referrals after handover.',
            'whatsapp' => 'Hello! Now that your solar system has been running a while, how is it performing? We are here for any support, and we offer service visits to keep it in top shape.',
            'tags' => ['after-sale', 'support', 'referral']],
        ['key' => 'msg_referral', 'type' => 'message', 'category' => 'Sales',
            'title' => 'Ask for a referral',
            'body' => 'Turn a happy customer into new leads.',
            'whatsapp' => 'So glad you are happy with your system! If you know anyone else tired of blackouts or high bills, we would be grateful for an introduction — and we look after referrals.',
            'tags' => ['referral', 'word of mouth']],
    ];

    /** All entries. */
    public static function all(): array
    {
        return self::ENTRIES;
    }
}
