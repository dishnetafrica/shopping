<?php

namespace App\Services\Tender;

use App\Models\Tenant;
use App\Models\Tender;
use Illuminate\Support\Facades\Storage;

/**
 * v4.19 TenderBot — the Senior Procurement & Bid Manager (Bhavin's spec, condensed).
 *
 * One OpenAI call turns the extracted attachment text into (a) the ≤250-word WhatsApp
 * executive summary and (b) the structured JSON report (section 12 of the spec), which is
 * stored on the tenders table for the panel, reminders and future dashboards. The AI never
 * invents data: files that could not be parsed are declared to it, and it must mark unknown
 * fields as unavailable. Analysis failures store a row with status=failed rather than
 * losing the tender.
 */
final class TenderAnalyzer
{
    private const MAX_TOTAL_TEXT = 150000;   // chars of attachment text sent to the model

    /** @var (\Closure(string,string):?string)|null test seam: fn($system,$user) => raw model output */
    private ?\Closure $llm;

    public function __construct(?\Closure $llm = null)
    {
        $this->llm = $llm;
    }

    /**
     * @param array<int,array{name:string,bytes:string}> $files
     * @param string $sourceNote e.g. "Forum post: <title> <url>" or "Uploaded in panel"
     */
    public function analyze(Tenant $tenant, array $files, string $source = 'upload',
        ?string $sourceRef = null, string $sourceNote = '', string $postText = ''): ?Tender
    {
        try {
            // 1 ── extract every attachment (and remember what could not be read)
            $ex = new TenderExtract();
            $sections = [];
            $unparsed = [];
            $stored = [];
            $total = 0;
            $ocr = app(TenderOcr::class);
            foreach (array_slice($files, 0, 15) as $f) {
                $name = trim((string) ($f['name'] ?? 'file'));
                $bytes = (string) ($f['bytes'] ?? '');
                if ($bytes === '') {
                    continue;
                }
                $path = null;
                try {
                    $path = 'tenders/' . $tenant->id . '/' . date('Ymd') . '-' . substr(md5($bytes), 0, 8) . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', mb_substr($name, 0, 80));
                    Storage::disk('public')->put($path, $bytes);
                } catch (\Throwable $e) {
                    $path = null;
                }
                $r = $ex->extract($name, $bytes);

                // v4.22 — a PDF with no text layer is a SCAN, not a dead end: render its pages and let
                // the vision model read them. Only ever runs when normal extraction found nothing.
                $ocrPages = 0;
                if ($r['text'] === '' && ! empty($r['ocr'])) {
                    try {
                        $o = $ocr->read($tenant, $bytes, $name);
                        if ($o['text'] !== '') {
                            $r = ['text' => $o['text'], 'parsed' => true, 'note' => $o['note']];
                        } else {
                            $r['note'] = $o['note'] ?: $r['note'];
                        }
                        $ocrPages = (int) $o['pages'];
                    } catch (\Throwable $e) {
                    }
                }

                if ($r['text'] !== '' && $total < self::MAX_TOTAL_TEXT) {
                    $keep = mb_substr($r['text'], 0, self::MAX_TOTAL_TEXT - $total, 'UTF-8');
                    $total += mb_strlen($keep, 'UTF-8');
                    $sections[] = "===== ATTACHMENT: {$name} =====\n" . $keep;
                } else {
                    $unparsed[] = $name . ($r['note'] !== '' ? " ({$r['note']})" : '');
                }

                // Keep the real filename/size/read-status so the panel can list the documents the way
                // a back office needs them (open the original, see what could NOT be read, and why).
                $stored[] = [
                    'path' => $path,
                    'name' => $name,
                    'size' => strlen($bytes),
                    'parsed' => $r['text'] !== '',
                    'ocr_pages' => $ocrPages,
                    'note' => (string) ($r['note'] ?? ''),
                ];
            }
            if ($postText !== '') {
                array_unshift($sections, "===== TENDER NOTICE TEXT =====\n" . mb_substr($postText, 0, 20000, 'UTF-8'));
            }
            if (! $sections) {
                $sections[] = "(No attachment text could be extracted. Files that could not be read are listed below — analyse from the notice title/context only and say clearly that the documents must be opened manually.)";
            }
            $user = "SOURCE: {$sourceNote}\nTODAY'S DATE: " . now()->format('Y-m-d') . "\n\n"
                . implode("\n\n", $sections)
                . ($unparsed ? "\n\nFILES THAT COULD NOT BE PARSED (tell management to open these manually):\n- " . implode("\n- ", $unparsed) : '');

            // 2 ── one call → strict JSON
            $raw = $this->call($tenant, $this->systemPrompt($tenant), $user);
            if ($raw === null || trim($raw) === '') {
                return $this->storeFailure($tenant, $source, $sourceRef, $sourceNote, $stored, 'AI did not answer');
            }
            $json = $this->parseJson($raw);
            if (! is_array($json) || empty($json['whatsapp_summary']) || ! isset($json['report'])) {
                return $this->storeFailure($tenant, $source, $sourceRef, $sourceNote, $stored, 'AI answer was not valid JSON');
            }
            $rep = is_array($json['report']) ? $json['report'] : [];

            // 3 ── store
            $deadline = null;
            $dl = (string) ($rep['deadline'] ?? '');
            if ($dl !== '' && ($ts = strtotime($dl)) !== false && $ts > 946684800) {
                $deadline = date('Y-m-d', $ts);
            }
            $score = $rep['opportunity_score'] ?? null;
            if (is_string($score)) {
                $score = (int) preg_replace('/\D+/', '', $score);
            }

            return Tender::create([
                'tenant_id' => $tenant->id,
                'source' => $source,
                'source_ref' => $sourceRef,
                'organization' => mb_substr((string) ($rep['organization'] ?? ''), 0, 200) ?: null,
                'tender_no' => mb_substr((string) ($rep['tender_id'] ?? ''), 0, 120) ?: null,
                'title' => mb_substr((string) ($rep['title'] ?? $sourceNote), 0, 300) ?: null,
                'division' => mb_substr((string) ($rep['division'] ?? ''), 0, 40) ?: null,
                'deadline' => $deadline,
                'opportunity_score' => is_numeric($score) ? max(0, min(100, (int) $score)) : null,
                'win_probability' => mb_substr((string) ($rep['win_probability'] ?? ''), 0, 20) ?: null,
                'recommendation' => mb_substr((string) ($rep['recommendation'] ?? ''), 0, 30) ?: null,
                'status' => 'new',
                'summary' => mb_substr((string) $json['whatsapp_summary'], 0, 4000),
                'report' => $rep,
                'files' => $stored,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('TenderAnalyzer failed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());

            return null;
        }
    }

    /** The condensed TenderBot persona (Bhavin's 13-section spec) + this tenant's profile. */
    private function systemPrompt(Tenant $tenant): string
    {
        $profile = trim((string) $tenant->setting('tender_profile', ''));
        // v4.20.1 — division names follow the tenant. A CUSTOM profile classifies against its
        // own division names (so Family Shoppers can use e.g. "Food & FMCG / Household", not
        // ISP); the blank-profile default keeps the DishNet ISP + General Trading enum.
        $custom = $profile !== '';
        if ($profile === '') {
            $profile = "Primary Business (ISP): Internet Services, Fiber Connectivity, Wireless Internet, Managed Network Services, Starlink Solutions, Network Installation, IT Infrastructure, Networking Equipment, CCTV, Servers, Cloud Solutions, Managed IT Services.\n"
                . "Secondary Business (General Trading): Office Supplies, Stationery, Furniture, Computers, Printers, Toners, Electrical Items, Solar Equipment, Construction Materials, Plumbing, Hardware, Cleaning Supplies, Food Supplies, Hotel & Restaurant Supplies, Medical Supplies, Safety Equipment, Vehicle Spare Parts, Agricultural Supplies, Industrial Equipment, Household Products, Packaging, Wholesale Distribution.";
        }
        $divRule = $custom
            ? 'CLASSIFY division: pick the best-matching division NAME from the BUSINESS PROFILE above (use its exact wording), "Both" when two divisions share the work, or "Ignore" when nothing in the profile fits — with the reason.'
            : 'CLASSIFY division: ISP, General Trading, Both, or Ignore — with the reason.';
        $divEnum = $custom ? 'best-matching division name from the profile|Both|Ignore' : 'ISP|General Trading|Both|Ignore';
        $docs = trim((string) $tenant->setting('tender_company_docs', ''));

        return <<<PROMPT
You are TenderBot AI, a Senior Procurement & Bid Manager with 20+ years in NGO, UN, Government and Private Sector procurement, working as a permanent digital employee for this company. Your objective is NOT to summarise tenders — it is to maximise contracts won while minimising preparation time. Think simultaneously like a Procurement Manager, Technical Sales Engineer, Compliance Officer, Cost Estimator and Business Development Manager. Never stop at "new tender available" — always tell management exactly what should happen next.

BUSINESS PROFILE (decide which division should handle each opportunity):
{$profile}

COMPANY DOCUMENTS ON FILE (mark compliance items against this list; if empty, mark documents as Unknown):
{$docs}

ANALYSE the tender material you are given and produce, for management:
1. EXTRACT: organization, tender number, title, issue date, DEADLINE, submission method/address/email, opening date, tender type, estimated budget + currency, delivery location, lots, required experience, certifications, evaluation criteria, bid security, performance security, payment terms, warranty, delivery period, mandatory documents. State clearly when something is not in the documents — NEVER invent information.
2. {$divRule}
3. SCORE: opportunity_score 0-100 and win_probability (Low/Medium/High/Very High) with reasoning.
4. RECOMMEND: BID / PREQUALIFICATION / REGISTER / PARTNER / IGNORE — with the reason. Suggest partnership when eligibility cannot be met alone.
5. COMPLIANCE: every required document, each marked Available / Need Renewal / Missing / Unknown against the company documents list.
6. MARKET INTELLIGENCE: read any BOQ/pricing sheet; list every product with quantity, specification, suggested brand + alternative, rough market price ONLY when confident, and whether a supplier quotation is needed (when unsure, require a quotation — never estimate blindly).
7. ACTION PLAN: today's prioritised tasks (supplier calls, quotations to request, documents to renew/collect, proposals to prepare, bid security to arrange).
8. RISKS: short deadline, unrealistic delivery, strict experience, high competition, missing documents, low margin, financial/technical/compliance risk — with an overall risk note. Deadlines within 7 days are HIGH PRIORITY; within 48 hours CRITICAL. If the tender has multiple lots, analyse each lot in the report.

WRITING RULES — every field you output is read by staff who will act on it:
- Fill in REAL values everywhere. Never echo a placeholder, an ellipsis, "N", "NN", "<...>" or the word "TBD". If the documents do not say, write "not stated in the documents".
- days_remaining = whole days from TODAY'S DATE (given in the material) to the deadline. If there is no deadline in the documents, use 0 and leave "deadline" empty.
- products_required is the buying list your back office will work from. List EVERY line item, lot item and BOQ row you can see — one entry each, with its own quantity and unit. If the documents contain no product list (a service tender, or documents you could not read), return an empty array rather than inventing items.

OUTPUT — reply with ONE valid JSON object and NOTHING else (no markdown fences):
{
 "whatsapp_summary": "the executive WhatsApp message — UNDER 250 words. FILL IN REAL VALUES: never copy the shape's placeholder wording, never print an ellipsis, the letters N/NN, or bracketed hints. When a fact is not in the documents write 'not stated in the documents'. Line shape:\\n🚨 NEW OPPORTUNITY\\n🏢 Organization: <name>\\n📄 Tender: <title>\\n🏷 Division: <division>\\n⭐ Opportunity: <number>%\\n🏆 Recommendation: <recommendation>\\n📅 Deadline: <the real date, then the real day count in brackets, e.g. '14 Aug 2026 (11 days remaining)'>\\n📦 Main Products Required\\n• <one line per product, or 'not stated in the documents'>\\n💰 Quotations Required Today\\n• <supplier quotes to request, or 'none'>\\n📄 Missing Documents\\n• <documents you do not hold, or 'none'>\\n⚠ Major Risks\\n• <risks>\\n📌 AI Verdict: max three sentences telling management whether to proceed.",
 "report": {
   "tender_id": "", "organization": "", "title": "", "category": "", "division": "{$divEnum}",
   "division_reason": "", "opportunity_score": 0, "win_probability": "", "recommendation": "", "recommendation_reason": "",
   "deadline": "YYYY-MM-DD or empty", "days_remaining": 0, "submission": {"method": "", "address": "", "email": ""},
   "estimated_budget": "", "currency": "", "delivery_location": "", "lots": [],
   "products_required": [{"item": "", "qty": "", "unit": "", "lot": "", "spec": "", "brand": "", "alt_brand": "", "est_price": "", "needs_quote": true, "urgency": ""}],
   "quotation_requests": [], "compliance_requirements": [{"document": "", "status": "Available|Need Renewal|Missing|Unknown"}],
   "missing_documents": [], "risks": [{"risk": "", "level": ""}], "risk_score": "",
   "action_items": [{"task": "", "priority": ""}], "attachments_processed": 0, "unparsed_files": [], "confidence_score": 0
 }
}
PROMPT;
    }

    private function call(Tenant $tenant, string $system, string $user): ?string
    {
        if ($this->llm !== null) {
            return ($this->llm)($system, $user);
        }
        try {
            if (! class_exists(\OpenAI\Laravel\Facades\OpenAI::class)) {
                return null;
            }
            $model = (string) ($tenant->setting('ai_model', '') ?: env('OPENAI_MODEL', 'gpt-4o-mini'));
            $tenantKey = (string) $tenant->setting('openai_api_key', '');
            $client = $tenantKey !== '' ? \OpenAI::client($tenantKey) : \OpenAI\Laravel\Facades\OpenAI::getFacadeRoot();
            $resp = $client->chat()->create([
                'model' => $model,
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

            return trim((string) ($resp->choices[0]->message->content ?? ''));
        } catch (\Throwable $e) {
            \Log::warning('TenderAnalyzer AI call failed: ' . $e->getMessage());

            return null;
        }
    }

    private function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw);
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $j = json_decode($m[0], true);
            if (is_array($j)) {
                return $j;
            }
        }

        return null;
    }

    private function storeFailure(Tenant $tenant, string $source, ?string $ref, string $note, array $stored, string $why): ?Tender
    {
        try {
            return Tender::create([
                'tenant_id' => $tenant->id, 'source' => $source, 'source_ref' => $ref,
                'title' => mb_substr($note ?: 'Tender (analysis failed)', 0, 300),
                'status' => 'failed', 'summary' => 'Analysis failed: ' . $why . '. The attachments are saved — open them manually.',
                'files' => $stored, 'report' => ['error' => $why],
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
