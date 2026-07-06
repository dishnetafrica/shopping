# ShopBot update — real-world hardening batch

Extract at the repo root; commit & push, then run the tinker block below.

## Code changes in this update
- AiBrain: HIGH-VALUE QUOTE ALERT — when a quotation total >= settings.quote_alert_threshold
  (in the quote currency; 0 = off), the sales/management team gets a WhatsApp ping to follow up.
- AiBrain: ADVISORY SAFETY caveat — technical/sizing/diagnosis advice is stated as INDICATIVE and
  defers final selection + installation to a licensed professional (protects the shop). Shared
  prompt — every tenant benefits; no persona edit needed.
- (from prior update) QuotationService VAT-inclusive mode, expert AI, photo/voice, special order.

## Settings to run on the Patronics tenant (tinker) — MUST-FIX batch
    $t = \App\Models\Tenant::find(7);

    // 1) No blanket discount. 20% was only Speke Hotel's negotiated rate — remove the auto-discount.
    $t->putSetting('quote_discount_pct', 0);

    // 2) Prices include VAT (fixes double-VAT on quotes)
    $t->putSetting('prices_include_vat', true);
    $t->putSetting('quote_vat_pct', 18);

    // 3) Salesperson
    $t->putSetting('sales_employee', 'Vimal Patel');
    $t->putSetting('quote_prepared_by', 'Vimal Patel');

    // 4) Alert the team on big quotes (UGX). 1,000,000 = ping on any quote >= 1M.
    $t->putSetting('quote_alert_threshold', 1000000);
    // make sure lead_recipients / alert_routing has a 'sales' or 'management' number, else no one is pinged.

## AUDIT — paste this ONE line in tinker to see everything at a glance
    $t=\App\Models\Tenant::find(7); foreach(['currency','currency_basis','vertical','prices_include_vat','quote_vat_pct','quote_discount_pct','quote_alert_threshold','sales_employee','quote_prepared_by','special_order_enabled','ai_catalogue_limit'] as $k){$v=$t->setting($k); echo str_pad($k,24).': '.(is_bool($v)?($v?'true':'false'):(($v===null||$v==='')?'(null)':$v))."\n";} echo 'ai_persona: '.($t->setting('ai_persona')?'SET':'(NULL)')."\n";

Expect (Patronics): currency UGX, prices_include_vat true, quote_vat_pct 18, quote_discount_pct 0,
quote_alert_threshold 1000000, sales/prepared_by Vimal Patel, special_order_enabled true, ai_persona SET.
If currency is NOT UGX, STOP and fix it first — a USD setting would quote UGX prices as dollars.

## Note on stock
Left as-is (10 per item) per your call. Be aware the bot may say "10 in stock" for items you
order-in; if a customer is told "in stock" and it isn't, that's a broken promise. When ready,
set stock to null/unknown so the bot says "usually available - I'll confirm" instead.
