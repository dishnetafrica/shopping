# wa:test — can a shop actually deliver to this number?

One file, `app/Console/Commands/WaTestCommand.php`. Your Dockerfile already does `COPY app/`,
so it ships with a normal rebuild. **No migration, no schedule change, no config change.**

Upload it to `app/Console/Commands/` in your repo, commit, press **Deploy** in EasyPanel.

```bash
php artisan wa:test 211927797217                 # from tenant 17 (default)
php artisan wa:test 211927797217 --tenant=7      # from one specific shop
php artisan wa:test 211927797217 --all           # from every shop with an instance, compared
php artisan wa:test 211927797217 --all --dry     # readiness table only, sends NOTHING
```

Exit code **0** = at least one warm test went through without a rejection. **1** = it didn't.

---

## The thing this tool exists to stop you doing

You want to compare Family Shoppers against a shop that works. That's the right instinct, but
done naively it produces a confident wrong answer, because **error 463 has two unrelated causes**:

- **Cold reach-out** — the shop is messaging someone who has never messaged it. WhatsApp refuses
  this as anti-spam. Completely normal. Says nothing about the shop's health.
- **Warm refusal** — the shop is replying to an existing conversation and is *still* refused.
  This is the broken case. It means the session has lost its privacy tokens.

Family Shoppers is failing **warm**. If you test another shop against 211927797217 and that shop
has never messaged the number, it will fail **cold** — and you'd conclude "both are broken, so
it's Evolution", which is the opposite of the truth.

So every row is labelled `WARM` or `cold`, based on whether that tenant has any prior contact
with the number in `bot_events` or `messages`. The final verdict is computed from warm tests only,
and cold results are explicitly excluded. If every test came out cold, the command tells you it
cannot answer the question rather than guessing.

---

## Reading the output

The readiness table comes first, and it's worth reading before the sends:

```
  id   shop                   instance         evolution   temp   prior contact
  ---------------------------------------------------------------------------
  17   Family Shoppers        shopbot_t17      open        WARM   1,204 events
  7    Patronics Services     shopbot_t7       open        cold   never — cold reach-out
  12   Krishna Wellness       shopbot_t12      NOT IN EVOLUTION
```

`NOT IN EVOLUTION` means the tenant row points at an instance Evolution has never heard of.
That is a real and silent failure mode — tenant 17 had a stale `shopbot_t17_3` channel row
exactly like that, and nothing anywhere reported it.

Then per send:

- **accepted by gateway: yes** — the gateway took the message. This is *not* delivery. Evolution
  returned 201 for every one of the 142 messages it refused on 11 August.
- **REFUSED** — a `send_failed` row landed for this number while we watched. Definitive.
- **no refusal in Ns** — nothing came back. Encouraging, not proof. The receipt path can be down
  and look identical to success, which is why the command tells you to check the handset rather
  than declaring victory.

`NO_REFUSAL` is deliberately never printed as "delivered". Absence of a rejection is weaker
evidence than presence of one.

---

## Safety

- `--dry` sends nothing and just prints the readiness table. Use it first.
- Each run sends **one** message per tenant. Under a reach-out timelock, every token-less send
  adds to the rate limit — so don't sit in a loop running this. A few times a day is fine;
  every two minutes is actively harmful.
- It uses `WhatsAppManager::forTenant()`, so it exercises your real send path — including the
  Cloud API driver if a tenant is already switched over. It is not a parallel implementation
  that could pass while the real one fails.
