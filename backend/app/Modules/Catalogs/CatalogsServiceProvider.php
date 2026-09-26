<?php

declare(strict_types=1);

namespace App\Modules\Catalogs;

use App\Modules\Catalogs\Application\CatalogRegistry;
use App\Modules\Catalogs\Application\Contracts\AgencyServiceInterface;
use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Application\Contracts\CatalogServiceInterface;
use App\Modules\Catalogs\Application\Contracts\MunicipalityServiceInterface;
use App\Modules\Catalogs\Application\Services\AgencyService;
use App\Modules\Catalogs\Application\Services\CatalogService;
use App\Modules\Catalogs\Application\Services\MunicipalityService;
use App\Modules\Catalogs\Infrastructure\Persistence\EloquentCatalogRepository;
use App\Modules\Shared\Support\AuditableObserver;
use Illuminate\Support\ServiceProvider;

final class CatalogsServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12, ADR-15): Presentation and
     * Application depend on ports only. One generic repository port
     * covers the 18 catalog tables (the models share the standard
     * shape), while three service ports expose the generic catalog
     * use cases plus the specific municipality and agency rules.
     */
    public function register(): void
    {
        $this->app->bind(
            CatalogRepositoryInterface::class,
            EloquentCatalogRepository::class,
        );

        $this->app->bind(
            CatalogServiceInterface::class,
            CatalogService::class,
        );

        $this->app->bind(
            MunicipalityServiceInterface::class,
            MunicipalityService::class,
        );

        $this->app->bind(
            AgencyServiceInterface::class,
            AgencyService::class,
        );
    }

    public function boot(): void
    {
        // Authorship stamping (ADR-14) for every catalog table: the
        // registry is the single list of observed models, so a new
        // catalog entry is automatically covered without further
        // wiring. Laravel resolves the observer through the container
        // on each model event, so the Shared actor port is injected
        // transparently into every created_by/updated_by stamp.
        foreach (CatalogRegistry::observedModels() as $model) {
            $model::observe(AuditableObserver::class);
        }
    }
}
