<?php

namespace Database\Seeders;

use App\Enums\PlatformRole;
use App\Enums\SchoolStatus;
use App\Models\PlatformUser;
use App\Services\Tenancy\SchoolProvisioner;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo data for local development only. Refuses to run in production.
 * All demo accounts use the password "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(SchoolProvisioner $provisioner): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo seed data is only allowed in local/testing environments.');
        }

        $admin = new PlatformUser(['name' => 'Platform Owner', 'email' => 'owner@'.config('tenancy.domain'), 'password' => 'password']);
        $admin->role = PlatformRole::Owner;
        $admin->email_verified_at = now();
        $admin->save();

        $provisioner->provision(
            ['slug' => 'dhaka-model', 'name' => 'Dhaka Model School', 'name_bn' => 'ঢাকা মডেল স্কুল', 'institution_type' => 'school', 'locale' => 'bn'],
            ['name' => 'Rahim Uddin', 'email' => 'principal@dhaka-model.test', 'password' => 'password'],
            SchoolStatus::Active,
        );

        $provisioner->provision(
            ['slug' => 'ctg-madrasa', 'name' => 'Chattogram Alia Madrasa', 'name_bn' => 'চট্টগ্রাম আলিয়া মাদ্রাসা', 'institution_type' => 'madrasa', 'locale' => 'bn'],
            ['name' => 'Karim Hossain', 'email' => 'principal@ctg-madrasa.test', 'password' => 'password'],
            SchoolStatus::Trial,
        );
    }
}
