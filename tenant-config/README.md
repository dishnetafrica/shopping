# Three interchangeable presets for the same tenant

All three drive the SAME `technology.html` and the SAME `TechTheme`. Switching is one
command and no deploy — the rendered pages are byte-identical outside the injected
config blob.

| File | Look | Landing page |
|---|---|---|
| `secure-solutions-settings.json` | New technology theme — blue `#1F6FEB`, Manrope/Inter | Standard technology homepage |
| `secure-solutions-wordpress-match-settings.json` | **Exact current WordPress colours/font** | Standard technology homepage |
| `secure-solutions-wplayout-settings.json` | Exact WordPress colours/font | **Exact current WordPress section order** |

```bash
php artisan storefront:technology --tenant=<ID> --settings=<file> --dry
php artisan storefront:technology --tenant=<ID> --settings=<file>
```

## What "WordPress match" means, measured

Pulled from the live site's own generated Rey stylesheet (`wp-content/uploads/rey/hs-*.css`):

| Token | Rey source | Hex |
|---|---|---|
| `--accent-color` | `hsl(210,10%,23%)` | `#353B41` |
| `--accent-hover-color` | `hsl(210,11%,15%)` | `#22262A` |
| `--link-color` | `neutral-10` | `#000000` |
| `--body-color` | `hsl(210,9%,31%)` | `#484F56` |
| `neutral-1` (surface) | `hsl(210,17%,98%)` | `#F9FAFB` |
| `neutral-3` (line) | `hsl(210,14%,89%)` | `#DFE3E7` |
| `--primary-ff` | — | `"Helvetica Neue", Helvetica, Arial` (no webfont) |

Note this is Rey's **stock default palette** — no brand colour was ever set in the
theme customiser. Matching it reproduces an unconfigured theme, not a designed identity.

## What `wplayout` reproduces

The live homepage, section for section, in the same order:

| # | Live WordPress | Reproduced as |
|---|---|---|
| 1 | Hero slider | `hero` with `slides` (3-slide carousel) |
| 2 | "Get 5% discount by subscribing to our newsletter" | `newsletter` block |
| 3 | New Arrivals + product grid | `rail`, `sort: new` |
| 4 | "Smart Office Connectivity & Monitoring" | `banner`, tone light |
| 5 | WINDOWS OS + product grid | `rail`, Windows Operating Systems |
| 6 | "Unlocking the Power of Google Workspace" | `banner`, tone dark, right |
| 7 | Endpoint Security + product grid | `rail`, sort popular |
| 8 | Sophos NG Firewalls + product grid | `rail`, brand Sophos |
| 9 | "P-Series PBX System — Software Edition" | `banner`, tone accent |
| 10 | Network + product grid | `rail`, Network & Voice Hardware |

Then the technology extras their old site lacked: brands, solutions, services,
why-us, quote CTA, FAQ, contact.

### Two live sections deliberately NOT reproduced

Both are unedited Rey demo content, not this business:

- **The hero slider copy** — "Innovative, Wireless Home Speaker" / "Outstanding
  performance". A home-speaker advert on a cybersecurity site. Replaced with three
  slides built from their real catalogue (Sophos XGS, endpoint security, Microsoft/Google).
- **The closing gallery** — "New design." / "New Era." / "Create your soundscape" /
  "Long-lasting comfort". Headphone marketing. Dropped; a `gallery` block type exists
  if they want to add real content there.

No banner artwork exists to migrate (the slider images are demo assets too), so the
banners currently use real product photography from the catalogue. Supply proper
banner images and set `image` on each block.
