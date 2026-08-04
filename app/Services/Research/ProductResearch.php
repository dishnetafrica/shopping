<?php

namespace App\Services\Research;

use App\Services\Research\Contracts\ResearchProvider;
use App\Services\Research\Dto\Finding;
use Illuminate\Support\Facades\Cache;

/**
 * ProductResearch
 * ---------------
 * Asks each configured source what it knows about a product, validates every claim,
 * and produces a PLAN. It never writes. Persisting is the caller's job — the console
 * command with --apply, or the panel when a human approves a queued suggestion.
 *
 * The three rules that make this safe to run over a live catalogue:
 *
 *   1. FILL BLANKS ONLY. A field a person has filled in is never overwritten, at any
 *      confidence, from any source. The decision for such a field is 'keep' and it is
 *      not even queued — a human already answered that question.
 *   2. AUTHORITATIVE SOURCES FIRST. Providers are asked in config order and the first
 *      to speak for a field wins, so a barcode fact is never displaced by an inference.
 *   3. VALIDATE, THEN CAP. Every value must survive a per-field validator. A value that
 *      fails is not dropped silently — it is kept with its confidence CAPPED below the
 *      review floor, so it disappears from the queue rather than reappearing as a
 *      confident wrong answer. This is the same trick ProductEnrichmentService uses on
 *      out-of-vocabulary product types, and it exists for the same reason.
 */
final class ProductResearch
{
    /** ODbL requires attribution wherever these facts are shown. */
    public const ATTRIBUTION = 'Product data from Open Food Facts, licensed under ODbL.';

    /**
     * Closed origin vocabulary. A country not on this list cannot be auto-applied —
     * the validator caps it — which stops "made in Europe", "Far East" or an invented
     * country from ever reaching a customs line. Extend it when a real supplier needs it.
     */
    public const COUNTRIES = [
        'Uganda', 'Kenya', 'Tanzania', 'Rwanda', 'Burundi', 'South Sudan', 'Sudan', 'Ethiopia',
        'Somalia', 'Djibouti', 'Egypt', 'Morocco', 'Tunisia', 'Algeria', 'Nigeria', 'Ghana',
        'South Africa', 'Zambia', 'Zimbabwe', 'Malawi', 'Mozambique', 'Botswana', 'Namibia',
        'China', 'India', 'Pakistan', 'Bangladesh', 'Sri Lanka', 'Nepal', 'Indonesia', 'Malaysia',
        'Singapore', 'Thailand', 'Vietnam', 'Philippines', 'Japan', 'South Korea', 'Taiwan',
        'UAE', 'Saudi Arabia', 'Oman', 'Qatar', 'Kuwait', 'Bahrain', 'Jordan', 'Lebanon',
        'Turkey', 'Iran', 'Israel',
        'United Kingdom', 'Ireland', 'France', 'Germany', 'Italy', 'Spain', 'Portugal',
        'Netherlands', 'Belgium', 'Switzerland', 'Austria', 'Poland', 'Denmark', 'Sweden',
        'Norway', 'Finland', 'Greece', 'Russia', 'Ukraine',
        'USA', 'Canada', 'Mexico', 'Brazil', 'Argentina', 'Chile', 'Colombia',
        'Australia', 'New Zealand',
    ];

    /** Common spellings that mean a country on the list. */
    private const COUNTRY_ALIASES = [
        'uk' => 'United Kingdom', 'great britain' => 'United Kingdom', 'britain' => 'United Kingdom',
        'england' => 'United Kingdom', 'scotland' => 'United Kingdom', 'wales' => 'United Kingdom',
        'united states' => 'USA', 'united states of america' => 'USA', 'us' => 'USA', 'u.s.a.' => 'USA',
        'america' => 'USA',
        'united arab emirates' => 'UAE', 'u.a.e.' => 'UAE', 'dubai' => 'UAE', 'abu dhabi' => 'UAE',
        'korea' => 'South Korea', 'republic of korea' => 'South Korea',
        'holland' => 'Netherlands', 'the netherlands' => 'Netherlands',
        'ksa' => 'Saudi Arabia', 'kingdom of saudi arabia' => 'Saudi Arabia',
        'prc' => 'China', "people's republic of china" => 'China', 'p.r.c.' => 'China',
        'republic of india' => 'India', 'bharat' => 'India',
        'rsa' => 'South Africa', 'republic of south africa' => 'South Africa',
        'türkiye' => 'Turkey', 'turkiye' => 'Turkey',
        'viet nam' => 'Vietnam',
        'deutschland' => 'Germany',
    ];

    /** Failing validation drops a value below the review floor rather than deleting it. */
    private const FAIL_CONF = 0.20;

    /** @var ResearchProvider[] */
    private array $providers;

