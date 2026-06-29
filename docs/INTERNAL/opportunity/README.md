# Business Opportunity Engine — internal note

Turns CloudBSS from an order-taker into a B2B sales assistant. When a tenant runs
`opportunity_mode`, a customer who trips a B2B signal (distributor / dealer / wholesale /
export / corporate / government / NGO / partnership / franchise / tender) is taken out of
the shopping/quotation path into a **sticky** qualification flow. The bot understands ->
qualifies -> values, **never negotiates price**, then hands a scored, sized lead to sales.

## Files
- `database/migrations/..._create_business_opportunities_table.php`
- `database/migrations/..._create_opportunity_tasks_table.php`
- `app/Models/BusinessOpportunity.php`, `app/Models/OpportunityTask.php`
- `app/Services/Bot/Opportunity/OpportunityTypes.php`  — taxonomy, triggers, qualification SCRIPT
- `app/Services/Bot/Opportunity/SlotExtractor.php`     — deterministic fact extraction
- `app/Services/Bot/Opportunity/OpportunityScorer.php` — score breakdown + size band
- `app/Services/Bot/BusinessOpportunityEngine.php`     — sticky orchestrator
- `app/Filament/Resources/BusinessOpportunityResource.php` (+ Pages, + Tasks relation manager)
- `app/Filament/Widgets/TodaysPrioritiesWidget.php`   — rep-scoped (managers see all)
- `app/Filament/Widgets/MyOpportunitiesWidget.php`    — rep's open book / manager pipeline
- Pipeline hook in `app/Jobs/ProcessIncomingMessage.php` (runs BEFORE one-shot lead capture)

## Sales-rep surface
Reps log into /app by OTP (phone), so `assigned_to` == `User.phone`. Scoping:
- owner / admin / manager  -> see the whole pipeline + all tasks
- any other role (sales rep) -> see only their assigned opportunities; Today's Priorities
  shows their tasks plus any still-unassigned ones.
Per-opportunity tasks are managed from the Tasks tab on the opportunity edit screen.

## Enabling per tenant (settings JSON — no migration)
```php
$tenant->putSetting('opportunity_mode', true);
$tenant->putSetting('opportunity_types', ['distributor','wholesale','export']); // omit = all
```
Suggested per business:
- Krishna Wellness (12): distributor, wholesale, export
- EuroPearl: wholesale, dealer, export
- DishNet: corporate, government, ngo
- Family Shoppers (fs): corporate

Handoff reuses the existing sales pipeline: `leadRecipients('sales')` + round-robin +
`NotifyOwner`. A `Lead` row (source=`opportunity`) and an `OpportunityTask` (call before
next business morning) are created; the rep gets a full opportunity card on WhatsApp.

## Flow rules baked in
1. Sticky: once active, every message routes here until handoff — no relapse to "how many cartons?".
2. Price is the LAST thing asked (SCRIPT order), and the first bare price always triggers the
   buying-vs-selling clarification before anything else.
3. Competitor intel is collaborative (photo/invoice optional, never "send invoice").
4. Core gate to hand off: business_name + area + monthly_volume + (current_brand OR buying_price).
5. After handoff the sticky pointer is released so normal shopping can resume; a later B2B
   message reattaches to the same open opportunity instead of duplicating it.

## Test
`php tests/Opportunity/logic_test.php` — deterministic brain (detection, extraction,
scoring, sizing, price-last ordering, core gate). 37 assertions.

## Not yet built (next pass)
Data-driven scoring: `score_breakdown` + won/lost outcomes are persisted now, so a future
miner can learn which signals (e.g. "competitor at 8500") actually convert and reweight.
