<?php

namespace Tests\Unit;

use App\Services\Bot\Merchant\CategoryInferer;
use App\Services\Bot\Merchant\MerchantConversationParser;
use App\Services\Bot\Merchant\MerchantProductMatcher;
use App\Services\Bot\Merchant\MerchantSummary;
use PHPUnit\Framework\TestCase;

/**
 * Pure coverage for the WhatsApp "create product if not on platform" feature:
 * category inference, typo-vs-new matching, and the NEW line in the summary.
 * No DB — the create/update branch in MerchantAssistant is framework-glue and
 * deploy-tested; everything decidable in pure logic is asserted here.
 */
class CreateProductMerchantTest extends TestCase
{
    public function test_category_inferer_reuses_tenant_category_spelling(): void
    {
        $known = ['Snacks & Crisps', 'Sweets', 'Spices & Masala'];
        $this->assertSame('Snacks & Crisps', CategoryInferer::infer('Banana Crisps Salted', $known));
        $this->assertSame('Sweets', CategoryInferer::infer('Kaju Katri', $known));
        $this->assertSame('Spices & Masala', CategoryInferer::infer('Garam Masala 100g', $known));
    }

    public function test_category_inferer_falls_back_to_default_label_when_tenant_has_none(): void
    {
        $this->assertSame('Snacks & Crisps', CategoryInferer::infer('Fafda', []));
        $this->assertSame('Rice', CategoryInferer::infer('Basmati Rice 5kg', []));
    }

    public function test_category_inferer_returns_null_when_unknown(): void
    {
        $this->assertNull(CategoryInferer::infer('Xyz Widget', ['Snacks & Crisps']));
    }

    public function test_matcher_flags_typo_of_existing_product(): void
    {
        $names = ['Fafda', 'Jalebi', 'Khaman'];
        $m = MerchantProductMatcher::closest('Fafada', $names);
        $this->assertNotNull($m);
        $this->assertSame('Fafda', $m['name']);
        $this->assertTrue(MerchantProductMatcher::isTypo($m));
    }

    public function test_matcher_treats_distinct_name_as_new(): void
    {
        $names = ['Fafda', 'Jalebi', 'Khaman'];
        $m = MerchantProductMatcher::closest('Banana Crisps Salted', $names);
        $this->assertFalse(MerchantProductMatcher::isTypo($m));
    }

    public function test_parser_still_emits_price_target_for_unknown_item(): void
    {
        // The parser stays product-agnostic; the assistant later decides create-vs-update.
        $res = MerchantConversationParser::extract('Banana Crisps Salted 1kg 35000');
        $this->assertCount(1, $res['changes']);
        $c = $res['changes'][0];
        $this->assertSame('price', $c['type']);
        $this->assertSame(1000, $c['weight_grams']);
        $this->assertSame(35000, $c['price']);
    }

    public function test_summary_renders_new_product_line(): void
    {
        $out = MerchantSummary::render([[
            'type' => 'create_product', 'name' => 'Banana Crisps Salted',
            'weight_grams' => 1000, 'price' => 35000, 'category' => 'Snacks & Crisps',
        ]]);
        $this->assertStringContainsString('🆕 NEW: Banana Crisps Salted', $out);
        $this->assertStringContainsString('UGX 35,000', $out);
        $this->assertStringContainsString('Snacks & Crisps', $out);
        $this->assertStringContainsString('Reply YES', $out);
    }
}
