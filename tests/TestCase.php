<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case. This file was MISSING — without it every test that does `use Tests\TestCase;`
 * (StockLedgerTest, InvoicePayTest, …) fails to load, so the suite could not run at all. Restoring
 * it is a P0 reliability fix on its own (Constitution v1.0 · Reliability → "Restore testing").
 *
 * Laravel 11's Illuminate\Foundation\Testing\TestCase supplies createApplication() from bootstrap/app.php,
 * so no CreatesApplication trait is needed.
 */
abstract class TestCase extends BaseTestCase
{
}
