# Solar Experience Center — v4: Prohauz theme restyle

Restyles the whole customer-facing Solar Experience Center to match your purchased **Prohauz "Solar"**
theme — the green + charcoal look, Montserrat/Roboto fonts, hero-with-checklist, themed cards, a
"Free call 24/7" button, and a dark footer contact band. **All the interactive features are unchanged** —
the appliance calculator, live recommendation, "why this system", package price, lead capture, the animated
"How Solar Works" diagram, and the Knowledge Center all work exactly as before, just under the new skin.

**Cumulative** — includes the whole solar platform so far (Advisor, assessment funnel, Knowledge Center,
animated How-Solar-Works). Upload just this; it supersedes the earlier zips.

## What changed — the look

- **Palette:** brand green `#1cb22a`, charcoal `#252525`, soft yellow accent `#dad674` (your theme's colours),
  replacing the old amber look.
- **Fonts:** Montserrat for headings, Roboto for body — loaded from Google Fonts, exactly like the theme.
- **Hero:** full-width photo hero with a dark overlay, a headline, a 3-point checklist, and green call-to-action
  buttons. A green **"Free call 24/7"** button sits in the top bar (links to your WhatsApp number when set).
- **Sections & cards:** white cards with green hover/active accents, green section labels, a charcoal package
  band, and a dark footer contact bar with quick links — the theme's structure.
- Applies across all three pages: the **assessment** (`/gsl/solar`), **How Solar Works** (`/gsl/how`), and the
  **Knowledge Center** (`/gsl/learn`).

## The one image to upload (optional but recommended)

The pages look for a single hero photo at:

```
public/img/solar/hero.jpg
```

Drop your theme's hero image (or any of your own solar photos) there and it appears behind the hero on all
three pages. **Until you upload it, the hero shows a clean green gradient instead** — nothing breaks, the page
just isn't showing a photo yet. Recommended size: a wide landscape image, ~1920×1080.

> On theme images: use your own photos or images you're licensed to use. I intentionally did **not** hard-link
> the theme vendor's demo images — those aren't licensed for a live business site and the links would break.

## Files in this zip

**Restyled**

- `resources/storefront/solar.html` — the assessment page, re-skinned to the theme.
- `resources/storefront/how.html` — the animated "How Solar Works" page, re-skinned.
- `app/Http/Controllers/Storefront/StorefrontLearnController.php` — the Knowledge Center shell, re-skinned.

**Carried** — the full Advisor backend, assessment funnel, Knowledge Center, animated How-Solar-Works, the V3
staff screen, routes, and all earlier tests.

## How to upload (GitHub, branch `main`)

Drag the `app`, `bootstrap`, `database`, `resources`, `routes`, `tests` folders/files into your repo at the same
paths. (Optionally also add `public/img/solar/hero.jpg` with your hero photo.)

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** (the `/how` and `/learn` render tests still pass unchanged). Then open
`mycloudbss.com/gsl/solar`, `mycloudbss.com/gsl/how`, and `mycloudbss.com/gsl/learn` — you'll see the new green
theme, and the calculator, animation and knowledge center all still work.

## Next in the Experience Center

4. **ROI dashboard + before/after + case studies** · 5. **Solar Academy + on-page AI assistant** ·
6. **Richer product pages.** Say "next" for the ROI + savings layer.
