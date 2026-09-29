<?php

namespace App\Modules\Security\Infrastructure\Persistence\Models;

use Carbon\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Eloquent model backing the Security role aggregate (RF-SEG-002,
 * ADR-26): the spatie Role extended with the management columns.
 *
 * Extends the package model instead of using it directly so the
 * whole module (repositories, observers, bitácora subjects) resolves
 * one class owned by Security: subject_type entries in the activity
 * trail and the roles.* pivots always point here, and the columns
 * the management surface owns (description, is_system) are
 * documented in one place. Registered through the package
 * configuration (permission.models.role), which every spatie
 * internal resolves at runtime.
 *
 * is_system marks the five institutional roles of section 2.2: they
 * are immutable through the API because the PermissionMatrix is
 * their single source of truth; custom roles are fully manageable.
 * The users relation stays the package morph: guard resolution and
 * pivot wiring are spatie's, only the columns above are ours.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string|null $description
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $users_count
 */
class Role extends SpatieRole
{
    /**
     * Pins the guard this whole module operates on. Without it a
     * bare `new static` (query builders, relation resolution) falls
     * back to Guard::getDefaultName, which reads config auth.defaults.
     * guard — a value the auth:sanctum middleware MUTATES to sanctum
     * for the rest of the process during any authenticated request
     * (AuthManager::shouldUse), leaving the users() morph unresolved
     * (model for the sanctum guard: null). The property short-circuits
     * that resolution: every Role instance, hydrated or not, always
     * belongs to the web guard the PermissionMatrix seeds.
     */
    protected $guard_name = 'web';

    /**
     * The attributes that are mass assignable. The spatie base keeps
     * the table/guard wiring; the management surface adds its own
     * two columns while the primary key stays guarded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'description',
        'is_system',
    ];
}
