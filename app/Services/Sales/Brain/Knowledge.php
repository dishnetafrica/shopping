<?php

namespace App\Services\Sales\Brain;

/**
 * PURE rules of the Sales Brain (DishNet South Sudan sales plan, Phase 3). No framework, no database:
 * topics, the life of a version, what a valid entry looks like, and which terms print on a quotation.
 *
 * A piece of sales knowledge (an "entry", e.g. "Starlink terms") has numbered VERSIONS. Each version
 * moves only forwards:
 *
 *   draft ──approve──▶ approved ──publish──▶ published ──(newer version published / retire)──▶ retired
 *     └──reject──▶ rejected     └──reject──▶ rejected
 *
 * Only a PUBLISHED version is ever read by the customer-facing bot, the salesperson coach or the
 * quotation. A draft cannot be approved while it still has open questions for management.
 * The business (tenant) is the market boundary: DishNetSS knowledge is South Sudan knowledge only.
 */
final class Knowledge
{
    public const TOPICS = [
        'company'       => 'Company introduction',
        'starlink'      => 'Starlink service',
        'fiber'         => 'Fiber service',
        'packages'      => 'Packages and prices',
        'coverage'      => 'Coverage areas',
        'qualification' => 'Qualification questions',
        'scripts'       => 'Sales scripts',
        'faq'           => 'Frequently asked questions',
        'technical'     => 'Technical information',
        'objections'    => 'Objection handling',
        'payment'       => 'Payment procedures',
        'terms'         => 'Terms and conditions',
        'followup'      => 'Follow-up and closing',
        'quiz'          => 'Training questions',
    ];

    /** Internal topics: management and the training only — never the bot, the coach or sales staff's reading. */
    public const INTERNAL = ['quiz'];

    public const STATUSES = ['draft', 'approved', 'published', 'retired', 'rejected'];

    /** Coverage answers. Anything not explicitly listed as covered is "survey needed", never "covered". */
    public const COVERAGE = ['covered', 'survey', 'none'];

    public const SERVICE_LINES = ['starlink', 'fiber'];

    public const BODY_MAX = 20000;

    /** action => [from-status => to-status] */
    private const MOVES = [
        'approve' => ['draft' => 'approved'],
        'publish' => ['approved' => 'published'],
        'reject'  => ['draft' => 'rejected', 'approved' => 'rejected'],
        'retire'  => ['published' => 'retired'],
    ];

    /** The status a version moves to, or null if that step is not allowed from where it is. */
    public static function next(string $from, string $action): ?string
    {
        return self::MOVES[$action][$from] ?? null;
    }

    /** Only a draft can be changed; anything approved or later is fixed and needs a new version. */
    public static function editable(string $status): bool
    {
        return $status === 'draft';
    }

    /** A new version may be started only when nothing is already on its way (draft or approved). */
    public static function canStartVersion(array $statuses): bool
    {
        return ! array_intersect($statuses, ['draft', 'approved']);
    }

