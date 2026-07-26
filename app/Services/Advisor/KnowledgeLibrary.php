<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Project Advisor — the knowledge library service.
 *
 * Serves a profile's sales knowledge (FAQs, educational explainers, objection handling, WhatsApp-ready
 * messages) and filters it by type, category and free-text query. The filtering is pure and
 * unit-testable; the content comes from a per-profile source (solar → {@see SolarKnowledge}). Another
 * industry registers its own source and reuses this service and the UI unchanged.
 */
class KnowledgeLibrary
{
    /** profile key => [content class, method]. Add industries here. */
    private const SOURCES = [
        'solar' => [SolarKnowledge::class, 'all'],
    ];

    public function has(string $profile): bool
    {
        return isset(self::SOURCES[$profile]);
    }

    /** Every entry for a profile. */
    public function all(string $profile): array
    {
        if (! $this->has($profile)) {
            return [];
        }
        [$cls, $m] = self::SOURCES[$profile];
        return array_values($cls::$m());
    }

    /** The distinct categories present, in first-seen order. */
    public function categories(string $profile): array
    {
        $seen = [];
        foreach ($this->all($profile) as $e) {
            $c = (string) ($e['category'] ?? '');
            if ($c !== '' && ! in_array($c, $seen, true)) {
                $seen[] = $c;
            }
        }
        return $seen;
    }

    /**
     * Filter entries. All filters are optional and combine with AND.
     *   type     — exact match against the entry type (faq|education|objection|message)
     *   category — exact match
     *   q        — case-insensitive substring across title, body, whatsapp and tags
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $profile, array $filters = []): array
    {
        $type = trim((string) ($filters['type'] ?? ''));
        $cat  = trim((string) ($filters['category'] ?? ''));
        $q    = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        return array_values(array_filter($this->all($profile), function ($e) use ($type, $cat, $q) {
            if ($type !== '' && ($e['type'] ?? '') !== $type) {
                return false;
            }
            if ($cat !== '' && ($e['category'] ?? '') !== $cat) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            $hay = mb_strtolower(implode(' ', [
                (string) ($e['title'] ?? ''),
                (string) ($e['body'] ?? ''),
                (string) ($e['whatsapp'] ?? ''),
                implode(' ', (array) ($e['tags'] ?? [])),
            ]));
            return str_contains($hay, $q);
        }));
    }
}
