<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Restores User::factory() — required by ~39 existing tests. tenant_id defaults to a fresh Tenant so
 * User::factory()->create() works standalone; tests that pass tenant_id/role/name override as usual.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name'      => $this->faker->name(),
            'email'     => $this->faker->unique()->safeEmail(),
            'phone'     => $this->faker->unique()->numerify('2119#######'),
            'password'  => Hash::make('password'),
            'role'      => 'owner',
        ];
    }
}
