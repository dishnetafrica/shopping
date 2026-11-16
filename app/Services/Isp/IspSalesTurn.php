<?php

namespace App\Services\Isp;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Sales\Brain\Knowledge;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Travel\Conversation\ReplyGuard;
use App\Support\BotTrace;

/**
 * The customer-facing bot on an Internet-provider business (DishNet South Sudan plan, Phase 3).
 *
 *   promptBlock()  what the model is told: qualify the customer, and use ONLY the Sales Brain's
 *                  PUBLISHED knowledge and the catalogue's prices — never invent a price, package,
 *                  coverage, term or timeline. With nothing published it is told so, plainly.
 *   afterReply()   IspReplyGuard on the model's words: unknown amounts and unpublished coverage
 *                  claims are removed and replaced with a short note; each removal is traced.
 *
 * Drafts, approved-but-unpublished and rejected knowledge never reach this class: it reads through
 * SalesBrain::published() only.
 */
class IspSalesTurn
{
    private const KNOWLEDGE_MAX = 9000;

    public function __construct(private SalesBrain $brain) {}

    public function promptBlock(Tenant $tenant): string
    {
        try {
            $published = $this->brain->published($tenant);
        } catch (\Throwable $e) {
            report($e);
            $published = [];
        }

        return self::rules() . self::knowledgeBlock($published);
    }

    public function afterReply(Tenant $tenant, string $from, string $customerText, string $reply): string
    {
        try {
            $published = $this->brain->published($tenant);
            [$amounts, $places] = self::allowed($published);
            $g = IspReplyGuard::clean($reply, array_merge($amounts, $this->cataloguePrices($tenant), ReplyGuard::amounts($customerText)), $places);
            foreach (['isp_price_stripped' => $g['price_hits'], 'isp_cover_stripped' => $g['coverage_hits']] as $stage => $n) {
                if ($n > 0) {
                    BotTrace::log($tenant->id, 'isp', $from, $stage, 'x' . $n);
                }
            }

            return $g['reply'];
        } catch (\Throwable $e) {
            report($e);

            return $reply;
        }
    }

    /** PURE. The standing rules for an Internet-provider sales conversation. */
    public static function rules(): string
    {
        return "INTERNET SALES (this business sells Starlink and Fiber internet; a named salesperson owns each customer and follows up):\n"
            . "- QUALIFY before recommending, ONE question at a time, using what the customer already said: which service (Starlink, Fiber, or not sure), WHERE exactly (area and a landmark), home or business, and how many people or devices will use it and for what.\n"
            . "- ONLY APPROVED INFORMATION: prices, packages, speeds, installation fees, contract terms, coverage and timelines come ONLY from APPROVED SALES KNOWLEDGE and PRODUCTS. If something is not written there, do not guess and do not use general knowledge about other countries or providers: say their salesperson will confirm it.\n"
            . "- COVERAGE: say a place is covered ONLY if COVERAGE below lists it as covered. Anywhere else, say a site survey is needed to confirm. Never say \"we cover your area\" otherwise.\n"
            . "- Never promise a discount, free installation, a speed, an uptime figure or an installation date that is not written below.\n"
            . "- When the customer is ready to go ahead (a quotation, a site visit, payment), tell them their salesperson will take it from here.\n";
    }

    /** PURE. The published knowledge, as the model reads it. */
    public static function knowledgeBlock(array $published): string
    {
        $coverage = [];
        $text = '';
        foreach ($published as $p) {
            if (in_array($p['topic'], Knowledge::INTERNAL, true)) {
                continue;                                   // training questions are never customer knowledge
            }
            if ($p['topic'] === 'coverage') {
                foreach ((array) ($p['data']['areas'] ?? []) as $a) {
                    $coverage[] = $a;
                }
                continue;
            }
            $label = Knowledge::TOPICS[$p['topic']] ?? $p['topic'];
            $body = $p['topic'] === 'terms' ? Knowledge::termsText((array) $p['data']) : trim((string) $p['body']);
            $text .= "## {$label}: {$p['title']}\n{$body}\n";
        }
        if ($text === '' && ! $coverage) {
            return "APPROVED SALES KNOWLEDGE: none is published yet. Do not state any price, package, coverage or term. Take the customer's needs and exact location, and tell them their salesperson will contact them with the details.\n";
        }
        if (mb_strlen($text) > self::KNOWLEDGE_MAX) {
            $text = mb_substr($text, 0, self::KNOWLEDGE_MAX) . "\n(…more is held by the sales team; for anything not shown here, the salesperson will confirm.)\n";
        }
        $out = "APPROVED SALES KNOWLEDGE (published by management for this market; use nothing else):\n" . ($text !== '' ? $text : "(no text published yet)\n");

        $names = ['covered' => 'Covered', 'survey' => 'Site survey needed', 'none' => 'Not served'];
        if ($coverage) {
            $out .= "COVERAGE (any place not listed here: site survey needed):\n";
            foreach (Knowledge::SERVICE_LINES as $line) {
                foreach ($names as $status => $label) {
                    $places = array_map(fn ($a) => $a['name'] . ($a['keywords'] ? ' (' . implode(', ', $a['keywords']) . ')' : ''),
                        array_values(array_filter($coverage, fn ($a) => $a['service_line'] === $line && $a['status'] === $status)));
                    if ($places) {
                        $out .= '- ' . ucfirst($line) . " — {$label}: " . implode('; ', $places) . "\n";
                    }
                }
            }
        } else {
            $out .= "COVERAGE: no areas are published yet: for every place, a site survey is needed to confirm.\n";
        }

        return $out;
    }

    /**
     * PURE. What the guard lets through: amounts written in the published knowledge, and the places
     * published as covered.
     * @return array{0: float[], 1: string[]}
     */
    public static function allowed(array $published): array
    {
        $amounts = [];
        $places = [];
        foreach ($published as $p) {
            if (in_array($p['topic'], Knowledge::INTERNAL, true)) {
                continue;
            }
            if ($p['topic'] === 'coverage') {
                foreach ((array) ($p['data']['areas'] ?? []) as $a) {
                    if (($a['status'] ?? '') === 'covered') {
                        $places[] = mb_strtolower((string) $a['name']);
                        foreach ((array) ($a['keywords'] ?? []) as $k) {
                            $places[] = mb_strtolower((string) $k);
                        }
                    }
                }
                continue;
            }
            $body = $p['topic'] === 'terms' ? Knowledge::termsText((array) $p['data']) : (string) $p['body'];
            $amounts = array_merge($amounts, ReplyGuard::amounts($body));
        }

        return [array_values(array_unique($amounts, SORT_REGULAR)), array_values(array_unique(array_filter($places)))];
    }

    private function cataloguePrices(Tenant $tenant): array
    {
        return Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->where('price', '>', 0)
            ->limit(500)->pluck('price')->map(fn ($p) => (float) $p)->unique()->values()->all();
    }
}
