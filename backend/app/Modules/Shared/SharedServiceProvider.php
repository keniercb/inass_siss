<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Contracts\ClockInterface;
use App\Modules\Shared\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    // Module bindings, migrations, routes and policies are registered
    // here as each delivery phase of the development plan progresses.
    public function register(): void
    {
        $this->app->bind(ClockInterface::class, SystemClock::class);
    }

    public function boot(): void {}
}
