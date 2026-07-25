# One small upload — makes the older failing tests show up clearly

This replaces a single file: `.github/workflows/tests.yml` (the automatic checker).
Nothing about the money-kernel check changes — it still decides your green ✓.

## What changed (and why it's safe)
I added a clean summary at the bottom of the "Full suite" step so the older tests
that still need attention are listed in one screenshot-able block. That step is
**informational only** — it literally cannot turn your check red. Your green
"Money kernel (must pass)" is untouched.

## What to do
Upload this `tests.yml` (keeping the `.github/workflows/` folder), replacing the
current one. GitHub re-runs automatically.

## What you'll see after it runs
1. Open the latest run under the **Actions** tab.
2. **Green ✓ "Money kernel (must pass)"** = the new money work is still verified. (Same as before.)
3. Click into the run → open the step **"Full suite (informational only)"** and scroll to the bottom.
   You'll see a block like:

       ======================================================================
          FULL-SUITE RESULT  —  screenshot from this line down and send to me
       ======================================================================
       Tests:  X failed, Y passed
       ----------------------------- FAILING --------------------------------
       FAIL  Tests\Feature\SomeOldTest
       ...

**Screenshot that block and send it to me.** That's the exact list of older tests
to tidy up, and I'll fix them in one batch.

(If you prefer: the run also has a downloadable "full-suite-log" file under
"Artifacts" at the bottom of the run page — you can upload that to me instead of a screenshot.)
