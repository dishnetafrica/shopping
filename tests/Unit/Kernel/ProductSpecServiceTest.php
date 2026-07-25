<?php declare(strict_types=1);

namespace Tests\Unit\Kernel;

use App\Services\Catalog\ProductSpecService as S;
use Tests\TestCase;

/**
 * Pure value-coercion for product specs (no DB). Proves numbers are stored numerically (for range
 * filtering) while text/enum keep their display value, booleans normalise, and blanks clear.
 * Lives under tests/…/Kernel so it is covered by the green gate.
 */
class ProductSpecServiceTest extends TestCase
{
    public function test_number_stores_both_text_and_number(): void
    {
        $c = S::coerce('number', '550 W');
        $this->assertSame('550 W', $c['value_text']);
        $this->assertSame(550.0, $c['value_number']);
    }

    public function test_number_handles_thousands_and_decimals(): void
    {
        $this->assertSame(1200.0, S::coerce('number', '1,200')['value_number']);
        $this->assertSame(3.14, S::coerce('number', '3.14 kg')['value_number']);
        $this->assertSame(-5.0, S::coerce('number', '-5')['value_number']);
    }

    public function test_text_and_enum_keep_display_value_only(): void
    {
        $t = S::coerce('text', 'IP65');
        $this->assertSame('IP65', $t['value_text']);
        $this->assertNull($t['value_number']);

        $e = S::coerce('enum', 'LiFePO4');
        $this->assertSame('LiFePO4', $e['value_text']);
        $this->assertNull($e['value_number']);
    }

    public function test_boolean_normalises(): void
    {
        $this->assertSame('Yes', S::coerce('boolean', 'yes')['value_text']);
        $this->assertSame(1.0, S::coerce('boolean', 'true')['value_number']);
        $this->assertSame('No', S::coerce('boolean', 'nope')['value_text']);
    }

    public function test_blank_clears(): void
    {
        $this->assertNull(S::coerce('number', '')['value_text']);
        $this->assertNull(S::coerce('text', '   ')['value_text']);
        $this->assertNull(S::coerce('text', null)['value_text']);
    }

    public function test_parse_number_and_key_from(): void
    {
        $this->assertSame(550.0, S::parseNumber('550 W'));
        $this->assertNull(S::parseNumber('n/a'));
        $this->assertSame('battery_chemistry', S::keyFrom('Battery Chemistry'));
        $this->assertSame('ip_rating', S::keyFrom('IP Rating'));
    }
}
