# CloudBSS Mobile Design System — FROZEN v1.0 (2026-07)

Reviewed at 9.2/10 and frozen. From here we build workflows + interactions, not new visual styles.
Every new mobile screen uses these tokens verbatim. Changing a token is a design-system decision, not a
per-screen one.

## Color
```
--bg           #F6F7F9    app background
--surface      #FFFFFF    cards
--ink          #0C1420    primary text
--ink-2        #37424F    secondary text
--muted        #8B95A3    meta / labels
--faint        #B4BCC7    disabled / hairline text
--line         #EFF1F5    dividers / rails
--brand        #12A150    primary green
--brand-deep   #0A7A3C    gradient end / pressed
--brand-wash   #E9F7EF    green tint
--amber        #DD8500    money-due accent (outstanding, discount)
--amber-wash   #FCF4E4
--red          #E24B3B    overdue
--red-wash     #FDEDEB
--indigo       #6C5CE7 · --sky #2C97DE   avatar / event-type accents
Gradients: brand = linear-gradient(140–155deg, #15AC57 → #0A7A3C)
```

## Type scale (Inter, letter-spacing −0.012em)
```
Hero number   38   / 860   (day-progress, big amounts use 40)
Screen title  22   / 840
Section       17   / 820
Card title    15.5 / 780
Body          14.5 / 700
Caption       13   / 650
Meta          11.5 / 650
Micro-label   10.5 / 700 (UPPERCASE, +0.04em)  e.g. OUTSTANDING
```

## Radii scale
```
--r-card    24    cards, hero, payment card
--r-btn     16    primary buttons
--r-input   14    inputs, quick-action tiles
--r-av      18    avatars
list-row / pill   20 / 999
icon-chip         11–14
```

## Elevation (soft, layered — "Apple Wallet", never hard borders)
```
--e1  0 1px 2px rgba(12,20,32,.04), 0 4px 12px -4px rgba(12,20,32,.06)   list rows, chips
--e2  0 2px 6px rgba(12,20,32,.05), 0 16px 30px -12px rgba(12,20,32,.12) payment card, hero
brand-glow  0 12–16px 22–30px -8/-10px rgba(10,122,60,.5–.6)             green CTAs + hero
```
Cards use shadow + a white surface, NOT a 1px border.

## Spacing
4 · 8 · 12 · 14 · 18 · 22 · 24 — screen padding 18; card padding 14 (rows) / 18–20 (feature cards);
section gaps 20–22; row gaps 10–11.

## Icons
ONE outline family only (Lucide-style, 1.8 stroke, round caps). No emoji as functional icons.
The greeting wave and status dots are the only decorative exceptions.

## Components (canonical)
- **Hero (Today):** brand gradient card, day-progress — Remaining big, progress bar, "X collected of Y".
- **List row (customer):** avatar (colored initial) · status dot (red overdue / amber due / green upcoming)
  · title · context line (INV · due-label · city) · right: amount + OUTSTANDING micro-label. Whole row taps.
- **Payment card (Stripe-Terminal):** label → big amount (editable) → invoice meta → segmented method →
  discount toggle → full-width primary "Receive USD X". The button is the destination.
- **Stepper:** slim; done = filled green + ✓, current = amber ring, todo = grey. Adaptive (hide quote steps
  when there was no quote). Payment stage labelled "Payment", never "Paid".
- **Timeline:** soft tinted icon-chip per event type, title, amount pulled out big, meta line, connecting rail.
- **Bottom nav:** 5 items + centered raised FAB (brand gradient). Icons 22–23px, labels 10px.
- **Sync pill:** 🟢 Synced / 🟡 N queued / offline — small, top-right, unobtrusive.

## Interaction language (the next investment — see backlog)
Page slide transitions · card :active scale(.985) · skeletons while loading · success animation after a
payment (✓ + amount + "Receipt sent") that then updates Today · swipe actions (call/navigate/done) ·
pull-to-refresh · Android haptics. These, not more visual restyling, are where the remaining polish lives.
