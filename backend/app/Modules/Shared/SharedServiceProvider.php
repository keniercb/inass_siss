<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Contracts\ClockInterface;
use App\Modules\Shared\Contracts\TransactionManager;
use App\Modules\Shared\Support\AuditRecorder;
use App\Modules\Shared\Support\DatabaseTransactionManager;
use App\Modules\Shared\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    // Module bindings, migrations, routes and policies are registered
    // here as each delivery phase of the development plan progresses.
    public function register(): void
    {
        $this->app->bind(ClockInterface::class, SystemClock::class);

        // Transaction boundary (S5.5): use cases that persist several
        // rows atomically receive the port instead of calling the
        // framework directly, keeping Application agnostic.
        $this->app->bind(TransactionManager::class, DatabaseTransactionManager::class);

        // Shared audit writer (ADR-19/ADR-24): one entry shape for the
        // Eloquent observer and for module-owned writes (role pivots).
        // Bound as a singleton: stateless, and observers plus repos
        // resolve it repeatedly on every write.
        $this->app->singleton(AuditRecorder::class);
    }

    public function boot(): void {}
}
