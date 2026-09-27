<?php

declare(strict_types=1);

namespace App\Modules\Security;

use App\Modules\Security\Application\Contracts\AuditLogQueryInterface;
use App\Modules\Security\Application\Contracts\AuthServiceInterface;
use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Application\Services\AuthService;
use App\Modules\Security\Infrastructure\Audit\EloquentAuditLogQuery;
use App\Modules\Security\Infrastructure\Authentication\AuthenticatedUserIdProvider;
use App\Modules\Security\Infrastructure\Persistence\EloquentUserRepository;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use App\Modules\Shared\Support\AuditableObserver;
use App\Modules\Shared\Support\AuditTrailObserver;
use Illuminate\Support\ServiceProvider;

final class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12): Presentation and Application
     * depend on ports only. The service contract receives the use
     * case implementation and the repository contract receives the
     * Eloquent adapter, so tests can rebind fakes for either and a
     * future store change never touches business logic (DIP,
     * architecture doc section 7).
     */
    public function register(): void
    {
        $this->app->bind(
            AuthServiceInterface::class,
            AuthService::class,
        );

        $this->app->bind(
            UserRepositoryInterface::class,
            EloquentUserRepository::class,
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
        // in the append-only bitácora too (toda escritura crítica).
        User::observe(AuditTrailObserver::class);
    }
}
