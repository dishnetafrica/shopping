# CloudBSS v4.31.2 — quotation links resolve by token, not by domain

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.31.2.zip (cumulative — supersedes v4.31.1; 164 files) · **Migration:** none · **Delta: 3 files.**

## What I checked, and what it ruled out

I tested the live link you sent. Findings, in order:

| check | result |
|---|---|
| Token shape — route needs 40 lowercase letters/digits | **40 chars, matches exactly.** The route is reachable. |
| `familyshoppers.net/q/{token}` | 404 |
| `mycloudbss.com/q/{token}` | **also 404** — so it is not a custom-domain problem |
| `familyshoppers.net/quote` (POST-only route in the same group) | **405 Method Not Allowed** |

That 405 is the important one. A route file that is not deployed gives 404; one that is gives 405 for the wrong verb. **So the routes are live and `/q/{token}` is registered — the 404 is coming from inside the controller, which means it did not find the quotation.**

## The fragility I found, and fixed

The portal resolved the **tenant from the host first**, and only fell back to looking the quotation up by its token *if no tenant matched the host*. On a host that does resolve to a tenant — which `familyshoppers.net` does — that fallback never ran. So if the resolved tenant was not the quotation's owner, a perfectly valid link 404'd, and the page gave no way to tell that apart from a bad token.

**The 40-character token is unguessable and globally unique. It is the credential, and it identifies the quotation on its own.** So the order is now reversed: look the quotation up by token, take the tenant from the record, and let the host and the `/{shop}` segment decide only how links *on* the page are written — never whether the page exists.

A link now opens on any host that serves CloudBSS. Nothing was loosened: an unknown token still 404s, a suspended tenant still 404s, and the token remains the only thing that grants access.

## Find out for certain, in one command

```
php artisan quote:check jbe3kt5rebgtssrguh4frzpbeqxz2zetud6wqqoh
```

It tells you whether the token exists at all, which tenant owns it, whether that tenant is suspended, whether the domain in the link resolves to that same tenant, and what link the system would generate today.

If it says **NOT FOUND**, the fix above will not help and the cause is different — the quotation was created by a path that bypassed the model (a raw insert skips the hook that generates the token), or the row is gone. Run:

```
php artisan quote:check --audit
```

which counts how many quotations have no portal token at all, and flags the other trap: a tenant that has the *platform* host set as its own custom domain, which silently breaks host resolution for everybody else.

Send me either output and I will take it from there.

## After you deploy

1. Open the same link again. If it now loads, the cause was the tenant/host mismatch and it is fixed.
2. If it still 404s, run `php artisan quote:check <token>` and send me what it prints.

No migration.

## Verified before ship

**21 checks** against the shipped source, on the thing that was actually wrong — order of operations.

The token lookup provably precedes both `byCustomDomain` and the `/{shop}` slug lookup; the tenant is taken from the record rather than the URL; the token query bypasses the tenant scope, because it runs before the tenant context exists. Nothing protective was dropped: still exactly three abort points — no tenant, suspended tenant, unknown token — and items now load *after* the context is set so their own scopes resolve. `$base` is still derived from the URL but nothing aborts on it any more, so the address can no longer decide whether the page exists.

Also asserted, because they are the assumptions the whole link depends on: the live token you sent **does** match the route pattern, uppercase does not (tokens are generated lowercase), and the generator is `Str::lower(Str::random(40))` on `creating` — so a token is persisted once and never rotated by a later save or revision. A quotation link stays valid for the life of the record.

`php -l` on 2 PHP files · v4.31.1's 61, v4.30's 61, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
