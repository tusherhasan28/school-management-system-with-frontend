<?php

namespace App\Models;

use App\Enums\PlatformRole;
use Database\Factories\PlatformUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Platform (Super Admin) staff. Separate table and guard from tenant users.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property PlatformRole $role
 * @property string $status
 */
class PlatformUser extends Authenticatable
{
    /** @use HasFactory<PlatformUserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'role' => PlatformRole::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasPlatformAbility(string $ability): bool
    {
        return $this->isActive() && $this->role->allows($ability);
    }
}
