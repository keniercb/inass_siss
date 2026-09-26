<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use Illuminate\Support\ServiceProvider;

final class PaymentsServiceProvider extends ServiceProvider
{
    // Module bindings, migrations, routes and policies are registered
    // here as each delivery phase of the development plan progresses.
    public function register(): void {}

    public function boot(): void {}
}
