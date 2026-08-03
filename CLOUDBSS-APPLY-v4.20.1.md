# CloudBSS v4.20.1 — TenderBot for every tenant (Family Shoppers included)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.20.1.zip (cumulative — supersedes v4.20; 131 files) · **Migration:** none new. Changed: one file — `TenderAnalyzer.php`.

## Yes — TenderBot was multi-tenant from day 1

Nothing about it is DishNet-only: the **Settings → Tenders** group exists on every tenant, just switched OFF by default. And the South Sudan NGO Forum is arguably a better fit for Family Shoppers — many of those tenders are food, cleaning and household supply requests to Juba.

## What this patch adds

The division names the AI classifies into now **follow each tenant's own business profile**. Blank profile = the DishNet default (ISP / General Trading / Both / Ignore). A custom profile = the AI classifies into THAT profile's division names (or Ignore). So Family Shoppers can define "Food & FMCG / Household & Cleaning" and never see "ISP" in a report.

## Enable it for Family Shoppers (tenant 17) — 3 minutes

1. Panel (tenant 17) → **Settings → Tenders (TenderBot)** → **Watch for tenders: ON**.
2. **Business profile for classification** — paste (edit freely):

```
Division A (Food & FMCG Supply): rice, sugar, cooking oil, flour, salt, beverages, water, milk, tea, canned & dry foods, food supplies for NGOs/hotels/institutions.
Division B (Household & Cleaning): soap, detergents, cleaning chemicals, cleaning materials, utensils, household products, hygiene supplies.
Division C (General Supplies): stationery, office supplies, packaging materials, general wholesale supply & delivery to Juba and upcountry South Sudan.
```

3. **Company documents on file** — one per line with validity (tax clearance, trading license, TIN, bank statement…). The compliance check marks each tender's requirements against this.
4. Open **`/papi/tenders`** (signed in on tenant 17) → **Scan forum now** → summaries + task nudges arrive on the FS owner WhatsApp.

## Worth knowing

- Each enabled tenant watches the forum **independently** — the same IRSS tender gets analysed once per tenant, against that tenant's own profile (an ISP tender scores BID for DishNet and Ignore for FS). That also means AI cost is per tenant; the 3-per-run cap applies to each.
- Tasks, deadline escalations and RFQ generation all work identically for FS — tasks go to the FS owner's task inbox.

## Verified before ship

Harness both variants: blank profile → DishNet enum intact; custom FMCG profile → dynamic division rule + enum in prompt, profile text present, zero "ISP" leakage · php -l · zip md5-verified (132 files).
