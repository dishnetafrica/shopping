<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\CapabilityRegistry;
use App\Services\Knowledge\Contracts\Capability;
use App\Services\Knowledge\Contracts\Extractor;
use App\Services\Knowledge\Contracts\Projector;
use App\Services\Knowledge\Dto\ActionRequest;
use App\Services\Knowledge\Dto\ExtractionResult;
use App\Services\Knowledge\EntityConfidence;
use App\Services\Knowledge\FactVersioning;
use App\Services\Knowledge\Intent;
use App\Services\Knowledge\KnowledgeQueue;
use App\Services\Knowledge\OwnerProfileResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pure coverage for the domain-free OKE engine core (Drop 1): capability routing, action
 * collapse, owner-profile aliases, per-entity confidence, and fact-versioning rules.
 * No DB — these are the pieces that prove the architecture independently of any app.
 */
class EngineCoreTest extends TestCase
{
    // ---- Capability registry: engine routes by intent, knows no domain ----
    public function test_registry_routes_intent_to_capability(): void
    {
        $reg = new CapabilityRegistry();
        $reg->register($this->dummyCapability('daily_menu', [Intent::MENU, Intent::SPECIAL]));
        $reg->register($this->dummyCapability('core', [Intent::PRICE, Intent::SCHEDULE]));

        $this->assertSame('daily_menu', $reg->forIntent(Intent::MENU)?->name());
        $this->assertSame('core', $reg->forIntent(Intent::PRICE)?->name());
        $this->assertNull($reg->forIntent('hotel_room'));            // unregistered → engine stays generic
        $this->assertCount(2, $reg->all());
    }

    public function test_first_registrant_owns_a_shared_intent(): void
    {
        $reg = new CapabilityRegistry();
        $reg->register($this->dummyCapability('a', [Intent::PRICE]));
        $reg->register($this->dummyCapability('b', [Intent::PRICE]));
        $this->assertSame('a', $reg->forIntent(Intent::PRICE)?->name());
    }

    // ---- Queue collapse: Tea 5000 / 5500 / 6000 -> one action (6000) ----
    public function test_queue_collapses_duplicate_actions_last_wins(): void
    {
        $mk = fn ($price) => new ActionRequest('core', 'set_price', 'Tea', ['price' => $price]);
        $out = KnowledgeQueue::collapse([$mk(5000), $mk(5500), $mk(6000)]);
        $this->assertCount(1, $out);
        $this->assertSame(6000, $out[0]->params['price']);
    }

    public function test_queue_keeps_distinct_targets(): void
    {
        $out = KnowledgeQueue::collapse([
            new ActionRequest('core', 'set_price', 'Tea', ['price' => 6000]),
            new ActionRequest('core', 'set_price', 'Samosa', ['price' => 7000]),
        ]);
        $this->assertCount(2, $out);
    }

    // ---- Owner profile aliases: same as yesterday = repeat = no changes ----
    public function test_owner_aliases_resolve_to_same_canonical(): void
    {
        $this->assertSame(Intent::REPEAT_PREVIOUS, OwnerProfileResolver::resolve('Same as yesterday'));
        $this->assertSame(Intent::REPEAT_PREVIOUS, OwnerProfileResolver::resolve('repeat'));
        $this->assertSame(Intent::REPEAT_PREVIOUS, OwnerProfileResolver::resolve('No changes'));
        $this->assertNull(OwnerProfileResolver::resolve('Lunch add Paneer Tikka'));
    }

    public function test_owner_specific_alias_overrides_seed(): void
    {
        $profile = ['aliases_json' => ['as usual' => Intent::REPEAT_PREVIOUS]];
        $this->assertSame(Intent::REPEAT_PREVIOUS, OwnerProfileResolver::resolve('As usual', $profile));
    }

    // ---- Per-entity confidence rollup = weakest link ----
    public function test_entity_confidence_rollup_is_min(): void
    {
        $entities = [
            EntityConfidence::entity('product', 'Paneer Tikka', 0.99),
            EntityConfidence::entity('price', 18000, 0.96),
            EntityConfidence::entity('meal', 'lunch', 0.83),
        ];
        $this->assertSame(0.83, EntityConfidence::rollup($entities));
    }

    // ---- Fact versioning: never overwrite ----
    public function test_fact_versioning_increments_and_detects_change(): void
    {
        $this->assertSame(1, FactVersioning::nextVersion(null));
        $this->assertSame(3, FactVersioning::nextVersion(2));
        $this->assertTrue(FactVersioning::changed(null, ['price' => 4000]));
        $this->assertTrue(FactVersioning::changed(['price' => 4000], ['price' => 5000]));
        $this->assertFalse(FactVersioning::changed(['price' => 5000], ['price' => 5000]));
    }

    // ---- ExtractionResult merge (one message -> facts + actions) ----
    public function test_extraction_result_merges_facts_and_actions(): void
    {
        $a = new ExtractionResult(Intent::MENU, 'none',
            [], [new ActionRequest('daily_menu', 'add_menu_item', 'Paneer Tikka')], ['??']);
        $b = new ExtractionResult(Intent::PRICE, 'none',
            [], [new ActionRequest('core', 'set_price', 'Tea', ['price' => 5000])]);
        $m = $a->merge($b);
        $this->assertCount(2, $m->actions);
        $this->assertSame(Intent::MENU, $m->intent);      // first non-unknown intent wins
        $this->assertCount(1, $m->leftovers);
        $this->assertFalse($m->isEmpty());
    }

    // ---- helper: a throwaway capability for registry tests ----
    private function dummyCapability(string $name, array $intents): Capability
    {
        return new class($name, $intents) implements Capability {
            public function __construct(private string $n, private array $i) {}
            public function name(): string { return $this->n; }
            public function intents(): array { return $this->i; }
            public function extractor(): Extractor {
                return new class implements Extractor {
                    public function extract(string $text, array $profile = []): ExtractionResult {
                        return new ExtractionResult();
                    }
                };
            }
            public function projector(): Projector {
                return new class implements Projector {
                    public function apply(\App\Models\KnowledgeAction $a): string { return ''; }
                    public function revert(\App\Models\KnowledgeAction $a): void {}
                };
            }
        };
    }
}
