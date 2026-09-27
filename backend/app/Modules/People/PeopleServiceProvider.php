<?php

declare(strict_types=1);

namespace App\Modules\People;

use App\Modules\People\Application\Contracts\PeopleRepositoryInterface;
use App\Modules\People\Application\Contracts\PeopleServiceInterface;
use App\Modules\People\Application\Services\PeopleService;
use App\Modules\People\Infrastructure\Persistence\EloquentPeopleRepository;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use App\Modules\Shared\Support\AuditableObserver;
use App\Modules\Shared\Support\AuditTrailObserver;
use Illuminate\Support\ServiceProvider;

final class PeopleServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12, ADR-20): Presentation and
     * Application depend on ports only. The service resolves the
     * duplicate verdicts through the pure Domain policy and "now"
     * through the Shared Clock port, so both stay testable.
     */
    public function register(): void
    {
        $this->app->bind(
            PeopleRepositoryInterface::class,
            EloquentPeopleRepository::class,
        );

        $this->app->bind(
            PeopleServiceInterface::class,
            PeopleService::class,
        );
    }

    public function boot(): void
    {
        // Authorship stamping (ADR-14): created_by/updated_by of every
        // person are stamped by the Shared observer, so the "who
        // registered / who corrected" trail exists from day one.
        Person::observe(AuditableObserver::class);

        // Activity trail (RF-AUD-001, ADR-19): every person write —
        // including the death registration and the correction of its
        // date — lands in the append-only bitácora with the previous
        // and new values (RF-PER-002/003).
        Person::observe(AuditTrailObserver::class);
    }
}
