<?php

namespace Database\Factories;

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Models\SchoolDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' School';

        return [
            'name' => $name,
            'slug' => Str::slug(Str::limit($name, 40, '')).'-'.Str::lower(Str::random(4)),
            'institution_type' => 'school',
            'status' => SchoolStatus::Active,
            'email' => fake()->companyEmail(),
            'phone' => '01'.fake()->numerify('#########'),
            'district' => 'Dhaka',
            'division' => 'Dhaka',
            'timezone' => 'Asia/Dhaka',
            'locale' => 'en',
            'activated_at' => now(),
        ];
    }

    public function status(SchoolStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (School $school) {
            $domain = new SchoolDomain([
                'domain' => $school->slug.'.'.config('tenancy.domain'),
                'is_primary' => true,
            ]);
            $domain->school()->associate($school);
            $domain->save();
        });
    }
}
