# ADR-0001 — Operational Timeline

**Status:** Accepted (design only — not implemented). Respects the platform freeze: this ADR adds
no engine, table, migration, or event bus. It defines a *contract* so that, when a future
capability genuinely needs to participate, it emits the same shape.

## Context
The events already exist — they're just scattered across subsystems, each in its own store:
`knowledge_events`, `shadow_diffs`, `order_flow_metrics`, `BotTrace`, order status changes,
`customer_confirmed_at`, seller stale-nudges, replay promotions. What's missing isn't
infrastructure (Kafka/queues); it's the ability to read them as **one chronological story** and
answer a business question:

> "What happened to this order / product / customer?" — not "which table do I query?"

## Decision
Define a single **event envelope** every subsystem can map onto, without moving its data. The
timeline is a *read* concept assembled from adapters; each subsystem keeps its own storage.

### Event envelope
```
event_id      uuid          stable, unique per event (idempotent re-emit safe)
event_type    string        dotted, past-tense: "order.delivered", "inventory.counted",
                            "customer.confirmed", "seller.nudged", "shadow.diff_recorded"
entity_type   string        "order" | "product" | "customer" | "conversation" | ...
entity_id     string        id of that entity within the tenant
tenant_id     int           always tenant-scoped
actor         string        who/what caused it: "customer" | "seller" | "rider" | "bot" |
                            "system:watchdog" | "system:observatory"
source        string        emitting subsystem: "order_engine" | "tracking_page" | "oke" |
                            "observatory" | "watchdog"
occurred_at   datetime      when it actually happened (not when ingested)
payload       json          small, event-specific facts (no PII beyond what the entity already holds)
```

### Identity & idempotency
`event_id` is the dedup key. Re-emitting the same logical event (a replay, a retry) carries the same
`event_id` and is ignored on read. Adapters derive a deterministic `event_id` from
`source + entity + natural key` where possible.

### Entity reference
`(tenant_id, entity_type, entity_id)` is the join key. A timeline query is "give me all events for
this entity, ordered by `occurred_at`."

### Actor vs source
- **actor** answers *who* (customer / seller / rider / bot / a named system process).
- **source** answers *which subsystem produced the record*.
Both are kept because "the watchdog (source) recorded that the seller (actor) was nudged" is
different from "the seller (actor) updated status via the panel (source)".

## Mapping — existing scattered sources → timeline (adapters, not migrations)
| Subsystem / store        | event_type examples                          | actor            | source        |
| ------------------------ | -------------------------------------------- | ---------------- | ------------- |
| order status changes     | order.preparing / order.dispatched / order.delivered | seller/rider | order_engine  |
| `customer_confirmed_at`  | customer.confirmed_delivery                  | customer         | tracking_page |
| rating fields            | customer.rated                               | customer         | tracking_page |
| stale-nudge fields       | seller.nudged                                | system:watchdog  | watchdog      |
| `delivered_by=customer`  | order.self_closed                            | customer         | tracking_page |
| inventory confidence     | inventory.counted / inventory.unknown        | seller/system    | inventory     |
| `knowledge_events` (OKE) | owner.fact_recorded                          | owner            | oke           |
| `shadow_diffs`           | shadow.diff_recorded / shadow.promoted       | system           | observatory   |
| `order_flow_metrics`     | (aggregate; not per-event — stays as metrics)| system           | observatory   |

Most rows already carry a timestamp and an entity id; an adapter is a read-only projection that
yields the envelope. No source-of-truth moves.

## Consequences
- A future "order timeline" view, a unified WhatsApp operations feed, or cross-subsystem analytics
  can be built by adding adapters — never by rewriting subsystems.
- Capabilities (picking, dispatch, rider participation) emit the same envelope when their evidence
  arrives, so they join the timeline for free.
- Until then: **nothing is built.** This document is the only deliverable; it exists so the
  eventual unification is intentional rather than accidental.

## Non-goals
No event bus, no broker, no central event table, no synchronous coupling between subsystems, no
change to any existing store. Those are explicitly out of scope and would require their own ADR with
production evidence that the adapter-on-read approach is insufficient.
