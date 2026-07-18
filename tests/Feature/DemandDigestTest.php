<?php

namespace Tests\Feature;

use App\Jobs\NotifyOwner;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** demand:digest (2026-07-18): customers write the purchase list; mornings deliver it. */
class DemandDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_aggregates_counts_and_notifies_owner_plus_team(): void
    {
        Queue::fake();
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('relationship_memory', true);
        $t->putSetting('demand_digest_numbers', '211926190900');
        $t->putSetting('learning_log', [
            now()->subDays(3)->toDateString() => ['gaps' => [], 'demand' => ['Dettol 500ml']],
            now()->subDays(2)->toDateString() => ['gaps' => [], 'demand' => ['Dettol 500ml', 'Red Bull 250ml']],
            now()->subDay()->toDateString()   => ['gaps' => [], 'demand' => ['Dettol 500ml', 'Baby wipes']],
        ]);

        $this->artisan('demand:digest', ['--tenant' => $t->id])->assertExitCode(0);

        Queue::assertPushed(NotifyOwner::class, 2);   // owner + the purchaser number
        Queue::assertPushed(NotifyOwner::class, function ($j) {
            return str_contains($j->text, "3× dettol 500ml")
                && str_contains($j->text, 'Baby wipes')
                && str_contains($j->text, 'wanted to pay us');
        });
    }

    public function test_nothing_to_say_means_no_message_at_all(): void
    {
        Queue::fake();
        $t = Tenant::factory()->create();
        app(\App\Support\TenantContext::class)->set($t->id);
        $t->putSetting('relationship_memory', true);
        $t->putSetting('learning_log', [now()->subDay()->toDateString() => ['gaps' => ['x'], 'demand' => []]]);

        $this->artisan('demand:digest', ['--tenant' => $t->id])->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
