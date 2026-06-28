<?php
namespace Tests\Unit\Orders;

use App\Apps\Orders\Extract\TimeParser;
use PHPUnit\Framework\TestCase;

class TimeParserTest extends TestCase
{
    public function test_parses_clock_and_relative_dates(): void
    {
        $this->assertSame('08:00', TimeParser::detect('sharp 8 am')['time']);
        $this->assertSame('20:30', TimeParser::detect('8:30pm')['time']);
        $tomorrow = TimeParser::detect('tomorrow 8 am');
        $this->assertSame('08:00', $tomorrow['time']);
        $this->assertSame(date('Y-m-d', strtotime('+1 day')), $tomorrow['date']);
    }
}
