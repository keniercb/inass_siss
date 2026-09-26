<?php

declare(strict_types=1);

namespace App\Modules\Security;

use App\Modules\Security\Application\Contracts\AuthServiceInterface;
use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Application\Services\AuthService;
use App\Modules\Security\Infrastructure\Persistence\EloquentUserRepository;
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
    }

    public function boot(): void {}
}
