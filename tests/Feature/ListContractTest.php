<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Table\ListBuilder;
use App\Support\Table\ListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Roadmap E8.1 — the shared server list contract. One engine drives search, whitelist filters/sort,
 * pagination and aggregates over the full filtered set. Unknown filter/sort fields are ignored (no
 * injection); aggregates are computed before paging.
 */
class ListContractTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): Tenant
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'Owner']);
        $this->actingAs($u);
        Product::create(['tenant_id' => $t->id, 'name' => 'Rice', 'sku' => 'RICE', 'stock' => 100, 'price' => 10, 'active' => true]);
        Product::create(['tenant_id' => $t->id, 'name' => 'Brown Rice', 'sku' => 'BRICE', 'stock' => 50, 'price' => 12, 'active' => true]);
        Product::create(['tenant_id' => $t->id, 'name' => 'Oil', 'sku' => 'OIL', 'stock' => 5, 'price' => 8, 'active' => false]);
        return $t;
    }

    private function spec(): array
    {
        return [
            'search'       => ['name', 'sku'],
            'filters'      => ['active' => 'active'],
            'sortable'     => ['name' => 'name', 'price' => 'price'],
            'default_sort' => ['column' => 'id', 'dir' => 'asc'],
            'aggregates'   => ['stock_total' => ['fn' => 'sum', 'column' => 'stock']],
        ];
    }

    private function req(array $q): ListQuery
    {
        return ListQuery::fromRequest(new Request($q));
    }

    public function test_search_matches_whitelisted_columns(): void
    {
        $t = $this->seed();
        $res = ListBuilder::apply(Product::where('tenant_id', $t->id), $this->req(['q' => 'rice']), $this->spec());
        $this->assertSame(2, $res->total); // Rice + Brown Rice
    }

    public function test_filter_and_aggregate_over_full_set(): void
    {
        $t = $this->seed();
        $res = ListBuilder::apply(Product::where('tenant_id', $t->id), $this->req(['filter' => ['active' => '1']]), $this->spec());
        $this->assertSame(2, $res->total);
        $this->assertSame(150.0, $res->aggregates['stock_total']); // 100 + 50, active only
    }

    public function test_unknown_filter_field_is_ignored(): void
    {
        $t = $this->seed();
        $res = ListBuilder::apply(Product::where('tenant_id', $t->id), $this->req(['filter' => ['evil' => 'x']]), $this->spec());
        $this->assertSame(3, $res->total); // filter dropped, all rows
    }

    public function test_sort_and_pagination(): void
    {
        $t = $this->seed();
        $res = ListBuilder::apply(Product::where('tenant_id', $t->id),
            $this->req(['sort' => 'price', 'dir' => 'desc', 'per_page' => 2, 'page' => 1]), $this->spec());
        $this->assertSame(3, $res->total);
        $this->assertSame(2, $res->pages());
        $this->assertCount(2, $res->rows);
        $this->assertSame('Brown Rice', $res->rows[0]->name); // highest price (12) first
    }

    public function test_list_query_clamps_per_page(): void
    {
        $lq = ListQuery::fromRequest(new Request(['per_page' => 9999]), 25, 200);
        $this->assertSame(200, $lq->perPage);
        $lq2 = ListQuery::fromRequest(new Request(['per_page' => 0]), 25, 200);
        $this->assertSame(25, $lq2->perPage);
    }
}
