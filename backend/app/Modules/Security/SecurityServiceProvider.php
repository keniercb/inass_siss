<?php

declare(strict_types=1);

namespace App\Modules\Security;

use App\Modules\Security\Application\Contracts\UserRepositoryInterface;
use App\Modules\Security\Infrastructure\Persistence\EloquentUserRepository;
use Illuminate\Support\ServiceProvider;

final class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11): Application and Presentation depend on
     * the repository port; the Eloquent implementation is bound here,
     * so tests can rebind an in-memory fake and a future store change
     * never touches business logic (DIP, architecture doc section 7).
     */
    public function register(): void
    {
        $this->app->bind(
            UserRepositoryInterface::class,
            EloquentUserRepository::class,
        );
    }

    public function boot(): void {}
}
