<?php

namespace App\Services\Marketplace\Providers;

use App\Services\Marketplace\Contracts\MarketplaceProvider;
use App\Services\Marketplace\Dto\MarketplaceResult;
use Illuminate\Support\Facades\Http;

/**
 * Jumia Uganda, via the EXISTING Playwright price API (do NOT rebuild the
 * scraper). That service runs on a box with a residential Uganda IP and exposes:
 *
 *     GET {url}/search?key={key}&q={query}   -> up to 5 real, in-stock products
 *     GET {url}/health                       -> {"ok":true}
 *
 * It already caches 30 min per query and is gentle on the marketplace, so we
 * call it directly. This class only maps its JSON into MarketplaceResult objects
 * and NEVER throws — a dead/slow box returns [] so the bot degrades gracefully.
 */
final class JumiaProvider implements MarketplaceProvider
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout = 60,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public function key(): string
    {
        return 'jumia';
    }

    public function isEnabled(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function healthy(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        try {
            $resp = Http::timeout(min($this->timeout, 10))->get("{$this->baseUrl}/health");
            return $resp->ok() && (bool) ($resp->json()['ok'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function search(string $query): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $resp = Http::timeout($this->timeout)->get("{$this->baseUrl}/search", [
                'key' => $this->apiKey,
                'q'   => mb_substr($query, 0, 60),
            ]);
        } catch (\Throwable $e) {
            report($e);                     // fall back to "shop will confirm"
            return [];
        }

        if (! $resp->ok()) {
            return [];
        }

        $data = $resp->json();
        if (! is_array($data) || ! ($data['ok'] ?? false)) {
            return [];
        }

        $cached = (bool) ($data['cached'] ?? false);
        $out = [];
        foreach (($data['results'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            // Carry the API's cached flag onto each row so the DTO reflects it.
            $row['cached'] = $row['cached'] ?? $cached;
            $result = MarketplaceResult::fromArray($row, $this->key(), 'UGX');
            if ($result !== null && $result->baseAmount >= 5000) {   // skip obvious junk/accessory noise
                $out[] = $result;
            }
        }
        return $out;
    }
}
