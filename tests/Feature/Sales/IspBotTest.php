<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Bot\AiBrain;
use App\Services\Catalog\ProductWriteService;
use App\Services\Isp\IspReplyGuard;
use App\Services\Isp\IspSalesTurn;
use App\Services\Sales\Brain\SalesBrain;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer-facing bot on an Internet-provider business (DishNet plan, Phase 3) with the real
 * database: it is told only PUBLISHED Sales Brain knowledge, never the unapproved free-text settings,
 * and its words are guarded. Other business types are unchanged. Texts and prices are test values.
 */
class IspBotTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->reset();
        parent::tearDown();
    }

    private function business(string $vertical): Tenant
    {
        $t = Tenant::factory()->create(['settings' => ['vertical' => $vertical, 'currency' => 'USD', 'timezone' => 'Africa/Juba']]);
        app(TenantContext::class)->set($t->id);
        $t->putSetting('brand_knowledge', 'UNAPPROVED NOTE: fiber costs $10 everywhere.');

        return $t;
    }

    private function prompt(Tenant $t): string
    {
        $m = new \ReflectionMethod(AiBrain::class, 'systemPrompt');
        $m->setAccessible(true);
        app(TenantContext::class)->set($t->id);

        return (string) $m->invoke(app(AiBrain::class), $t);
    }

    private function publish(Tenant $t, string $topic, string $key, ?string $body, $data = null): void
    {
        $mgr = User::where('tenant_id', $t->id)->where('role', 'manager')->first()
            ?? User::create(['tenant_id' => $t->id, 'name' => 'Manager', 'role' => 'manager', 'email' => 'm' . $t->id . '@t.test', 'password' => 'secret']);
        $brain = app(SalesBrain::class);
        $r = $brain->startVersion($t, $mgr, $topic, $key, ucfirst($key), $body, $data);
        $this->assertTrue($r['ok'], json_encode($r));
        $brain->approve($t, $mgr, $r['version_id']);
        $brain->publish($t, $mgr, $r['version_id']);
    }

    public function test_the_bot_is_told_only_published_knowledge_and_never_the_unapproved_settings(): void
    {
        $t = $this->business('isp');
        $mgr = User::create(['tenant_id' => $t->id, 'name' => 'Manager', 'role' => 'manager', 'email' => 'mm@t.test', 'password' => 'secret']);
        app(SalesBrain::class)->startVersion($t, $mgr, 'faq', 'draft-only', 'Draft only', 'DRAFT TEXT NOT APPROVED');

        $before = $this->prompt($t);
        $this->assertStringContainsString('INTERNET SALES', $before);
        $this->assertStringContainsString('none is published yet', $before);
        $this->assertStringNotContainsString('DRAFT TEXT NOT APPROVED', $before, 'a draft never reaches the bot');
        $this->assertStringNotContainsString('UNAPPROVED NOTE', $before, 'free-text company knowledge skips approval, so it is left out');

        $this->publish($t, 'faq', 'install', 'Installation takes 2-5 days after payment.');
        $this->publish($t, 'coverage', 'fiber', null, ['areas' => [['name' => 'Test Town', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['test town']]]]);
        $after = $this->prompt($t);
        $this->assertStringContainsString('Installation takes 2-5 days after payment.', $after);
        $this->assertStringContainsString('Fiber — Covered: Test Town', $after);
    }

    /** Live, 21 Nov 2026: "The remaining wifi is not working" got "contact our technical support team" — no number. */
    public function test_a_customer_with_a_problem_gets_the_published_support_number(): void
    {
        $t = $this->business('isp');
        $this->publish($t, 'company', 'test-co', "Departments: Sales +211 900 000 001 (Mon–Sat 9 AM – 6 PM) · Technical Support +211 900 000 002 (Mon–Sun 7 AM – 10 PM).");

        $this->assertStringContainsString('In your FIRST reply give them the number now: Technical Support: +211 900 000 002 (Mon–Sun 7 AM – 10 PM)', $this->prompt($t));

        $turn = app(IspSalesTurn::class);
        $out = $turn->afterReply($t, '211911000001', 'The remaining wifi is not working',
            'I understand that your Wi-Fi is not working properly. To assist you better, I recommend contacting our technical support team.');
        $this->assertStringEndsWith("\u{1F4DE} Technical Support: +211 900 000 002 (Mon–Sun 7 AM – 10 PM)", $out);
        $this->assertSame('Starlink Mini suits you.', $turn->afterReply($t, '211911000001', 'hi', 'Starlink Mini suits you.'), 'a sales reply is untouched');
    }

    /** Live, 21 Nov 2026: "Connect me to the sales person" got the office line; their salesperson never heard. */
    public function test_a_customer_who_asks_for_the_salesperson_gets_theirs_and_the_salesperson_is_told(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\NotifyOwner::class]);
        $t = $this->business('isp');
        User::create(['tenant_id' => $t->id, 'name' => 'Akol Deng', 'role' => 'sales', 'phone' => '211922000001', 'email' => 'akol' . $t->id . '@t.test', 'password' => 'secret']);
        $turn = app(IspSalesTurn::class);

        $out = $turn->afterReply($t, '211911000009', 'Connect me to the sales person', 'Of course! You can reach our sales team at +211 923 400 000.');

        $this->assertStringEndsWith("\u{1F4DE} Your salesperson is *Akol Deng* (+211922000001): you can call or WhatsApp them directly.", $out);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\NotifyOwner::class, fn ($j) => $j->to === '211922000001' && str_contains($j->text, 'Customer asked to speak to you') && str_contains($j->text, 'Connect me to the sales person'));
        $this->assertStringContainsString("THIS CUSTOMER'S SALESPERSON: Akol Deng (+211922000001)", IspSalesTurn::salesBlock($t, '211911000009'), 'and from now on the bot knows who it is');

        $turn->afterReply($t, '211911000009', 'Please call me', 'Sure.');
        \Illuminate\Support\Facades\Bus::assertDispatchedTimes(\App\Jobs\NotifyOwner::class, 1);
        $this->assertSame('The Mini suits you.', $turn->afterReply($t, '211911000009', 'Which kit?', 'The Mini suits you.'), 'no ask, no change');
    }

    public function test_the_guard_keeps_catalogue_and_published_amounts_and_removes_the_rest(): void
    {
        $t = $this->business('isp');
        app(ProductWriteService::class)->create($t, ['name' => 'Test Kit', 'price' => 120, 'stock' => 5, 'billing' => 'one_off', 'service_line' => 'starlink']);
        $this->publish($t, 'faq', 'relocation', 'Moving the kit costs $75.');
        $this->publish($t, 'coverage', 'fiber', null, ['areas' => [['name' => 'Test Town', 'service_line' => 'fiber', 'status' => 'covered', 'keywords' => ['test town']]]]);

        $turn = app(IspSalesTurn::class);
        $this->assertSame('The kit is $120 and moving it later is $75.', $turn->afterReply($t, '211911000001', 'hi', 'The kit is $120 and moving it later is $75.'));
        $this->assertSame("Hello.\n\n" . IspReplyGuard::PRICE_NOTE, $turn->afterReply($t, '211911000001', 'hi', 'Hello. Monthly service is $49.'));
        $this->assertSame('Yes, Test Town is covered.', $turn->afterReply($t, '211911000001', 'hi', 'Yes, Test Town is covered.'));
        $this->assertSame(IspReplyGuard::COVERAGE_NOTE, $turn->afterReply($t, '211911000001', 'hi', 'We cover Gudele.'));
    }

    public function test_a_grocery_prompt_is_unchanged(): void
    {
        $t = $this->business('grocery');
        $p = $this->prompt($t);

        $this->assertStringNotContainsString('INTERNET SALES', $p);
        $this->assertStringContainsString('UNAPPROVED NOTE', $p, 'other businesses keep using their company knowledge setting');
    }
}
