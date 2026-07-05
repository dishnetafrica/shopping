<?php

namespace Tests\Unit\Marketplace;

use App\Services\Marketplace\Support\ImageHostAllowList;
use PHPUnit\Framework\TestCase;

/**
 * Load-bearing guardrail: the bot must never forward an image from an
 * unapproved host. Pure unit test.
 */
class ImageHostAllowListTest extends TestCase
{
    private array $hosts = ['*.jumia.is', 'ug.jumia.is'];

    public function test_subdomain_wildcard_allowed(): void
    {
        $this->assertTrue(ImageHostAllowList::allowed('https://ug.jumia.is/a.jpg', $this->hosts));
        $this->assertTrue(ImageHostAllowList::allowed('https://sc.jumia.is/b.png', $this->hosts));
    }

    public function test_exact_host_allowed(): void
    {
        $this->assertTrue(ImageHostAllowList::allowed('https://ug.jumia.is/a.jpg', ['ug.jumia.is']));
    }

    public function test_lookalike_suffix_is_blocked(): void
    {
        $this->assertFalse(ImageHostAllowList::allowed('https://jumia.is.evil.com/a.jpg', $this->hosts));
    }

    public function test_arbitrary_host_blocked(): void
    {
        $this->assertFalse(ImageHostAllowList::allowed('https://randomcdn.example/a.jpg', $this->hosts));
    }

    public function test_non_http_scheme_blocked(): void
    {
        $this->assertFalse(ImageHostAllowList::allowed('ftp://ug.jumia.is/a.jpg', $this->hosts));
        $this->assertFalse(ImageHostAllowList::allowed('javascript:alert(1)', $this->hosts));
    }

    public function test_empty_inputs_blocked(): void
    {
        $this->assertFalse(ImageHostAllowList::allowed('', $this->hosts));
        $this->assertFalse(ImageHostAllowList::allowed('https://ug.jumia.is/a.jpg', []));
    }
}
