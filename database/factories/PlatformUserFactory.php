<?php

namespace Database\Factories;

use App\Enums\PlatformRole;
use App\Models\PlatformUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PlatformUser>
 */
class PlatformUserFactory extends Factory
{
    protected $model = PlatformUser::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => PlatformRole::Administrator,
            'status' => 'active',
            'email_verified_at' => now(),
        ];
    }

    public function role(PlatformRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
