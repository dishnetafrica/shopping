# CloudBSS Engineering Policy v1

> **Production is the specification. Replay tests are the contract.**
>
> - Architecture explains *why*.
> - Production conversations show *what actually happens*.
> - Replay tests define *what must never break again*.
>
> When they disagree, **production wins**.

This is an engineering *policy*, not a technical spec. Treat it as seriously as the coding
standards. It governs the Order Engine, OKE, and the operating layer (shadow mode, Observatory,
replay corpus).

---

## Platform status

**Architecture is frozen.** The system has three sufficient layers:

1. **OKE (Owner Knowledge Engine)** — how the business learns and updates itself.
2. **Order Engine** — how customer conversations become structured draft orders.
3. **Operating System** — corpus harness, Observatory, shadow mode, experiment framework, replay.

Structural changes require sustained production evidence. The burden of proof is on production,
not speculation.

## Allowed changes

Every change must trace to one of:

- a production **shadow difference** (`shadow_diffs`),
- an **Observatory metric** (`order_flow_metrics` / `orders:observatory`),
- a **replay regression** (`tests/Fixtures/order_replays.json` / `ProductionReplayTest`).

If none exists, the change is **deferred**.

## Development loop

```
Production conversation
        ↓
Shadow comparison        (OrderObservatory → shadow_diffs)
        ↓
Human review             (orders:diffs → ReviewService; Confidence Ladder)
        ↓
Replay test              (promoteToReplay → ProductionReplayTest)
        ↓
Code change              (the one category being fixed)
        ↓
Metric improvement       (orders:observatory: engineering + business)
        ↓
Production verification   (ladder stage → verified)
```

## Success criteria

A change is **complete** only when:

- replay tests pass,
- the shadow disagreement rate improves (or holds) for that category,
- business metrics do not regress,
- production confirms the improvement (the diff reaches ladder stage `verified`).

## Replay rule

**Every production bug becomes a replay case.** No bug is fixed without a permanent regression
test added to the corpus.

## Measurement first

Engineering work is prioritized by production metrics, not intuition. The Observatory's
disagreement-reason breakdown and the business-metric impact determine what gets fixed next —
one category at a time.

## Freeze rule

No new engines, pipelines, architectural layers, or abstractions are introduced without sustained
production evidence that the current architecture **cannot** satisfy the requirement.

---

## Appendix — the weekly report (automate after a few weeks of data)

The weekly report must answer exactly three questions. If it cannot, improve the measurements
before writing more code.

1. **What got better?** (shadow disagreement ↓, clarification turns ↓, completion rate ↑)
2. **What is the single biggest remaining problem?** (e.g. weight variants, "Gathiya" ambiguity,
   address parsing)
3. **What is the next replay test we should add?**

### Scorecard fields (already available in code)

- **Engineering**: shadow disagreement rate, clarification rate, reason breakdown, ladder stages,
  parser precision*, resolver confidence calibration*.
- **Business**: orders completed, avg clarification turns/order, avg time-to-confirmation,
  manual interventions, abandonment rate.
- **Learning**: new replay cases, approved aliases (`ClarificationLogger::aliasProposals`),
  top unresolved disagreement categories (`ladderBreakdown.unexplained`).

\* parser precision and confidence calibration fill in once a week of human-reviewed labels exists.

---

*v1 — adopted at the transition from building the system to operating it.*
