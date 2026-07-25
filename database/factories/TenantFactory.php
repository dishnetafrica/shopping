<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Restores Tenant::factory() — required by ~85 existing tests (the factories/ directory was missing,
 * which is why so much of the suite could not run). Minimal, valid tenant; override any field per test.
 *
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name'   => $name,
            'slug'   => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'plan'   => 'basic',
        ];
    }
}
