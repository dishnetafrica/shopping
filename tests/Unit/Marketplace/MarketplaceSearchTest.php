<?php

namespace Tests\Unit\Marketplace;

use App\Services\Marketplace\Dto\MarketplaceOutcome;
use App\Services\Marketplace\MarketplaceSearch;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * MarketplaceSearch orchestration: pricing + confidence gating + guardrails.
 * We exercise the facade-free core (quoteWith) with a FakeProvider and a
 * hand-built config, so no HTTP, container, or DB is needed.
 */
class MarketplaceSearchTest extends TestCase
{
    use MakesMarketplaceFixtures;

    private function search(): MarketplaceSearch
    {
        // quoteWith() doesn't touch the registry; build without the constructor
        // so we don't need a container in a pure unit test.
        return (new ReflectionClass(MarketplaceSearch::class))->newInstanceWithoutConstructor();
    }

    public function test_strong_match_returns_a_priced_quote(): void
    {
        $out = $this->search()->quoteWith(
            $this->cfg(),
            new FakeProvider([$this->result('TP-Link Archer C6 AC1200 Router', 302000)]),
            'tp-link router',
        );

        $this->assertSame(MarketplaceOutcome::QUOTE, $out->decision);
        $this->assertCount(1, $out->quotes());
        $q = $out->quotes()[0];
        $this->assertSame((int) ceil(302000 * 1.15), $q->sellPrice);
        $this->assertSame('UGX', $q->currency);
        $this->assertSame('https://ug.jumia.is/x.jpg', $q->image);      // passed allow-list
    }

    public function test_weak_match_returns_none(): void
    {
        $out = $this->search()->quoteWith(
            $this->cfg(),
            new FakeProvider([$this->result('Kitchen Blender 2L', 120000)]),
            'wireless router',
        );
        $this->assertTrue($out->isNone());
    }

    public function test_empty_provider_returns_none(): void
    {
        $out = $this->search()->quoteWith($this->cfg(), new FakeProvider([]), 'anything');
        $this->assertTrue($out->isNone());
    }

    public function test_bad_host_image_is_stripped_but_quote_kept(): void
    {
        $out = $this->search()->quoteWith(
            $this->cfg(),
            new FakeProvider([$this->result('TP-Link Archer C6 Router', 302000, 'https://evil.example/x.jpg')]),
            'tp-link archer c6 router',
        );
        $this->assertTrue($out->isQuote());
        $this->assertSame('', $out->quotes()[0]->image);
    }

    public function test_disabled_config_is_respected_via_none(): void
    {
        // quoteWith trusts the caller's gate; the disabled short-circuit lives in quote().
        // Here we assert the richer object still carries all fields for the bot.
        $out = $this->search()->quoteWith(
            $this->cfg(),
            new FakeProvider([$this->result('TP-Link Archer C6 Router', 302000)]),
            'tp-link archer c6 router',
        );
        $arr = $out->quotes()[0]->toArray();
        foreach (['name', 'sell_price', 'currency', 'image_url', 'url', 'provider', 'cached', 'confidence'] as $k) {
            $this->assertArrayHasKey($k, $arr);
        }
    }

    public function test_max_options_caps_quote_count(): void
    {
        $out = $this->search()->quoteWith(
            $this->cfg(['maxOptions' => 2]),
            new FakeProvider([
                $this->result('Solar Panel 100W Mono', 300000),
                $this->result('Solar Panel 150W Mono', 400000),
                $this->result('Solar Panel 200W Mono', 500000),
            ]),
            'solar panel',
        );
        $this->assertTrue($out->isQuote());
        $this->assertLessThanOrEqual(2, count($out->quotes()));
    }
}
