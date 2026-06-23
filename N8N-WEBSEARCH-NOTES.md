# Scoped web search for the smart bot

The AI Agent now has a **Web Search** tool — but fenced so it stays on-brand and never touches prices.

## What changed
- **n8n**: added a `Web Search` tool node (SerpApi) wired to the agent via `ai_tool`. The Brain's
  prompt scopes it hard:
  - Use web search ONLY for things NOT in COMPANY KNOWLEDGE / FAQ / PRODUCTS (general or current
    real-world facts).
  - NEVER for our prices, specs or stock — those come from the catalogue.
  - At most one search, brief answer, then steer back to helping.
- **Per-tenant on/off**: CloudBSS sends `web_search` (admin toggle "Allow web search", default on).
  When off, the prompt tells the agent not to use the tool at all.

## Bind the search provider (one step on import)
The tool uses **SerpApi**. Open the `Web Search` node → set Credential to a SerpApi account
(free tier ~100 searches/mo at serpapi.com). That's the only new setup.
Prefer Tavily / Brave / Google CSE instead? Tell me and I'll swap the tool node — the prompt scoping
stays the same.

## Why this is safe (not Pal's-style risk)
Prices/specs stay grounded in the catalogue; the model can't quote a web price. Web search only adds
breadth for off-catalogue questions, and the per-tenant toggle lets you turn it off entirely.

## Files
- `app/Jobs/ProcessIncomingMessage.php` — sends `web_search` flag.
- `app/Filament/Admin/Resources/TenantResource.php` — "Allow web search" toggle.
- `cloudbss-smart-bot.n8n.json` — Web Search tool + scoped prompt rule.

## Deploy
Pull → restart → `optimize:clear` (no migration). Re-import the workflow, bind OpenAI **and** SerpApi
credentials, Save + Activate. Test:
- "what GSM is good for a laser printer?" → answers (general knowledge, maybe a search), then offers our A4 80 GSM.
- "what's today's USD to UGX rate?" → may web-search.
- "price per carton of 300-sheet?" → catalogue only, no search.
