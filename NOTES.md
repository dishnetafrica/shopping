# Phase 1 — Brain Migration (notes)

Deterministic conversational brain ported from the production n8n workflow.
**Scope: Phase 1 only** — CatalogueMatcher, ShoppingParser, Clarification flow.

## Files
```
app/Services/Bot/CatalogueMatcher.php   NEW  synonyms, stopwords, units, Damerau fuzzy (+first-char guard), category, clarify-on-price-spread, token cache
app/Services/Bot/ShoppingParser.php     NEW  multi-item split (comma/and/&/+/newline), qty-any-position, run-on lists, edit-verb defer, browse vs add
app/Services/Bot/ClarificationFlow.php  NEW  numbered options + numeric/name selection
app/Services/Bot/ShoppingEngine.php     NEW  orchestrator (pure, framework-free), catalogue-aware split, off-topic defer
app/Services/Bot/BotBrain.php           EDIT keywordRespond() -> engine; +tenantCatalogue()/currencyFor()
tests/Unit/ShoppingEngineTest.php       NEW  11 tests (8 examples + selection)
```

## Behaviour rule
A verb or a quantity => ADD. Bare word/list, question, or "show me" => SHOW (browse).
Edit verbs (remove/make/change/double/half/ordinals) are detected and DEFERRED
(Phase 2) so the cart is never wrongly modified.

## Scope boundaries (Phase 2, frozen)
Cart-edit engine (remove/advanced edits/replacements), reorder, checkout flow
(name + location), escalation, per-tenant AI metering, tenant-aware outbound queue.
