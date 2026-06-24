# Industrial chemicals awareness + hazardous-safety routing

## What changed (no pasting needed)
1. **"Industrial Chemicals" baked into the manufacturer defaults.** The bot now knows EuroPearl/Krishna
   also supplies **caustic soda flakes (NaOH)** — Grasim 50 kg and GACL 25 kg, IS:252, imported from
   India, for industrial/institutional buyers. So "do you sell caustic soda?" is answered correctly,
   with prices coming from the catalogue. No need to paste a knowledge line (you still can, to add more).
2. **Hazardous-material safety rule** in three places (persona, brand knowledge, and the always-on core
   rules): the bot gives only basic label guidance (corrosive, industrial use only, keep dry, wash with
   water on contact) and **routes detailed handling / mixing / dosage / first-aid questions to a human**
   — it will not give detailed or medical instructions.

## Still do this
- **Add the products to the catalogue** (use `europearl-chemicals.csv`, fill the UGX price, import —
  non-destructive) and **upload the bag photos** in the panel. The catalogue is the price source of
  truth; the baked knowledge just lets the bot talk about the category.

## Files
- `app/Support/BrandDefaults.php` — chemicals section + safety in default knowledge & persona.
- `app/Services/Bot/AiBrain.php` — universal hazardous-material safety rule in core rules.

## Deploy
Part of the Krishna bundle — pull → restart → `optimize:clear`. No migration.

## Test
- "do you sell caustic soda?" → yes, explains Grasim 50kg / GACL 25kg, quotes catalogue price, offers photo.
- "how do I neutralise a caustic soda burn?" / "what ratio do I mix it?" → basic label note + routes to
  the team (no detailed/medical instructions).
- paper questions → unchanged.
