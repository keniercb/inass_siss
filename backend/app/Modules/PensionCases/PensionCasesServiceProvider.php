<?php

declare(strict_types=1);

namespace App\Modules\PensionCases;

use App\Modules\PensionCases\Application\Contracts\PensionCaseRepositoryInterface;
use App\Modules\PensionCases\Application\Contracts\PensionCaseServiceInterface;
use App\Modules\PensionCases\Application\Services\PensionCaseService;
use App\Modules\PensionCases\Infrastructure\Persistence\EloquentPensionCaseRepository;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use App\Modules\Shared\Support\AuditableObserver;
use App\Modules\Shared\Support\AuditTrailObserver;
use Illuminate\Support\ServiceProvider;

final class PensionCasesServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12, S5): Presentation and Application
     * depend on ports only. The service resolves the creation rules
     * through the public ports of People (eligibility), Organizations
     * (office/entity probes), Catalogs (reference probes) and the
     * Shared sequences/clock/transaction boundary; the subrecord
     * rules live in the pure Domain analysis values.
     */
    public function register(): void
    {
        $this->app->bind(
            PensionCaseRepositoryInterface::class,
            EloquentPensionCaseRepository::class,
        );

        $this->app->bind(
            PensionCaseServiceInterface::class,
            PensionCaseService::class,
        );
    }

    public function boot(): void
    {
        // Authorship stamping (ADR-14): who registered/edited the
        // case. The subrecord rows carry no authorship columns by
        // model design — the aggregate owns them and the trail keeps
        // their values.
        PensionCase::observe(AuditableObserver::class);

        // Activity trail (RF-AUD-001, ADR-19): every case write and
        // every subrecord high/removal lands in the append-only
        // bitácora with the previous and new values.
        PensionCase::observe(AuditTrailObserver::class);
        SalaryRecord::observe(AuditTrailObserver::class);
        ServiceRecord::observe(AuditTrailObserver::class);
        WorkCycle::observe(AuditTrailObserver::class);
    }
}
