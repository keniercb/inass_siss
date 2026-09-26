<?php

namespace App\Modules\Security\Infrastructure\Persistence\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Eloquent model backing the Security user aggregate (RF-SEG-001).
 *
 * Lives in the module's Infrastructure layer: Eloquent never leaks
 * to Application contracts beyond type references nor to
 * Presentation beyond serialization (architecture doc section 6,
 * ADR-11). Authentication business rules live in
 * Application\Services\AuthService.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Resolves the factory for this model. Overridden because the
     * model no longer lives in App\Models, so the default
     * factory-name convention does not apply.
     */
    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }
}
