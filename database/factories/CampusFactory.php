<?php

namespace Database\Factories;

use App\Modules\Setup\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campus>
 */
class CampusFactory extends Factory
{
    protected $model = Campus::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Campus',
            'code' => strtoupper(fake()->unique()->bothify('C-###??')),
            'address' => fake()->address(),
            'phone' => '01'.fake()->numerify('#########'),
            'is_main' => false,
        ];
    }
}
