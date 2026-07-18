# DEPLOY-TODAY.md — ONE upload for everything since your code snapshot
### Supersedes: live-fix-final · deploy-check · photo-catalogue · price-truth-2 · invoice-bulk · bulk-all · link-preview-fix

## Why one zip
Several of those zips carry the same files (routes, PanelApiController) at
different ages — uploaded out of order they'd overwrite each other. This zip is
the UNION at final state: 24 files, upload once, done.

## What's inside (by feature)
1. **Registry fix + hardening** — FollowupsCapability implements the contract;
   any registration failure = missing menu item, never a dead panel ← THE 500 FIX
2. **deploy:check** — the permanent build gate
3. **Photo catalogue = price list** — bot, cadence, and chat-composer button
4. **Price-truth round 2** — photo captions, combos, offers on panel price; all 8 readers test-pinned
5. **Bulk select + export on 6 lists** — invoices, quotations, customers, orders,
   payments, products (CSV Arabic-safe / PDF / WhatsApp bulk-send) + fixed the
   pre-existing invoice-send fatal (wrong MessageLog import)
6. **Link previews** — familyshoppers.net cards on WhatsApp say "Family Shoppers" + og tags
7. **Controller.php restored** — the missing base class landmine

## Deploy
1. Upload all 24 (drag the `app`, `resources`, `routes`, `tests` FOLDERS on GitHub)
2. Rebuild → then in the terminal:
```
php artisan deploy:check
php artisan test --filter='RelationshipMemory|FollowupEngine|PlanningEscalation|Attribution|ShopAddressFaq|PriceTruth'
```
Both green → hard-refresh /v3 → side menu is BACK, chats load.

## Then the console session that's been waiting all day (copy the block):
```
php artisan shop:configure 17 --address="A29 & A31, FREEDOM SQUARE CENTRE\nKonyo Konyo Market, Juba" --website="https://familyshoppers.net"
php artisan tinker --execute="$t=App\Models\Tenant::find(17); $t->putSetting('relationship_memory',true); $t->putSetting('followups_ai',true); $t->putSetting('ai_planning',true); echo 'flags on';"
php artisan relationship:extract --tenant=17 --batch=10
php artisan relationship:stages --tenant=17
php artisan relationship:plan --tenant=17
php artisan products:price-sync 17 --dry
```
Read the price-sync --dry list, then run it without --dry. Paste me the outputs —
especially the --dry list and the plan count.

## Wire the gate (once, 30 seconds — makes today's incident impossible forever)
EasyPanel → app → Build → append after composer install:  php artisan deploy:check
