# CloudBSS v4.23 — one tender at a time, and the pricing goes to your buyers

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.23.zip (cumulative — supersedes v4.22; 136 files) · **Migration:** none new (v4.22's `tender_items` still required — run `php artisan migrate` if you have not) · **Delta: 8 files.**

## 1. Work one tender at a time

The Items-to-price board now has a **tender filter** across the top: *All tenders* plus one chip per tender, each showing how many lines still need a price. Pick one and everything else disappears. Your choice is in the address bar (`…/papi/tenders#items/12`), so you can bookmark a single tender's pricing screen or send that link to someone.

**"🧾 Price its items"** on a tender row now jumps straight to *that* tender's board instead of the top of the combined list.

Each tender keeps its own bid sheet — lines, how many still need a price, cost, bid, profit, margin — so nothing is mixed between tenders.

## 2. Pricing work is assigned automatically to the people who do it

Two new settings under **Settings → Storefront → Tenders (TenderBot)**:

- **Who sources prices** — type your buyers, comma separated: `Savan, Jayesh`. They must exist under Settings → Team. Leave it empty to switch this off.
- **Where they check prices** — e.g. `Kampala market`. It goes into the task so there is no ambiguity.

Now, every time a tender is analysed (uploaded or picked up from the forum), TenderBot:

1. splits the unpriced lines into **buying categories** — sports & games, stationery & art, toiletries, furniture, solar, special-needs aids, food;
2. deals those categories between the named buyers, so one person walks the stationery shops and the other the sports shops instead of both wandering the whole market;
3. gives each person **one task** — *"💵 Kampala market prices — 27 item(s) · Islamic Relief South Sudan"* — with **their lines as a tick-list** and the quantity on each line;
4. sends the usual WhatsApp nudge with the no-login link, so they work from their phone in the market and tick items off as they go.

One task per person, never one per item — 68 nudges would be noise; a buyer wants a shopping list. Due date is **three days before the tender deadline** (or today if you are already inside that window), and anything closing within 7 days is top priority. Your existing task reminders and escalation cover them automatically.

There is also a **"👥 Assign this tender's pricing to the team"** button under each tender on the board, for when you add people later or want to re-deal the work.

If a name matches nobody, the system says so by name — *"Could not find Ramesh in your team"* — instead of quietly dumping the whole list on the owner.

## Set it up (2 minutes)

Settings → Storefront → Tenders → **Who sources prices**: `Savan, Jayesh` · **Where they check prices**: `Kampala market` → Save. Confirm both appear under Settings → Team with a WhatsApp number, or they will get the task without the nudge.

## Verified before ship

27-check harness: "Savan" matches *Savan Patel*, **"Jayesh bhai" matches *Jayesh*** (honorifics and punctuation ignored), phone numbers match on the last 9 digits, duplicates collapse; 18 unpriced lines split into two tasks with every line landing on somebody's tick-list; already-priced lines excluded; the owner is *not* handed the shopping list; market and client in the title; deadline in 7 days → priority 3; due date lands before the deadline; unknown names reported back by name; empty setting, ignored tender, and fully-priced tender each produce zero tasks with a clear reason; category buckets check out, including *"balls with bells for children with disability"* going to special-needs rather than the sports shop. Browser test of the board: chips render per tender with live counts, filtering shows only that tender, the hash deep-link `#items/2` opens filtered, "Price its items" jumps and filters, the assign button posts the right tender id and shows the reply, zero JS errors · `php -l` ×7 · v4.22's 43-check harness re-run clean · zip md5-verified (136 files).
