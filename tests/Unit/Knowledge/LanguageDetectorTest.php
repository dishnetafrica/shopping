<?php
namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\Observatory\LanguageDetector;
use PHPUnit\Framework\TestCase;

class LanguageDetectorTest extends TestCase
{
    public function test_detects_script(): void
    {
        $this->assertSame('en', LanguageDetector::detect('Tea 5000'));
        $this->assertSame('gu', LanguageDetector::detect('ચા ૫૦૦૦'));
        $this->assertSame('gujlish', LanguageDetector::detect('Tea ચા 5000'));
    }
}
