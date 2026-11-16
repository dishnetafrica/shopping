<?php

namespace Tests\Feature\Sales;

use App\Models\Quotation;
use App\Models\Tenant;
use App\Services\Catalog\ProductWriteService;
use App\Services\Sales\QuotationWriteService;
use App\Services\Sales\QuoteNumber;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quotation numbers: on the running series the PDF the customer receives and the saved quotation must
 * carry the SAME number. Before this fix the PDF was drawn with a random legacy number and the save then
 * replaced it with the next series number.
 */
class QuoteNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(array $settings): Tenant
    {
        $t = Tenant::factory()->create(['settings' => array_merge(['vertical' => 'grocery', 'currency' => 'USD'], $settings)]);
        app(TenantContext::class)->set($t->id);
        app(ProductWriteService::class)->create($t, ['name' => 'Test Item', 'price' => 10, 'stock' => 10]);

        return $t;
    }

    private function quote(Tenant $t): array
    {
        $r = app(QuotationWriteService::class)->createFor($t, '256711000001', 'Customer', [['name' => 'Test Item', 'qty' => 1]], null, false);
        $this->assertTrue($r['ok'] ?? false, json_encode($r));

        return [$r, Quotation::withoutGlobalScopes()->findOrFail($r['id'])];
    }

    public function test_on_the_running_series_the_pdf_and_the_saved_quotation_have_the_same_number(): void
    {
        $t = $this->business(['quote_number_mode' => 'sequential']);
        $year = now()->format('Y');

        [$r1, $q1] = $this->quote($t);
        [$r2, $q2] = $this->quote($t);

        $this->assertSame(["QUO-{$year}-0001", "QUO-{$year}-0002"], [$q1->quote_no, $q2->quote_no], 'one number each, in order, none skipped');
        $this->assertSame($q1->quote_no, $r1['quote_no'], 'the number the customer is given is the saved number');
        if ($r1['has_pdf']) {
            $this->assertStringContainsString($q1->quote_no, (string) $q1->pdf_path, 'and the PDF file carries it too');
        }
    }

    public function test_the_legacy_numbering_is_unchanged(): void
    {
        $t = $this->business([]);

        [$r, $q] = $this->quote($t);

        $this->assertMatchesRegularExpression('/-Q\d{6}-[A-Z0-9]{4}$/', $q->quote_no);
        $this->assertSame($q->quote_no, $r['quote_no']);
    }

    public function test_what_counts_as_a_series_number(): void
    {
        $this->assertTrue(QuoteNumber::matchesSeries('QUO', 'QUO-2026-0007'));
        $this->assertTrue(QuoteNumber::matchesSeries('QUO', 'QUO-0007'), 'the never-resetting form');
        $this->assertFalse(QuoteNumber::matchesSeries('QUO', 'ORD-Q261116-AB12'), 'a legacy number is not');
        $this->assertFalse(QuoteNumber::matchesSeries('QUO', 'XQUO-2026-0007'));
        $this->assertFalse(QuoteNumber::matchesSeries('DN', 'QUO-2026-0007'), 'another prefix is not');
    }
}
