<?php

declare(strict_types=1);

namespace App\Modules\Security;

use App\Modules\Organizations\Application\Contracts\OfficeAssignmentQueryInterface;
use App\Modules\Security\Application\Authentication\SecurityPolicies;
use App\Modules\Security\Application\Contracts\AuditLogQueryInterface;
use App\Modules\Security\Application\Contracts\AuthServiceInterface;
use App\Modules\Security\Application\Contracts\PermissionServiceInterface;
use App\Modules\Security\Application\Contracts\PermissionUsageQueryInterface;
use App\Modules\Security\Application\Contracts\RoleRepositoryInterface;
use App\Modules\Security\Application\Contracts\RoleServiceInterface;
use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Application\Contracts\UserServiceInterface;
use App\Modules\Security\Application\Services\AuthService;
use App\Modules\Security\Application\Services\PermissionService;
use App\Modules\Security\Application\Services\RoleService;
use App\Modules\Security\Application\Services\UserService;
use App\Modules\Security\Infrastructure\Audit\EloquentAuditLogQuery;
use App\Modules\Security\Infrastructure\Authentication\AuthenticatedUserIdProvider;
use App\Modules\Security\Infrastructure\Persistence\EloquentPermissionUsageQuery;
use App\Modules\Security\Infrastructure\Persistence\EloquentRoleRepository;
use App\Modules\Security\Infrastructure\Persistence\EloquentUserRepository;
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Security\Infrastructure\Persistence\OfficeAssignmentQuery;
use App\Modules\Shared\Contracts\ClockInterface;
use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use App\Modules\Shared\Support\AuditableObserver;
use App\Modules\Shared\Support\AuditTrailObserver;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\ServiceProvider;

final class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12): Presentation and Application
     * depend on ports only. The service contracts receive the use
     * case implementations and the repository contract receives the
     * Eloquent adapter, so tests can rebind fakes for either and a
     * future store change never touches business logic (DIP,
     * architecture doc section 7).
     *
     * The hardening policies (password/lockout, ADR-24) are rebuilt
     * from configuration on EVERY resolution: the domain values stay
     * pure while runtime config overrides apply to the next request.
     */
    public function register(): void
    {
        $this->app->bind(
            AuthServiceInterface::class,
            fn ($app): AuthService => new AuthService(
                $app->make(UserRepositoryInterface::class),
                $app->make(Hasher::class),
                $app->make(ClockInterface::class),
                SecurityPolicies::lockoutFromConfig(),
                SecurityPolicies::passwordFromConfig(),
            ),
        );

        $this->app->bind(
            UserServiceInterface::class,
            fn ($app): UserService => new UserService(
                $app->make(UserRepositoryInterface::class),
                $app->make(ClockInterface::class),
                SecurityPolicies::passwordFromConfig(),
            ),
        );

        $this->app->bind(
            UserRepositoryInterface::class,
            EloquentUserRepository::class,
        );

        // Cross-module read projection (ADR-29): Organizations owns
        // the office deactivation guard but must not import Security,
        // so it declares the port and this module binds the count of
        // active users per office.
        $this->app->bind(
            OfficeAssignmentQueryInterface::class,
            OfficeAssignmentQuery::class,
        );

        // Role management (RF-SEG-002, ADR-26): custom roles bundle
        // permission subsets of the matrix catalog; the institutional
        // five stay immutable.
        $this->app->bind(
            RoleServiceInterface::class,
            RoleService::class,
        );

        $this->app->bind(
            RoleRepositoryInterface::class,
            EloquentRoleRepository::class,
        );

        // Permission catalog (RF-SEG-002, ADR-27): read-only surface
        // that answers to the matrix with live usage projections from
        // the spatie pivots.
        $this->app->bind(
            PermissionServiceInterface::class,
            PermissionService::class,
        );

        $this->app->bind(
            PermissionUsageQueryInterface::class,
            EloquentPermissionUsageQuery::class,
        );

        // Audit actor port (ADR-14): stamping needs to know who performs
        // every write; the port is Shared so any module's observer can
        // consume it without depending on Security (deptrac topology).
        $this->app->bind(
            CurrentUserProviderInterface::class,
            AuthenticatedUserIdProvider::class,
        );

        // Audit trail read port (RF-AUD-003, ADR-19): the bitácora is
        // append-only, so the query surface is the single contract.
        $this->app->bind(
            AuditLogQueryInterface::class,
            EloquentAuditLogQuery::class,
        );
    }

    public function boot(): void
    {
        // Audit stamping (ADR-14): Laravel resolves the observer through
        // the container on each model event, so the actor port above is
        // injected transparently into every created_by/updated_by stamp.
        User::observe(AuditableObserver::class);

        // Activity trail (RF-AUD-001, ADR-19): user account writes land
        // in the append-only bitácora too (toda escritura crítica), with
        // secret columns redacted (RedactsAuditAttributes, ADR-24).
        User::observe(AuditTrailObserver::class);

        // Role writes join the same trail (ADR-26): row events land
        // through the observer, permission pivots — invisible to
        // Eloquent events — through the explicit entries the role
        // repository records with the previous grant set.
        Role::observe(AuditTrailObserver::class);
    }
}
