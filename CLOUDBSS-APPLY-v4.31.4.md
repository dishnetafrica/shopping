# CloudBSS v4.31.4 — found it: the token was being read as the shop name

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.31.4.zip (cumulative — supersedes v4.31.3; 165 files) · **Migration:** none (v4.31.3's backfill still applies if you have not run it) · **Delta: 2 files.**

## Your `quote:check` output settled it

Everything was correct: token **FOUND**, quotation 216, tenant 17 Family Shoppers, tenant **active**, `familyshoppers.net` resolving to **tenant 17** — the same tenant that owns the quotation. No mismatch anywhere.

Which means my v4.31.2 change was **not** the fix. With the host and the owner agreeing, the old code would have resolved it too. Something was failing before the lookup ever ran.

It was.

## The bug

The portal methods were declared like this:

```php
public function show(?string $shop = null, string $token = '')
```

The link route supplies **one** parameter: `/q/{token}`. **Laravel binds controller arguments positionally.** With a single route value, it fills slot 0 — so **`$shop` received the 40-character token**, and `$token` fell back to its default, an empty string.

The portal then went looking for a shop whose slug was `jbe3kt5rebgtssrguh4frzpbeqxz2zetud6wqqoh`, found nothing, and returned 404. Every host. Every quotation. Every time, since the day the shopless route was added.

It hid because the *other* route shape, `/{shop}/q/{token}`, supplies **two** values — so those bound correctly and worked fine. Only the short links, the ones actually sent to customers, were broken.

**The fix:** all six portal methods now read `route('token')` and `route('shop')` **by name**. Route parameters are keyed by name, so no number of them can be mis-ordered.

## What I got wrong, in order

Worth recording, because two of my three previous answers were confidently wrong:

- **v4.31.2** — I blamed host-vs-tenant resolution order. Your output disproved it: they matched. That change is still a genuine improvement (a link now opens on any host that serves CloudBSS) but it was not your bug.
- **v4.31.3** — I said a raw insert was skipping the token hook. Wrong: there is one creation path and it uses the model properly. The 64 missing tokens were real, but the cause was a migration with no backfill, and that is fixed.
- **v4.31.4** — this. Proven rather than reasoned: I reproduced Laravel's argument-binding algorithm and ran it against the actual method signatures. It puts the token in `$shop` and leaves `$token` empty, exactly as observed.

## After you deploy

Open the same link. It should now show the quotation.

```
https://familyshoppers.net/q/jbe3kt5rebgtssrguh4frzpbeqxz2zetud6wqqoh
```

Every quotation link ever sent from this system starts working at the same moment — the tokens were always valid, the page just never read them. Worth telling anyone still sitting on an unanswered quotation.

If you have not run `php artisan migrate` since v4.31.3, do — that is what gives the 64 older quotations a token at all.

## Verified before ship

**22 checks**, and this time the diagnosis itself is tested rather than argued.

Laravel's `resolveMethodDependencies` is reproduced verbatim and run against the real signatures. It proves the old `show(?string $shop = null, string $token = '')` binds the token into `$shop` and leaves `$token` as `''` for a one-parameter route; that `share(Request $r, …)` fails identically despite the Request in front; and that `/{shop}/q/{token}`, with two values, always bound correctly — which is precisely why this survived so long.

Against the shipped file: all six public portal methods take **no** positional `$shop`/`$token`, the token and shop are read via `route('token')`/`route('shop')`, all six route through `resolveRequest()`, and no positional `resolve($shop, $token)` call remains anywhere. Both route shapes are still registered.

`php -l` clean · v4.31.2's 21, v4.31.1's 35 and 26, v4.30's 61, v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