    /** Machine key for an entry: lower-case letters, digits, dashes. */
    public static function key(string $raw): string
    {
        $k = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($raw))) ?? '', '-');

        return mb_substr($k, 0, 64);
    }

    /**
     * Check and normalise the content of a version. Returns ['ok'=>true,'body'=>..,'data'=>..] or
     * ['ok'=>false,'error'=>..]. Terms and coverage carry structured data; other topics are text.
     */
    public static function validate(string $topic, ?string $body, $data): array
    {
        if (! isset(self::TOPICS[$topic])) {
            return ['ok' => false, 'error' => 'unknown_topic'];
        }
        $body = trim((string) $body);
        if (mb_strlen($body) > self::BODY_MAX) {
            return ['ok' => false, 'error' => 'too_long'];
        }
        $data = is_array($data) ? $data : null;

        if ($topic === 'terms') {
            $items = [];
            foreach ((array) ($data['items'] ?? []) as $it) {
                $label = trim((string) ($it['label'] ?? ''));
                $text = trim((string) ($it['text'] ?? ''));
                if ($text !== '') {
                    $items[] = ['label' => mb_substr($label, 0, 80), 'text' => mb_substr($text, 0, 1000)];
                }
            }
            if (! $items) {
                return ['ok' => false, 'error' => 'terms_need_items'];
            }
            $data = ['title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 160), 'items' => $items];
            if ($body === '') {
                $body = self::termsText($data);
            }
        } elseif ($topic === 'coverage') {
            $areas = [];
            foreach ((array) ($data['areas'] ?? []) as $a) {
                $name = trim((string) ($a['name'] ?? ''));
                $line = (string) ($a['service_line'] ?? '');
                $status = (string) ($a['status'] ?? '');
                if ($name === '' || ! in_array($line, self::SERVICE_LINES, true) || ! in_array($status, self::COVERAGE, true)) {
                    return ['ok' => false, 'error' => 'coverage_area_invalid', 'area' => $name];
                }
                $kw = array_values(array_filter(array_map(fn ($k) => mb_strtolower(trim((string) $k)), (array) ($a['keywords'] ?? [])), fn ($k) => mb_strlen($k) >= 3));
                $lat = isset($a['lat']) && is_numeric($a['lat']) ? (float) $a['lat'] : null;
                $lng = isset($a['lng']) && is_numeric($a['lng']) ? (float) $a['lng'] : null;
                $rad = isset($a['radius_m']) && is_numeric($a['radius_m']) && $a['radius_m'] > 0 ? (int) $a['radius_m'] : null;
                if ($kw === [] && ($lat === null || $lng === null || $rad === null)) {
                    return ['ok' => false, 'error' => 'coverage_area_needs_place', 'area' => $name];   // keywords or a circle
                }
                $areas[] = ['name' => mb_substr($name, 0, 120), 'service_line' => $line, 'status' => $status, 'keywords' => $kw,
                    'lat' => $lat, 'lng' => $lng, 'radius_m' => $rad, 'note' => mb_substr(trim((string) ($a['note'] ?? '')), 0, 300)];
            }
            if (! $areas) {
                return ['ok' => false, 'error' => 'coverage_needs_areas'];
            }
            $data = ['areas' => $areas];
        } elseif ($topic === 'quiz') {
            // Training questions for one module (Phase 5); the module is the entry's key.
            $v = \App\Services\Sales\Training\Assessment::validate((string) ($data['module'] ?? ''), (array) ($data['questions'] ?? []));
            if (! $v['ok']) {
                return $v;
            }
            $data = $v['data'];
            $body = count($data['questions']) . ' question(s) for ' . \App\Services\Sales\Training\Assessment::MODULES[$data['module']]['label'];
        } else {
            if ($body === '') {
                return ['ok' => false, 'error' => 'body_required'];
            }
            $data = null;
        }

        return ['ok' => true, 'body' => $body, 'data' => $data];
    }

    /** Plain-text form of a set of terms (what the screen and the coach read). */
    public static function termsText(array $data): string
    {
        $out = [];
        foreach (array_values((array) ($data['items'] ?? [])) as $i => $it) {
            $out[] = ($i + 1) . '. ' . ($it['label'] !== '' ? $it['label'] . ': ' : '') . $it['text'];
        }

        return implode("\n", $out);
    }

    // ── the text-box forms used on the screen (one item per line) ─────────

    /** "Label: text" per line → terms items. A line without a colon is a term with no label. */
    public static function parseTermsLines(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/^\s*(\d+[.)]|[-*•])\s*/u', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            $p = mb_strpos($line, ':');
            $items[] = ($p !== false && $p > 0 && $p <= 40)
                ? ['label' => trim(mb_substr($line, 0, $p)), 'text' => trim(mb_substr($line, $p + 1))]
                : ['label' => '', 'text' => $line];
        }

        return $items;
    }

    public static function termsLines(array $data): string
    {
        return implode("\n", array_map(fn ($it) => ($it['label'] !== '' ? $it['label'] . ': ' : '') . $it['text'], (array) ($data['items'] ?? [])));
    }

    /**
     * Coverage, one area per line:  Name | starlink or fiber | covered, survey or none | place names, comma separated | note | lat,lng,radius_m
     * The last two parts are optional.
     */
    public static function parseCoverageLines(string $text): array
    {
        $areas = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            $circle = array_map('trim', explode(',', $p[5] ?? ''));
            $areas[] = [
                'name' => $p[0] ?? '', 'service_line' => mb_strtolower($p[1] ?? ''), 'status' => mb_strtolower($p[2] ?? ''),
                'keywords' => array_values(array_filter(array_map('trim', explode(',', $p[3] ?? '')), fn ($k) => $k !== '')),
                'note' => $p[4] ?? '',
                'lat' => count($circle) === 3 ? $circle[0] : null, 'lng' => count($circle) === 3 ? $circle[1] : null, 'radius_m' => count($circle) === 3 ? $circle[2] : null,
            ];
        }

        return $areas;
    }

    public static function coverageLines(array $data): string
    {
        return implode("\n", array_map(function ($a) {
            $parts = [$a['name'], $a['service_line'], $a['status'], implode(', ', (array) $a['keywords']), (string) ($a['note'] ?? '')];
            if (($a['lat'] ?? null) !== null && ($a['lng'] ?? null) !== null && ($a['radius_m'] ?? null) !== null) {
                $parts[] = $a['lat'] . ',' . $a['lng'] . ',' . $a['radius_m'];
            }

            return rtrim(implode(' | ', $parts), ' |');
        }, (array) ($data['areas'] ?? [])));
    }

    /** Screen text → [body, data] for a topic (terms and coverage are typed as lines; others are prose). */
    public static function fromText(string $topic, string $text, string $title = '', string $key = ''): array
    {
        return match ($topic) {
            'terms'    => [null, ['title' => $title, 'items' => self::parseTermsLines($text)]],
            'coverage' => [null, ['areas' => self::parseCoverageLines($text)]],
            'quiz'     => [null, ['module' => self::key($key), 'questions' => \App\Services\Sales\Training\Assessment::parseQuestions($text)]],
            default    => [$text, null],
        };
    }

    /** Version → the text shown in the screen's text box. */
    public static function toText(string $topic, ?string $body, ?array $data): string
    {
        return match ($topic) {
            'terms'    => self::termsLines((array) $data),
            'coverage' => self::coverageLines((array) $data),
            'quiz'     => \App\Services\Sales\Training\Assessment::questionsText((array) ($data['questions'] ?? [])),
            default    => (string) $body,
        };
    }

    /**
     * Which published terms print on an Internet-provider quotation, following the UISP template's
     * switch: Starlink only → the "starlink" terms; Fiber only → the "fiber" terms; anything else
     * (both, or neither known) → the "general" terms. If the chosen set is not published, NOTHING
     * prints: another service's terms are never used as a stand-in.
     *
     * @param array $published key => data (published 'terms' entries)
     * @return array  the layout's terms list: [['title'=>..,'items'=>[['label','text'],..]]] or []
     */
    public static function quoteTerms(array $serviceLines, array $published): array
    {
        $lines = array_values(array_unique($serviceLines));
        sort($lines);
        $key = $lines === ['starlink'] ? 'starlink' : ($lines === ['fiber'] ? 'fiber' : 'general');
        $d = $published[$key] ?? null;
        if (! is_array($d) || empty($d['items'])) {
            return [];
        }

        return [['title' => (string) ($d['title'] ?? ''), 'items' => array_values($d['items'])]];
    }
}
