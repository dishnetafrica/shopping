<?php
namespace Tests\Unit\Knowledge;

use App\Apps\DailyMenu\MenuExtractor;
use PHPUnit\Framework\TestCase;

class MenuExtractorTest extends TestCase
{
    private MenuExtractor $e;
    protected function setUp(): void { $this->e = new MenuExtractor(); }

    public function test_headed_meal_block(): void
    {
        $r = $this->e->extract("Lunch:\n- Gujarati Thali 25000\n- Veg Biryani 18000");
        $adds = array_filter($r->actions, fn ($a) => $a->actionType === 'add_menu_item');
        $this->assertCount(2, $adds);
        $first = array_values($adds)[0];
        $this->assertSame('lunch', $first->params['meal']);
        $this->assertSame('Gujarati Thali', $first->target);
        $this->assertSame(25000, $first->params['price']);
    }

    public function test_sold_out_special_and_no_meal(): void
    {
        $this->assertSame('mark_unavailable', $this->e->extract('Dhokla sold out')->actions[0]->actionType);
        $this->assertSame('add_special', $this->e->extract('Special: Kathiyawadi Thali 30000')->actions[0]->actionType);

        $r = $this->e->extract('No lunch tomorrow');
        $this->assertSame('clear_meal', $r->actions[0]->actionType);
        $this->assertSame('lunch', $r->actions[0]->params['meal']);
        $this->assertSame(date('Y-m-d', strtotime('+1 day')), $r->actions[0]->params['date']);
    }
}
