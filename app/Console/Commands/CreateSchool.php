<?php

namespace App\Console\Commands;

use App\Enums\SchoolStatus;
use App\Services\Tenancy\SchoolProvisioner;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Provision a school from the command line until the onboarding UI
 * (Milestone 2) is available.
 */
class CreateSchool extends Command
{
    protected $signature = 'school:create
        {slug : Subdomain, e.g. dhaka-model}
        {--name= : School name}
        {--type=school : school|college|kindergarten|madrasa|combined|other}
        {--owner-name=} {--owner-email=}
        {--status=trial : trial|active}';

    protected $description = 'Provision a school with its subdomain, default roles and owner account';

    public function handle(SchoolProvisioner $provisioner): int
    {
        try {
            $school = $provisioner->provision(
                [
                    'slug' => $this->argument('slug'),
                    'name' => $this->option('name') ?: text('School name', required: true),
                    'institution_type' => $this->option('type'),
                ],
                [
                    'name' => $this->option('owner-name') ?: text('Owner name', required: true),
                    'email' => $this->option('owner-email') ?: text('Owner email', required: true),
                    'password' => password('Owner password', required: true),
                ],
                SchoolStatus::from($this->option('status')),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("School created: {$school->url()}");
        if (config('tenancy.manual_subdomains')) {
            $this->warn("Add the subdomain {$school->slug}.".config('tenancy.domain').' in your hosting panel (same folder as the main domain), then wait for its SSL certificate.');
        }

        return self::SUCCESS;
    }
}
