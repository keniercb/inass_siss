<?php

namespace App\Modules\Security\Infrastructure\Persistence\Models;

use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Shared\Contracts\RedactsAuditAttributes;
use Database\Factories\UserFactory;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Eloquent model backing the Security user aggregate (RF-SEG-001,
 * RF-SEG-002). Roles and permissions arrive through the spatie
 * pivot tables materialized from the PermissionMatrix domain value
 * (ADR-05, ADR-18).
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
 * @property int $failed_login_attempts
 * @property DateTimeImmutable|null $locked_at
 * @property DateTimeImmutable|null $password_changed_at
 * @property int|null $person_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Person|null $person
 * @property DateTimeImmutable|null $deleted_at
 */
class User extends Authenticatable implements RedactsAuditAttributes
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'failed_login_attempts',
        'locked_at',
        'password_changed_at',
        'created_by',
        'updated_by',
    ];

    /**
     * Explicit storage date format: the immutable casts resolve it
     * without a database connection (unit tests build bare models),
     * matching MySQL's datetime precision.
     */
    protected $dateFormat = 'Y-m-d H:i:s';

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
            'deleted_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'failed_login_attempts' => 'integer',
            // Immutable casts (ADR-24): the lockout/password lifecycle
            // domain values consume DateTimeImmutable, and CarbonImmutable
            // satisfies that contract without conversion glue.
            'locked_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Natural person behind the account (S3.5, RF-SEG-004): every
     * user may link to one registered person for action
     * traceability; the association is unique at the database level.
     * withTrashed: a deactivated person stays registered (its
     * identity is reserved), so the link keeps pointing at it.
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id')->withTrashed();
    }

    /**
     * Account that created this record (audit trail, ADR-14).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    /**
     * Account that last modified this record (audit trail, ADR-14).
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(self::class, 'updated_by');
    }

    /**
     * Secret columns that must never reach the bitácora (ADR-24):
     * the audit trail keeps the FACT that the password changed with
     * a [redacted] marker instead of the hash, because the log is
     * readable by the Auditor role (RF-AUD-003).
     *
     * @return list<string>
     */
    public function auditRedactedAttributes(): array
    {
        return ['password', 'remember_token'];
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
