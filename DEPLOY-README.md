# WhatsApp watchdog + verify — deploy

Two files, both under `app/Console/Commands/`. Your Dockerfile already does `COPY app/`, so they ship with a normal rebuild. **No migration, no schedule change, no config change.**

---

## Upload

Unzip. You get:

```
app/Console/Commands/WaWatchdogCommand.php     ← REPLACES the existing file
app/Console/Commands/WaVerifyCommand.php       ← new
```

On GitHub, upload both into `app/Console/Commands/` in your repo, keeping the paths. GitHub will show one file as *modified* and one as *added* — that's correct. Commit, then press **Deploy** in EasyPanel.

*(A file at the repo root would NOT have worked — your Dockerfile copies specific directories, not everything. That's why the verifier is an artisan command rather than a loose script.)*

---

## After the rebuild, run this

```bash
php artisan wa:verify
```

Today it will print every block green and the last one red:

```
  DELIVERY  ← the one that matters
  ✗ last delivered             2026-07-18 21:02 (3 weeks ago)
  · last rejected              2026-08-11 09:00 (20 minutes ago)
  ✗ rejections (24h)           15
  ✗ VERDICT                    NOT DELIVERING — the bot is answering into a void

  NOT WORKING — the shop cannot answer its customers.
```

**That contrast is the point.** Configuration fine, Evolution reachable, instance "open", webhooks arriving, worker healthy, AI replying — and nothing getting through. That exact combination is what hid this for 24 days.

When it's fixed, `last delivered` reads a few minutes ago and the footer says **WORKING**.

---

## The commands

```bash
php artisan wa:verify                        # read-only. Safe any time, run it whenever you wonder.
php artisan wa:verify --tenant=17            # a specific shop (17 is the default)
php artisan wa:verify --send=211922281312    # LIVE: really sends, then waits for WhatsApp's receipt
php artisan wa:verify --quiet                # no output, exit code only

php artisan wa:watchdog                      # the watchdog, by hand (it also runs every 5 min)
```

Exit code **0 = delivering, 1 = not**, so `wa:verify --quiet` works as a cron or uptime check.

### `--send` is the definitive test

It separates two things that look identical from outside:

1. **Evolution accepted it** — HTTP 201. That only means Evolution *took* the message. It returns 201 today, while completely broken.
2. **WhatsApp's receipt came back.** The command then watches for up to 45 seconds and reports `DELIVERED` or `REJECTED`.

Send to a number that has messaged the shop before — error 463 is specifically about replying to contacts, so a cold number can fail for unrelated reasons and muddy the answer.

**This is the cleanest way to judge the Evolution image upgrade:**

```bash
php artisan wa:verify --send=211922281312     # before — expect REJECTED
#   change the image tag in EasyPanel, redeploy, wait for Connected
php artisan wa:verify --send=211922281312     # after
```

`DELIVERED` = the newer Baileys carries the 463 fix and you have service back. `REJECTED` = roll the tag back and put everything into the Cloud API migration.

---

## What the watchdog change does

`wa:watchdog` already ran every five minutes. It asked Evolution one question — *"is the connection open?"* — and got "open" every five minutes for 24 days while every message was refused. **Connected and able-to-deliver are different facts, and only the second one matters.**

It now also compares the two stamps your webhook has always written (`wa_send_ok_at`, `wa_send_err_at`) and alarms when **rejections are arriving now AND nothing has been delivered for 6 hours**.

That AND is deliberate. A quiet shop has a stale `wa_send_ok_at` and nothing wrong with it — alarming on staleness alone would fire every Sunday, the owner would mute it, and a muted watchdog is worse than none.

**One thing to check:** the alert goes out via `PLATFORM_WA_INSTANCE`, deliberately **not** through the shop's own number — that's the number that can't deliver, so the alert would be swallowed by the fault it's reporting. If that env var isn't set, the alert can't be sent; it logs `wa.no_ops_instance` and gives up. Point it at a number that isn't a shop's.

Detection runs around the clock; the message waits for 7am–9pm in the shop's timezone, so a 3am fault becomes a 7am phone call. Recovery is announced once, through the shop's own number, since by then it works again.

New settings the panel can read for a banner, alongside the existing `wa_status`: `wa_delivery` (`ok`/`failing`), `wa_delivery_since`, `wa_delivery_alerted_at`.

---

## Honest note

Neither of these fixes anything. The bot still cannot deliver. What they do is make the *next* outage visible in six hours instead of twenty-four days, and give you a one-line way to check the answer at any moment — including whether the Evolution upgrade worked.
