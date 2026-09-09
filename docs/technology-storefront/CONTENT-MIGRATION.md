# Secure Solutions content — what was migrated, and what was deliberately left behind

Harvested from the WordPress mirror into
`tenant-config/secure-solutions-settings.json`. Raw extraction in
`secure-solutions-harvested-content.json`.

## Migrated (genuine business content)

| Content | Volume | Where it lands |
|---|---|---|
| FAQ | **12 Q&A pairs** | `tech.faq` — homepage FAQ section + `FAQPage` JSON-LD |
| Refund and Exchanges Policy | 27 blocks | `tech.pages.refund` |
| Terms & Conditions | 38 blocks | `tech.pages.terms` |
| Privacy Policy | 38 blocks | `tech.pages.privacy` |
| Phone numbers | 4 (UG ×2, UAE, KE) | `tech.contact.phones` — utility bar + footer + CTA |
| Emails | 2 | `tech.contact.emails` |
| Postal address | 1 | `tech.contact.address` |
| Categories | 9 | nav, tiles, rails, footer — *derived from the catalogue, not copied* |
| Brands | 16 | brand strip — *derived from the catalogue* |
| Product descriptions | 173 | already imported with the catalogue |

The FAQ is the strongest asset on the old site: real, specific answers about
licence-key delivery, digital refunds, hardware warranty and support. It carries
over verbatim.

## Deliberately NOT migrated

These are unedited **Rey theme demo content** — they belong to the theme's fashion
demo, not to this business. Migrating them would have published false claims.

| Page | What it actually contains |
|---|---|
| **About Us** | Lorem ipsum body ("Volutpat arcu nulla facilisi pellentesque…", "Maecenas id euismod arcu…"), plus fabricated stats: *"Est. 2012 · 2,400 Employees · 9 Store locations · Worldwide Delivery"* and *"EXCELLENCE IN INNOVATION SINCE 2010"* — which also contradicts the "Est. 2012" line beside it. |
| **Showrooms** | Three **Canadian** addresses — Victoria BC, Scarborough ON, Lafontaine QC — with `@tech-store.com` emails and `(250) 590-57xx` phone numbers. |
| **Contact page prose** | *"handpicked for their understanding of materials, process and passion for fashion"*, *"our makers and their craft"*, and a demo phone number `778 568 999`. The real phone numbers and address on that page **were** kept. |

**No About page or Showrooms page is configured.** Write real copy and add it as
`tech.pages.about` (and a showrooms block) when the business supplies it. The
storefront simply omits sections that have no content.

## Written for the storefront, and on what basis

The hero, trust strip, why-us, solutions and services blocks are new copy — the
old site had no usable equivalent. Each is grounded in what the catalogue
actually contains, and nothing claims a capability with no products behind it:

- Hero stats are **computed**: 173 products, 16 brands, 9 categories.
- "Endpoint & server security" names Sophos, Bitdefender, ESET, McAfee, Avast,
  AVG — all 16 brands are really in the catalogue.
- Delivery and warranty wording is taken from the FAQ's own answers
  ("delivered electronically", "timelines confirmed on order",
  "manufacturer's warranty").
- Nothing asserts an office count, staff count, founding year, certification or
  partner status, because the source has no truthful figure for any of them.

Review this copy before go-live — it is a reasonable draft written from the
catalogue, not the client's own approved marketing.

## Catalogue observations worth acting on

- **No computers or laptops exist.** The catalogue is licences, security and
  network/voice hardware. The generic `hardware` rail is therefore re-pointed at
  *Security & Infrastructure* (94 products) with an honest title rather than left
  empty or pointed at "Windows Operating Systems", which is software. Add a real
  computers category and repoint it.
- **Category depth is uneven** — Security & Infrastructure holds 94 of 173
  products, Digital Certificates holds 3. Subcategories would make browsing
  easier.
- **5 source categories are empty** and were never created: `access-control`,
  `accessories`, `computer-software`, `servers-storage`, `uncategorized`.
- **87 photos serve 173 products** (the source reuses them across licence
  variants), so roughly 60% of cards share an image with a sibling.
- **Specifications are empty.** The storefront renders ShopBot `ProductSpec`
  data when present and falls back to licence facts parsed out of the product
  name (duration, seats/devices, perpetual). Seed spec definitions via
  `POST /papi/spec-definitions` to get a real specifications table.
