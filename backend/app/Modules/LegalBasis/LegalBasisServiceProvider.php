<?php

declare(strict_types=1);

namespace App\Modules\LegalBasis;

use App\Modules\LegalBasis\Application\Contracts\LegalBasisRepositoryInterface;
use App\Modules\LegalBasis\Application\Contracts\LegalBasisServiceInterface;
use App\Modules\LegalBasis\Application\Services\LegalBasisService;
use App\Modules\LegalBasis\Infrastructure\Persistence\EloquentLegalBasisRepository;
use App\Modules\LegalBasis\Infrastructure\Persistence\Models\LegalBasis;
use App\Modules\Shared\Support\AuditableObserver;
use App\Modules\Shared\Support\AuditTrailObserver;
use Illuminate\Support\ServiceProvider;

final class LegalBasisServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12, ADR-23): Presentation and
     * Application depend on ports only. The service derives the year
     * from the issue date (H-11) and validates the RN-006 ordering
     * before persisting; the derived validity (RF-LEG-003) is
     * resolved by the Domain LegalBasisStatus against the Shared
     * Clock.
     */
    public function register(): void
    {
        $this->app->bind(
            LegalBasisRepositoryInterface::class,
            EloquentLegalBasisRepository::class,
        );

        $this->app->bind(
            LegalBasisServiceInterface::class,
            LegalBasisService::class,
        );
    }

    public function boot(): void
    {
        // Authorship stamping (ADR-14): created_by/updated_by of every
        // legal basis are stamped by the Shared observer, so the
        // "who registered / who corrected the derogation" trail
        // exists from day one.
        LegalBasis::observe(AuditableObserver::class);

        // Activity trail (RF-AUD-001, ADR-19): every corpus write —
        // including the derogation date edits and the deactivation —
        // lands in the append-only bitácora with the previous and
        // new values.
        LegalBasis::observe(AuditTrailObserver::class);
    }
}
