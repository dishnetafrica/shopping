# v4.52 — Send the statement on WhatsApp, and say what the charges were for

**No migration.** Unzip over the repo, `php artisan optimize:clear`, done.

---

## What changed, and why

**The button already existed. It sent the wrong thing.**

A customer record in V3 has had *Send statement on WhatsApp* in its **⋯** menu for a long time. It
sent a plain-text message built from the accounting ledger, whose lines read `• Invoice: USD 1,234`
— with no invoice number in them. A customer receiving that cannot tell you what they are paying
for. It also refused outright to send anything to a customer who was paid up, and reported that
refusal as an error.

Meanwhile `/papi/statement-send` — which builds the real statement PDF and sends it as a WhatsApp
document — was wired into the old panel only. The V3 button now posts there instead, exactly as the
invoice's *Send on WhatsApp* already does.

**The v4.51 descriptions were on the wrong surface.** They landed on the on-screen Account statement
tab. The PDF the customer actually receives still printed bare invoice numbers. Both now read from
one class, `LineDescription`, so they cannot drift apart again.

**"Send reminder" sent nothing.** It answered *"Reminder sent."* and did absolutely nothing, on the
customer record and in the assistant's next-best-action. It is gone. Chasing an overdue account now
offers the real statement send.

---

## Files

| File | |
|---|---|
| `app/Services/Invoicing/LineDescription.php` | **new** — one definition of what a statement line says |
| `app/Capability/Shared/CustomerInsight.php` | the screen's helpers now delegate to it |
| `app/Services/Invoicing/StatementService.php` | each line carries a `detail`; `items` eager-loaded |
| `app/Services/Invoicing/InvoiceRenderer.php` | the PDF prints the detail under the number |
| `app/Capability/Object/CustomerCapability.php` | send → `/papi/statement-send`; fake reminder removed |
| `app/Capability/Shared/AssistantCapability.php` | the brief chases with a real send |
| `app/Capability/Dto/ActionSpec.php` | actions can carry a POST body |
| `app/Capability/Registry/CoreCapabilityProvider.php` | manifests no longer advertise commands that do not exist |
| `app/Http/Controllers/Panel/InvoiceController.php` | every reply carries a human message; the file is named |
| `resources/panel/help-center.html` | the Customers card explains the button |

Nine of the ten are edits to files you already have. Nothing was deleted that another screen still
calls; `app/Services/Ledger/StatementMessenger.php` is left in place because the Books page still
uses it — it is only the customer record that stopped.

---

## Checks that ran

```
t452/describe.php    33 passed, 0 failed
t452/mutants.sh      15 killed, 0 survived
t452/contracts.sh    38 passed, 0 failed
t451/detail.php      19 passed, 0 failed   ← v4.51's own tests, unchanged, still green
```

That last line is the one that matters for the refactor: the screen's output is byte-identical to
what v4.51 produced. The logic moved; the behaviour did not.

---

## What is NOT proven

**The PDF itself.** There is no PDF engine in my environment, so dompdf has never rendered this. The
new description is a `<div>` inside the document-number cell, in the same shape credit notes have
used for months, and the column has room to wrap — but that is reasoning, not a rendered page.
Opening one statement is the only thing that closes it.

**The WhatsApp document send.** `sendDocument` is handed base64. That is what the Evolution driver
expects. **If this tenant is on the Cloud API driver instead, it needs a public URL and will log
`cloud_api_requires_public_url` and skip** — the same constraint that already applies to the supplier
statement and the quotation PDF. Worth checking before you trust the button:

```
php artisan tinker --execute="\$t=App\Models\Tenant::find(17); echo 'driver: '.(\$t->whatsapp_driver ?: config('whatsapp.default')).PHP_EOL;"
```

`evolution` → the send works as built. `cloud` → tell me, and I will route it through a token URL the
way the quotation portal does.
