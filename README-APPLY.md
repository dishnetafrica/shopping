# Delivery watchdog — apply + the Evolution gamble + Cloud API prep

Three things, in the order you asked for them.

---

## 1. The watchdog (built — apply now)

**One file replaced:** `app/Console/Commands/WaWatchdogCommand.php`

Nothing else changes. It's already scheduled — `routes/console.php:28` runs `wa:watchdog` every five minutes — and no migration is needed, because the two stamps it reads have been written by the webhook all along and nobody ever looked at them.

```bash
# copy the file into the repo, commit, push, then in EasyPanel: Rebuild
php artisan wa:watchdog          # run it by hand first
```

Expected output today:

```
wa:watchdog checked N instances, 0 down, 1 connected-but-undeliverable.
```

That `1` is Family Shoppers. It's the line that has been missing for 24 days.

### What it does

The existing watchdog asked Evolution one question — *"is the connection open?"* — and got "open" every five minutes for 24 days while every message was rejected. **Connected and able-to-deliver are different facts, and only the second one matters.** So it now also compares:

- `wa_send_ok_at` — stamped on every delivery receipt
- `wa_send_err_at` — stamped on every rejection

and alarms when **rejections are arriving now AND nothing has been delivered for 6 hours**.

That `AND` is the whole design. Alarming on staleness alone would fire at every quiet shop every Sunday, the owner would mute it, and a muted watchdog is worse than none. The nine cases below are verified:

| Situation | Alarm |
|---|---|
| Healthy shop, steady traffic | no |
| Quiet Sunday, nothing sent for 3 days | no |
| One blocked contact, everything else fine | no |
| Error 5 min ago but delivered 2 min ago | no |
| Errors now, last delivery 2 hours ago | no |
| Errors now, last delivery 7 hours ago | **yes** |
| Family Shoppers on 11 Aug (24 days mute) | **yes** |
| Recovered — errors stopped 4 hours ago | no |
| New number, never delivered, erroring now | **yes** |

### Two details that matter

**It does not use `NotifyOwner`.** That sends through the shop's own number — precisely the one that cannot deliver — so the alert would be swallowed by the fault it is reporting. It goes out via `PLATFORM_WA_INSTANCE` instead, the same reasoning `WhatsappConnectionAlert` already uses. **If `PLATFORM_WA_INSTANCE` is not set, the alert cannot be sent** — it logs `wa.no_ops_instance` and gives up. Set it to a number that is *not* a shop's.

**Detection runs always; the message waits for business hours** (7am–9pm in the tenant's timezone). A 3am detection becomes a 7am message. Recovery is announced once, through the shop's own number, since by then it works again.

New tenant settings the panel can read for a banner, alongside the existing `wa_status`: `wa_delivery` (`ok` / `failing`), `wa_delivery_since`, `wa_delivery_alerted_at`.

---

## 2. The Evolution gamble (30 minutes, do it today)

2.3.7 is December 2025 and ships Baileys `7.0.0-rc.9`. The 463 work upstream is July 2026, so no *released* Evolution can contain it. Building from `main` might.

In EasyPanel, on the **evo-evolution-api** service:

1. Note the current image tag first, so you can roll back in one edit.
2. Change the image to the latest `main` build — `atendai/evolution-api:latest` or `evoapicloud/evolution-api:latest` depending on which you're on. **Do not** delete the instance or the volume; the session survives an image change.
3. Redeploy, wait for it to come up, confirm the instance still shows **Connected**.
4. Test:

```bash
php artisan tinker --execute="
  \$c = config('whatsapp.drivers.evolution');
  \$r = Illuminate\Support\Facades\Http::withHeaders(['apikey'=>\$c['api_key']])->timeout(25)
        ->post(rtrim((string)\$c['base_url'],'/').'/message/sendText/shopbot_t17',
               ['number'=>'211922281312','text'=>'test '.now()->format('H:i:s')]);
  echo 'HTTP '.\$r->status().\"\n\";
"
sleep 30
php artisan tinker --execute="
  \$t=App\Models\Tenant::find(17);
  echo 'last OK : '.(\$t->setting('wa_send_ok_at') ?: 'NEVER').\"\n\";
  echo 'last ERR: '.(\$t->setting('wa_send_err_at') ?: '—').\"\n\";
"
```

**`last OK` showing today = fixed.** Still 18 July = the gamble lost; roll the tag back and put everything into path 3.

Be clear-eyed: if it works, you are running unreleased code in production on a library that just broke you for 24 days. Treat it as breathing room to do the migration properly, not as the fix.

---

## 3. Cloud API migration — start the Meta clock today

Verification is the long pole and it costs nothing to begin, so start it now regardless of how the gamble goes.

**What you do (nothing technical):**

1. Meta Business Manager → add **Family Shoppers** as a business, submit verification documents.
2. WhatsApp Manager → add phone number **211929245020**. It must first be **deleted from the WhatsApp Business app**, and doing that means the bot cannot fall back to Evolution on that number. Consider using a second number for Cloud API and keeping 211929245020 on Evolution until the new one is proven.
3. Once approved, collect the **permanent access token** and the **phone number ID**.

**What I do when you have those three things:** set `WHATSAPP_CLOUD_TOKEN` and `WHATSAPP_CLOUD_PHONE_ID`, point Meta's webhook at `https://<your-domain>/api/webhook/whatsapp/cloud` with the verify token from `config('whatsapp.cloud_verify_token')`, flip tenant 17's `whatsapp_driver` setting to `cloud`, and test the round trip. The code path already exists — `CloudApiGateway.php`, the driver in `config/whatsapp.php`, and the `hub_challenge` handshake in `WebhookController::handle`. It needs credentials and testing, not new code.

**The one behavioural difference to plan for:** outside 24 hours of a customer's last message you can only send **pre-approved templates**, not free text. Your bot replies within minutes, so conversations are fine. What needs templates is proactive traffic — order confirmations sent the next day, statements, campaigns. Worth listing those before you migrate so the templates go in for approval at the same time as the number.

---

## Honest summary

The watchdog is real and worth applying today, but **it does not fix anything** — it makes sure the next outage is caught in six hours instead of twenty-four days. The gamble might restore service this afternoon or might do nothing. The Cloud API is the only path that removes this failure permanently, and its slowest step is Meta's paperwork, which is why it should start now.