    /** @param ResearchProvider[] $providers */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers ?: self::fromConfig();
    }

    /** @return ResearchProvider[] */
    public static function fromConfig(): array
    {
        $out = [];
        foreach ((array) config('research.providers', []) as $key => $class) {
            try {
                $p = app($class);
                if ($p instanceof ResearchProvider && $p->isEnabled()) {
                    $out[] = $p;
                }
            } catch (\Throwable $e) {
                // a misconfigured provider must not take the pipeline down
            }
        }

        return $out;
    }

    public static function enabled($tenant = null): bool
    {
        if (! (bool) config('research.enabled', true)) {
            return false;
        }
        if ($tenant === null) {
            return true;
        }

        return (bool) (method_exists($tenant, 'setting') ? $tenant->setting('research_enabled', false) : false);
    }

    // ------------------------------------------------------------ gathering

    /**
     * Ask every provider, in order, for the fields still unknown. First source to
     * speak for a field wins; later providers are not even asked for it.
     *
     * @return Finding[]  validated, deduped by field
     */
    public function gather(array $product): array
    {
        $wanted = self::missing($product);
        $found = [];

        foreach ($this->providers as $p) {
            $ask = array_values(array_diff($wanted, array_keys($found)));
            // hints are extras the interactive UI uses; keep asking for them
            if (! $ask && isset($found['name_hint'], $found['image_hint'])) {
                break;
            }
            try {
                $results = $p->research($product, $ask);
            } catch (\Throwable $e) {
                $results = [];                       // contract says never throw; belt and braces
            }
            foreach ($results as $f) {
                if (! $f instanceof Finding || isset($found[$f->field])) {
                    continue;
                }
                $found[$f->field] = self::validate($f);
            }
        }

        return array_values($found);
    }

    /** Writable fields this product is still missing. */
    public static function missing(array $product): array
    {
        $out = [];
        foreach ((array) config('research.fields', []) as $f) {
            if (trim((string) ($product[$f] ?? '')) === '') {
                $out[] = $f;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ validation (pure)

    /** Enforce the shape of a value for its field; cap confidence when it does not hold. */
    public static function validate(Finding $f): Finding
    {
        $v = trim($f->value);

        switch ($f->field) {
            case 'origin_country':
                $c = self::country($v);

                return $c === ''
                    ? $f->with($v, self::FAIL_CONF, 'not a recognised country — left for a human')
                    : $f->with($c);

            case 'unit_size':
                $s = Providers\BarcodeProvider::compactSize($v);

                return $s === ''
                    ? $f->with($v, self::FAIL_CONF, 'not a usable size — left for a human')
                    : $f->with($s);

            case 'brand':
                $ok = mb_strlen($v) >= 2 && mb_strlen($v) <= 60 && preg_match('/\p{L}{2}/u', $v);

                return $ok ? $f->with(mb_substr($v, 0, 60)) : $f->with($v, self::FAIL_CONF, 'does not look like a brand');

            case 'category':
                $ok = mb_strlen($v) >= 2 && mb_strlen($v) <= 60;

                return $ok ? $f->with(mb_substr($v, 0, 60)) : $f->with($v, self::FAIL_CONF, 'not a usable category');

            case 'description':
                $ok = mb_strlen($v) >= 40 && mb_strlen($v) <= 900;

                return $ok ? $f : $f->with($v, self::FAIL_CONF, 'too short or too long to publish');

            default:
                return $f;                            // name_hint / image_hint pass through untouched
        }
    }

    /** Resolve a free-text country to the closed vocabulary, or '' if it is not one. */
    public static function country(string $raw): string
    {
        $k = mb_strtolower(trim(preg_replace('/\s+/', ' ', $raw) ?? ''));
        $k = trim($k, " .\t\n");
        if ($k === '') {
            return '';
        }
        foreach (self::COUNTRIES as $c) {
            if (mb_strtolower($c) === $k) {
                return $c;
            }
        }

        return self::COUNTRY_ALIASES[$k] ?? '';
    }

    // ------------------------------------------------------------ the plan (pure)

    /**
     * Decide what happens to each finding.
     *
     * apply  — the field is empty and the source is confident: write it
     * review — the field is empty and the source is unsure: queue it for a human
     * keep   — a person already filled this field: do nothing, do not queue
     * skip   — too weak to be worth anyone's attention
     *
     * @param  Finding[]  $findings
     * @return array<int,array{finding:Finding,decision:string,current:string}>
     */
    public static function plan(array $product, array $findings): array
    {
        $apply = (float) config('research.confidence.auto_apply', 0.85);
        $review = (float) config('research.confidence.review', 0.55);
        $writable = (array) config('research.fields', []);
        $out = [];

        foreach ($findings as $f) {
            if (! $f instanceof Finding || ! in_array($f->field, $writable, true)) {
                continue;                              // hints are not written by the planner
            }
            $current = trim((string) ($product[$f->field] ?? ''));

            if ($current !== '') {
                $decision = 'keep';                    // rule 1 — never overwrite a human
            } elseif (mb_strtolower($f->value) === mb_strtolower(trim((string) ($product['name'] ?? '')))) {
                $decision = 'skip';                    // echoing the name back is not information
            } elseif ($f->confidence >= $apply) {
                $decision = 'apply';
            } elseif ($f->confidence >= $review) {
                $decision = 'review';
            } else {
                $decision = 'skip';
            }

            $out[] = ['finding' => $f, 'decision' => $decision, 'current' => $current];
        }

        return $out;
    }

    /** Convenience: the field => value map a caller should write for decision 'apply'. */
    public static function applied(array $plan): array
    {
        $out = [];
        foreach ($plan as $row) {
            if (($row['decision'] ?? '') === 'apply') {
                $out[$row['finding']->field] = $row['finding']->value;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ spend guard

    /**
     * There is no AI cost metering in this application. Until there is, this counts
     * research calls per tenant per day in the cache and refuses past the cap, so a
     * runaway loop or an over-eager backfill cannot quietly spend a month's budget.
     */
    public static function capReached(int $tenantId): bool
    {
        $cap = (int) config('research.daily_cap', 400);

        return $cap > 0 && (int) Cache::get(self::capKey($tenantId), 0) >= $cap;
    }

    public static function countCall(int $tenantId, int $n = 1): void
    {
        $k = self::capKey($tenantId);
        try {
            if (Cache::add($k, $n, now()->addDay())) {
                return;
            }
            Cache::increment($k, $n);
        } catch (\Throwable $e) {
            // metering must never block the work it is metering
        }
    }

    private static function capKey(int $tenantId): string
    {
        return 'research:calls:' . $tenantId . ':' . date('Y-m-d');
    }
}
