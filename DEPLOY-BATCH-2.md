# DEPLOY-BATCH-2.md — everything since deploy-today, ONE upload (21 files)
### Supersedes: bulkbar-ux · customers-hotfix · currency-repair · sales-manners ·
### progressive-profiling · nightly-learning · media-frontdoor-fix · demand-digest · abandoned-cart

## What's inside
1. **Bulk bar UX v2** — floating bottom bar, select-all-matching, shift-click range,
   row highlight, live money totals, busy states, Esc
2. **Customers page hotfix** (the actions-array crash)
3. **Currency repair v2** — `invoices:repair-paid` (totals + payments into true currency)
4. **Sales manners** — restraint prompt + suggestions only after a committed add
5. **Progressive profiling** — the bot gets acquainted (name + shop/area), once, naturally
6. **Nightly learning** — 02:30 vocabulary study + gaps/demand log (data, never instructions)
7. **Media front door** — voice notes + captionless photos now REACH the pipeline
8. **Morning demand digest** — 08:00 WhatsApp: asked-but-not-stocked, to owner + team
9. **Abandoned-cart trigger** — hottest leads first in the follow-up inbox, value computed

## Deploy
1. GitHub: drag the extracted `app`, `resources`, `routes`, `tests` FOLDERS → commit
2. Rebuild → in the terminal:
```
php artisan deploy:check
php artisan test --filter='SalesManners|ProgressiveProfiling|LexiconLearning|MediaFrontDoor|DemandDigest|FollowupEngine|RelationshipMemory|PlanningEscalation|Attribution|PriceTruth'
```

## Post-deploy console block (the new switches)
```
# rates then the money repair (you already read the dry list — it was clean)
php artisan tinker --execute="$t=App\Models\Tenant::find(17); $t->putSetting('rate_ugx_per_usd',3650); $t->putSetting('rate_ssp_per_usd',4500); echo 'rates';"
php artisan invoices:repair-paid 17

# purchaser on the morning digest (Jayesh's number)
php artisan tinker --execute="App\Models\Tenant::find(17)->putSetting('demand_digest_numbers','256XXXXXXXXX'); echo 'digest team set';"

# kick the new learners once
php artisan relationship:learn --tenant=17
php artisan relationship:learn --tenant=17 --show
php artisan demand:digest --tenant=17 --dry
php artisan relationship:plan --tenant=17
```

## The 5-minute live test after
voice note → answered · captionless photo → identified · "meta sukar kilo" → price
ONLY · new number asks price → gets price THEN "who do I have the pleasure…" ·
abandoned a cart 2h+ → it's in #/followups with its value.
