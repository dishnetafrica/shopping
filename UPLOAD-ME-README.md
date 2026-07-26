# Solar Experience Center — v2: the Knowledge Center (your SEO engine)

Adds a public, server-rendered **Knowledge Center** — educational articles and FAQs that build trust,
answer the questions first-time buyers actually ask, and bring organic search traffic. Every page guides
the reader toward the 3-minute assessment.

**Cumulative** — this bundle includes the whole solar platform (internal Advisor + the v1 assessment
funnel). Upload just this; it supersedes the earlier zips.

## What customers get — `mycloudbss.com/<shop>/learn`

- A hub of educational articles grouped by category (Basics, Batteries, Cost & savings, Technical,
  By industry, Local) plus an FAQ accordion (reused from your knowledge library).
- Each article on its own URL (e.g. `/<shop>/learn/how-solar-works`), **server-rendered with a unique
  title, meta description, canonical link and Open-Graph tags** — so Google can index every one.
- 12 articles to start: How Solar Works · Do I Need a Battery · Hybrid vs Off-Grid · Battery Guide ·
  Solar vs Generator · Can Solar Run My AC · How We Size Your System · Solar for Schools · Solar for
  Clinics · Solar for Farms · 5 Solar Myths · Solar in Uganda. Adding more is just data.
- A "Learn how solar works" link on the assessment hero, and every article/hub funnels to the assessment.

Why it matters: research on solar adoption shows people are far more likely to buy once they *understand*
the benefits. This turns education into both trust and traffic — and adds no maintenance burden.

## Public & SEO-correct

Server-rendered HTML (not a JS app), so crawlers get real content with proper meta on each page. Public,
shop-scoped, no login. Add articles by editing one data file (`SolarArticles`).

## Files in this zip

**New**

- `app/Services/Advisor/SolarArticles.php` — the article content.
- `app/Http/Controllers/Storefront/StorefrontLearnController.php` — the server-rendered hub + article pages.
- `tests/Unit/Kernel/SolarArticlesTest.php`, `tests/Feature/Kernel/StorefrontLearnTest.php`.

**Replace**

- `routes/web.php` — the public `/<shop>/learn` routes.
- `resources/storefront/solar.html` — adds the "Learn how solar works" link.

**Carried** — the complete Advisor backend, the v1 assessment funnel, the V3 screen and earlier tests, so
this works standalone.

## How to upload (GitHub, branch `main`)

Drag the `app`, `bootstrap`, `database`, `resources`, `routes`, `tests` folders/files into your repo at the
same paths.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** (the hub and an article's SEO tags are tested). Then open
`mycloudbss.com/gsl/learn`, browse an article, and confirm each has its own title in the browser tab.

## Next in the Experience Center

3. **Animated "How Solar Works" + comparisons** · 4. **ROI / before-after / case studies** ·
5. **Solar Academy + on-page AI assistant** · 6. **Richer product pages.** Say "next" for the next one.
