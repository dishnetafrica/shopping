# DEPLOY-BATCH-3.md — the complete current fix set, ONE GitHub upload (24 files)
### Supersedes deploy-batch-2 AND everything after it (promise-delivery · bot-reliability).
### Safe over any partial state — every file is the final superset version.

## What this contains (11 features/fixes)
1. **Bot reliability net** — job retries once, then failed(): customer gets a courtesy
   reply + YOU get a 🚨 alert; watchdog now watches tenant 17 (default bot_mode=ai),
   watches TAKEN-OVER chats (30-min staff alert), and AUTO-RELEASES forgotten
   takeovers after 2h ← fixes tonight's "bot not responding" root cause (line 272 gate)
2. **Promise↔delivery coupling** — <<SEND>> block: the AI can only say "I'll send the
   price list" by emitting the block that sends it; failed delivery = customer courtesy
   + owner alert; the 300-product silent self-disable raised to 1000 (you're at 318)
3. Bulk-bar UX v2 (floating bar, select-all-matching, shift-click, money totals)
4. Customers page hotfix
5. Currency repair command (run the money fix if not yet done)
6. Sales manners (answer-first, one suggestion max, no bazaar)
7. Progressive profiling (name + shop/area, asked once, naturally)
8. Nightly vocabulary learning + gaps/demand log (02:30)
9. Media front door (voice notes + captionless photos reach the pipeline)
10. Morning demand digest (08:00 → owner + team WhatsApp)
11. Abandoned-cart trigger (hottest leads first in the follow-up inbox)

## Deploy
1. Extract → GitHub → drag the `app`, `resources`, `routes`, `tests` FOLDERS → commit
2. EasyPanel **Rebuild** → in the SHOPPING container terminal (/var/www/html — not /evolution 🙂):
```
php artisan deploy:check
php artisan test --filter='BotReliability|SendPromiseCoupling|SalesManners|ProgressiveProfiling|LexiconLearning|MediaFrontDoor|DemandDigest|FollowupEngine|RelationshipMemory|PlanningEscalation|Attribution|PriceTruth'
```

## Post-deploy console block (everything still pending, in one paste)
```
# release the 4 stranded takeovers (bot resumes on their next message)
php artisan tinker --execute="echo App\Models\Conversation::withoutGlobalScope('tenant')->where('tenant_id',17)->whereIn('customer_phone',['256709687944','917017790081','211922995966','211914832718'])->update(['agent_active'=>false]).' released';"

# FX rates + the money repair (dry list already verified clean)
php artisan tinker --execute="$t=App\Models\Tenant::find(17); $t->putSetting('rate_ugx_per_usd',3650); $t->putSetting('rate_ssp_per_usd',4500); echo 'rates';"
php artisan invoices:repair-paid 17

# purchaser on the morning digest
php artisan tinker --execute="App\Models\Tenant::find(17)->putSetting('demand_digest_numbers','256XXXXXXXXX'); echo 'digest team';"

# kick the learners once
php artisan relationship:learn --tenant=17 && php artisan relationship:learn --tenant=17 --show
php artisan relationship:plan --tenant=17
php artisan demand:digest --tenant=17 --dry
```

## The 6-point live test
voice note → answered · captionless photo → identified · "price list" → PDF actually
arrives (SEND block) · "meta sukar kilo" → price only · staff reply then ignore a
customer 30 min → YOUR phone buzzes · 2h → bot resumes by itself.

## REMINDERS still open
- Manual replies to the 4 waiting customers FIRST (sugar-500 especially)
- Wire `php artisan deploy:check` into the EasyPanel build command (one line, forever)
- The three V4 flags if not yet flipped: relationship_memory · followups_ai · ai_planning
