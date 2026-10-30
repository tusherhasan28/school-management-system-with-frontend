<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {--name=} {--email=} {--role=owner}';

    protected $description = 'Create a platform (Super Admin) user';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = Str::lower($this->option('email') ?: text('Email', required: true));
        $secret = password('Password (min 12 characters)', required: true);

        $validator = Validator::make(
            ['email' => $email, 'password' => $secret, 'role' => $this->option('role')],
            [
                'email' => ['email', 'unique:platform_users,email'],
                'password' => [Password::min(12)->mixedCase()->numbers()->symbols()],
                'role' => ['in:'.implode(',', array_column(PlatformRole::cases(), 'value'))],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new PlatformUser(['name' => $name, 'email' => $email, 'password' => $secret]);
        $user->role = PlatformRole::from($this->option('role'));
        $user->email_verified_at = now();
        $user->save();

        $this->info("Platform user {$email} created with role {$user->role->value}.");

        return self::SUCCESS;
    }
}
