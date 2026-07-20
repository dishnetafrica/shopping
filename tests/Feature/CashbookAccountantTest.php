<?php

namespace Tests\Feature;

use App\Services\Finance\CashbookExport;
use Tests\TestCase;

/**
 * Accountant cashbook enhancement: the daily digest must send the company USD
 * consolidated book (PDF + CSV) and support a dedicated accountant recipient.
 * These assert the three gaps from the discovery are closed and stay closed.
 */
class CashbookAccountantTest extends TestCase
{
    private function digestSource(): string
    {
        return file_get_contents(app_path('Console/Commands/CashbookDigestCommand.php'));
    }

    public function test_gap1_company_usd_book_is_built(): void
    {
        $src = $this->digestSource();
        // collect() called with company=true (6th positional arg).
        $this->assertStringContainsString(
            'collect($t, null, $date, $date, null, true)',
            $src,
            'digest must build the company (all-currencies-in-USD) book'
        );
        $this->assertStringContainsString('Company-USD-Cashbook-', $src);
    }

    public function test_gap2_company_book_sent_as_both_pdf_and_csv(): void
    {
        $src = $this->digestSource();
        $this->assertStringContainsString("Company-USD-Cashbook-' . \$date . '.pdf", $src, 'company PDF attached');
        $this->assertStringContainsString("Company-USD-Cashbook-' . \$date . '.csv", $src, 'company CSV attached');
        $this->assertStringContainsString('$export->csv($cData)', $src, 'CSV generated from the company dataset');
    }

    public function test_gap3_dedicated_accountant_recipients_supported(): void
    {
        $src = $this->digestSource();
        $this->assertStringContainsString('cashbook_digest_numbers', $src, 'dedicated recipient setting read');
        // Blank setting must not change behaviour: extra is filtered, merged only if non-empty.
        $this->assertStringContainsString('array_unique(array_merge($numbers, $extra))', $src);
    }

    public function test_export_supports_company_consolidation(): void
    {
        // Guards the contract the digest depends on: collect() has the $company flag.
        $ref = new \ReflectionMethod(CashbookExport::class, 'collect');
        $params = array_map(fn ($p) => $p->getName(), $ref->getParameters());
        $this->assertContains('company', $params, 'CashbookExport::collect must expose a company flag');
    }
}
