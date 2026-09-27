<?php

declare(strict_types=1);

namespace App\Modules\Organizations;

use App\Modules\Organizations\Application\Contracts\EntityRepositoryInterface;
use App\Modules\Organizations\Application\Contracts\EntityServiceInterface;
use App\Modules\Organizations\Application\Contracts\OfficeRepositoryInterface;
use App\Modules\Organizations\Application\Contracts\OfficeServiceInterface;
use App\Modules\Organizations\Application\Contracts\SignatureRepositoryInterface;
use App\Modules\Organizations\Application\Contracts\SignatureServiceInterface;
use App\Modules\Organizations\Application\Services\EntityService;
use App\Modules\Organizations\Application\Services\OfficeService;
use App\Modules\Organizations\Application\Services\SignatureService;
use App\Modules\Organizations\Infrastructure\Persistence\EloquentEntityRepository;
use App\Modules\Organizations\Infrastructure\Persistence\EloquentOfficeRepository;
use App\Modules\Organizations\Infrastructure\Persistence\EloquentSignatureRepository;
use App\Modules\Organizations\Infrastructure\Persistence\Models\AuthorizedSignature;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use App\Modules\Shared\Support\AuditableObserver;
use App\Modules\Shared\Support\AuditTrailObserver;
use Illuminate\Support\ServiceProvider;

final class OrganizationsServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12, ADR-22): Presentation and
     * Application depend on ports only. The services resolve the
     * structural rules through the pure Domain policies
     * (HierarchyPolicy for RN-003, SignatureStatus for the derived
     * state) and the reference probes through the public ports of
     * the Catalogs and People modules.
     */
    public function register(): void
    {
        $this->app->bind(
            EntityRepositoryInterface::class,
            EloquentEntityRepository::class,
        );

        $this->app->bind(
            EntityServiceInterface::class,
            EntityService::class,
        );

        $this->app->bind(
            OfficeRepositoryInterface::class,
            EloquentOfficeRepository::class,
        );

        $this->app->bind(
            OfficeServiceInterface::class,
            OfficeService::class,
        );

        $this->app->bind(
            SignatureRepositoryInterface::class,
            EloquentSignatureRepository::class,
        );

        $this->app->bind(
            SignatureServiceInterface::class,
            SignatureService::class,
        );
    }

    public function boot(): void
    {
        // Authorship stamping (ADR-14): created_by/updated_by of every
        // entity, office and signature are stamped by the Shared
        // observer, so the "who registered / who revoked" trail
        // exists from day one.
        Entity::observe(AuditableObserver::class);
        Office::observe(AuditableObserver::class);
        AuthorizedSignature::observe(AuditableObserver::class);

        // Activity trail (RF-AUD-001, ADR-19): every structural write —
        // including the deactivations and the signature revocation —
        // lands in the append-only bitácora with the previous and new
        // values.
        Entity::observe(AuditTrailObserver::class);
        Office::observe(AuditTrailObserver::class);
        AuthorizedSignature::observe(AuditTrailObserver::class);
    }
}
