# 3 — Event Catalog

**Status:** DRAFT for approval · Layer 3. An **event** is an immutable fact about a **business
object**: "this happened to that object, at this time, by this actor." Events are history, not state
— the object (layer 1) still holds its own current state. Processes (layer 2) *emit* events;
projections (layer 4) *read* them. No object ever calls another; they communicate only through events.

> **`Already?`** 🟢 your code already does this (in an observer/job/service) and just needs the event
> *named + written to the unified log*; 🟡 partial; 🔴 new. Most are 🟢 — adoption is naming, not building.

## Envelope (shared by every event)
```
event_id        uuid           (client-generated when created offline)
tenant_id       int
name            string         "PaymentReceived"        ← CONTRACT (approval to rename)
subject         {type,id}      the OBJECT it happened to → drives every timeline
occurred_at     ts             device/real-world time
recorded_at     ts             server accept time
actor           {type,id,name} user | bot | system | customer
transition      {from,to}?     the object state change this records, if any
payload         json           event-specific fields    ← CONTRACT (approval to rename/remove)
idempotency_key string         offline retry never double-writes
```
`subject` = the object. Customer timeline, order history, product history are all one query:
`events WHERE subject={type,id} ORDER BY occurred_at`.

---

## Customer & relationship
| Event | Producer (process) | Payload | Subscribers → effect | Already? |
|---|---|---|---|---|
| `LeadCaptured` | Sell/P1 | source, name, phone | timeline start · AI queue | 🟡 |
| `CustomerCreated` | Sell/P1 | customer_id, name, phone, region | timeline start · workspace subject | 🟢 |
| `CustomerVisited` | Sell/P2 | customer_id, geo, note | timeline · "visited today" · follow-up clock | 🔴 |
| `CustomerCalled`/`Messaged` | Sell/P4 | customer_id, channel, direction | timeline | 🟡 |
| `CustomerEscalated` | Support/P9 | customer_id, reason | push owner · Chats flag | 🟢 (`follow_up=ESCALATED:`) |
| `FollowupScheduled`/`Completed` | Sell/P4 | customer_id, due_at, reason | Today activity | 🟢 (`FollowupProposal`) |
| `CustomerMerged` | admin | survivor, merged | re-point history | 🔴 |

## Quote → Order
| Event | Producer | Payload | Effect | Already? |
|---|---|---|---|---|
| `QuoteCreated/Edited/Sent` | Sell/P3 | quote_no, lines, currency, total | timeline · PDF · close-score | 🟢 |
| `QuoteAccepted/Rejected` | Sell/P3 | quote_no, reason? | timeline · AI learns | 🟡 (status) |
| `QuoteConverted` | Sell/P3 | quote_no, order_id | creates order | 🟢 |
| `OrderConfirmed` | Sell/P3 | order_id, lines, total, sell_currency | reserve stock · notify owner+customer | 🟢 (`OrderObserver`) |
| `OrderStatusChanged` | Deliver/P6 | order_id, from, to | notify customer · maybe auto-invoice | 🟢 |

## Delivery
| `DeliveryDispatched` | Deliver/P6 | order_id, driver | "in transit" · nudge | 🟢 |
| `GoodsLoaded` | Deliver/P6 | order_id | timeline | 🟡 |
| `DeliveryCompleted` | Deliver/P6 | order_id, geo | triggers `AutoIssueInvoice` | 🟢 |
| `PODUploaded` | Deliver/P6 | order_id, image | attach to timeline | 🔴 (camera) |

## Invoice → Payment → Receipt
| `InvoiceIssued` | Sell/P3, Deliver/P6 | invoice_no, order_id, total, currency, balance | AR · "Waiting for Payment" | 🟢 |
| `InvoiceSent/Voided` | Sell | invoice_no | timeline · reverse AR | 🟢 |
| `PaymentReceived` | Collect/P7 | receipt_no, amount, currency, usd, invoice/order | **cashbook + invoice + ledger + collections** | 🟢 (fan-out exists) |
| `ReceiptGenerated/Sent` | Collect/P7 | receipt_no | timeline · closes loop | 🟢 (just shipped) |
| `CreditNoteIssued` | Support/P8 | cn_no, invoice_no, amount | −AR | 🟢 |

## Purchase → Stock
| `PurchaseOrdered` | Buy/P5 | po_id, supplier, lines | payable · "Items to Receive" | 🟢 |
| `StockReceived` | Buy/P5 | product_id, qty, cost | on-hand up · low-stock clears | 🟢 (`ProductStockObserver`) |
| `StockCounted/Adjusted/Transferred` | Buy/admin | product_id, delta | inventory · audit | 🟡/🟢 |
| `LowStockDetected` | Buy/P5 | product_id, on_hand | Today reorder activity | 🟢 |
| `PriceChanged` | admin | product_id, old, new | audit (price-drift) · re-quote | 🟢 |
| `SupplierPaid` | Buy/P5 | supplier, amount | cashbook | 🟢 |

## Money / cash
| `ExpenseRecorded/Approved` | Admin/P10 | amount, currency, category | cashbook · P&L | 🟢 |
| `CashTransferred` | Admin/P11 | from, to, amount, currency | two cashbook legs | 🟢 (CB-0201/0202) |
| `CashEntryVoided` | Admin/P11 | entry_id, reason | reverse live balance, keep audit | 🟢 |
| `CurrencyConverted` | Admin/P11 | from, to, rate | two legs | 🟢 |

## Messaging & returns
| `MessageReceived` | inbound | customer, body, media | bot · timeline · "replied" push | 🟢 |
| `BotReplied/BotQuoted` | bot | customer, kind | timeline | 🟢 (`bot_events`) |
| `CampaignSent` | marketing | campaign_id | timeline · analytics | 🟢 |
| `WhatsAppLineDown/Restored` | watchdog | since | top-of-brief alert | 🟢 (`wa_status`) |
| `ReturnInitiated/Approved/Completed` | Support/P8 | return_id, lines | restock + credit note | 🔴 |

---

## Cross-cutting subscribers
- **Timelines** — any event with `subject` appends one human line (customer/order/product).
- **AI orchestrator** — reads events to build Today, risk, next-best-action; emits only
  `FollowupScheduled` + suggestions; **never money numbers.**
- **Accounting** — payment/expense/transfer/invoice/credit keep cashbook + AR + P&L correct.
- **Inventory** — order/stock/delivery/return move on-hand.
- **Notifications** — pushes are event subscriptions with a device target.
- **Insights** — every report is an aggregation over events (no separate reporting store).

## Decisions to freeze (consistent with object-first)
1. **Event-logged** (objects keep state; events are history) — recommended, your stated preference.
2. **One unified `events` table** (recommended) vs the per-domain tables today.
3. **Keep events forever** (recommended).
