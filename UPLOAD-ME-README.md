# Project Advisor — Slice 1: the deterministic Calculation Engine

This is the first piece of the **AI-powered Project Advisor** (solar first, reusable for other
industries later). It is the *technical core*: the deterministic sizing engine that turns a customer's
needs into engineering numbers a proposal can stand on. By design, **the AI will only ever explain these
numbers — it never computes or overrides them.**

## What this slice gives you

A pure, tested calculation engine that produces, from a discovery brief:

- **Connected load** and **critical load** (W)
- **Daily energy demand** (Wh/day and kWh/day)
- **Peak demand** (W) — including motor surge
- **PV array size** (W) and a suggested **panel count**
- **Battery bank** (Wh and Ah at the system voltage)
- **Inverter rating** (W and VA)
- with a **safety margin** and a **future-expansion allowance** applied, and an ordered **steps**
  breakdown (the working) for the AI narrative and the proposal.

Every assumption — sun hours, efficiencies, depth-of-discharge, system voltage, safety margin, power
factor, expansion — is configurable with sensible East-Africa defaults, so the same engine is reusable
and tunable per tenant or project without code changes.

## Why it's built this way (the architecture)

`SizingEngine` is a **generic contract**, not solar-specific. Solar is the first implementation
(`SolarSizingService`). To add generators, HVAC, water treatment, etc. later, you add another engine
that implements the same contract — the rest of the Advisor framework (discovery wizard, proposal
templates) reuses it unchanged. The engine is pure: same inputs always give the same outputs, no
database, no network, no randomness — which is exactly what makes the recommendations defensible.

This slice is **standalone and safe**: two new service files plus one test. Nothing existing is touched,
no database change, nothing is wired into a screen yet (that comes in later slices — Discovery Wizard,
then the Proposal Generator that reuses your quotation + PDF engine).

## Files in this zip (3)

**New**

- `app/Services/Advisor/SizingEngine.php` — the generic, reusable sizing contract.
- `app/Services/Advisor/SolarSizingService.php` — the deterministic solar sizing engine.
- `tests/Unit/Kernel/SolarSizingTest.php` — proves a full worked example, determinism, safety on empty
  input, surge handling, config overrides, and that the explainable steps are present.

## How to upload (GitHub, branch `main`)

Drag the `app` and `tests` folders into your repo at the same paths. All three are new files.

## How you know it worked

Green ✓ on **"Money kernel (must pass)"** — the new test runs there. (Before shipping, I also ran the
real engine against a worked home-system example: 300 W connected load, 1.83 kWh/day, ~586 W of panels,
~3.87 kWh battery bank, a 375 W / 469 VA inverter — all correct and repeatable.)

## What's next

With the numbers trustworthy, the next slices build on top: the **Discovery Wizard** (captures the brief
this engine consumes), then the **Proposal Generator** (assembles the proposal from brief + these
numbers + your Product Specifications, rendered through your existing quotation + PDF pipeline), then
**solution packages** and the **knowledge library**.
