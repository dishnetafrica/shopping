# CloudBSS v4.32 — a do-not-message list for campaigns

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.32.zip (cumulative — supersedes v4.31.4; 169 files) · **Migration:** none · **Delta: 10 files.**

## The honest answer to your question: you couldn't

There was no way to exclude a number from a campaign. Not a hidden setting, not an undocumented flag — **nothing in either WhatsApp blast path checked anything at all.** Whoever the audience query returned got the message.

There *is* a do-not-contact table in the database, but only the Email Center ever consulted it, it has no screen anywhere, and its phone matching was broken: it stored whatever you typed and compared it against bare digits, so a number entered as `+256 772 123 456` could never match `256772123456`. It would have looked like it was working.

Your own number was in that audience too. If you have ever placed an order or exist as a customer profile, every broadcast went to you.

## Where to set it

**Settings → WhatsApp & bot → Never message these numbers**

Type them one per line, or separated by commas. **Any format works.** These all match the same person:

```
0772 123 456
+256772123456
256-772-123456
(0772) 123-456
```

You do not have to know how the number was saved against the customer — that was the whole trap, and it is handled.

There is a second switch, **Never message my own team**, ON by default. It skips your own WhatsApp line, your owner-alert numbers and anyone on the order-notification list. Broadcasting to your own staff looks careless to them and quietly skews every reply figure you then read.

## What it covers

All three campaign systems, at every point one could leak:

- **Classic WhatsApp blast** — filtered inside `AudienceResolver::norm()`, the single place all five audience modes (recent, inactive, VIP, category, all) pass through. Add a sixth mode next year and it is filtered without anyone remembering.
- **AI Campaign** — checked twice. Once before a draft is written, because each draft costs an AI call, and again **at send time**, because drafts sit approved for days and a number added to the list in between must still be honoured.
- **Email Center WhatsApp campaigns** — checked before the message is even queued.

The audience preview now shows the number that will **actually** be contacted, with the excluded count beside it, so you can see the list working before you send anything.

## How matching works, and why it is a suffix

Every number, from every source, is reduced to digits — exactly what the send loops do. Matching then compares the **last nine digits**, so a country code on one side and a leading zero on the other still resolve to the same person. Nine digits is long enough that a false match needs two customers whose numbers agree on all nine.

There is a `PhoneNormalizer` class in this codebase that adds a country code and strips leading zeros. It is deliberately **not** used here — it would produce `256772123456` where the send loop compares `0772123456`, and the exclusion would silently never fire.

## Also fixed

The suppression table now normalises phone numbers to digits on **write and read**, so the existing unsubscribe/do-not-contact API works for WhatsApp instead of storing rows that can never match.

## Worth knowing

`CampaignService` has a comment claiming WhatsApp campaigns carry a "reply STOP to opt out" line. **That is not implemented** — nothing appends it and nothing processes an inbound STOP. Now that there is a list to add people to, wiring STOP into it is a small job and the obvious next step. Say the word.

## After you deploy

1. **Settings → WhatsApp & bot → Never message these numbers** — add the numbers.
2. Open a campaign and check the audience count. It should drop, and show how many were excluded.
3. Send a small one first and confirm the excluded numbers stay quiet.

No migration.

## Verified before ship

**65 checks** against the real class.

**The harness caught a bug that would have made the whole feature useless.** I first split the box on any whitespace — so `+256 772 123 456` shattered into four fragments, each too short to be a number, and matched nobody. Silently: a list that matches nothing looks exactly like a list that is working. Lines, commas and semicolons now separate; spaces are part of the number. Several numbers space-separated on one line are still handled, because more than 15 digits cannot be a single number.

Covered: one person written six different ways all match; a country code on either side matches; a different number does not; junk in the box (`none`, `n/a`, `---`, `12345`) is ignored without breaking anything, and a good number alongside junk still works; `filter()` splits a real audience correctly including a duplicate spelling of the same person; staff exclusion on and off; **another tenant's suppression row does not leak in**; an email address in the table is not treated as a phone number; writes store digits on the WhatsApp channel and refuse anything too short; a null tenant and an unconfigured shop block nobody.

And the check that matters most for a feature like this — **every send path is asserted to actually consult the list**: the classic resolver inside `norm()`, the AI campaign at both generation and send, and the Email Center's WhatsApp branch. A leak in any one of them would make the other three pointless.

`php -l` on 7 PHP files · v4.31.4's 22, v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
