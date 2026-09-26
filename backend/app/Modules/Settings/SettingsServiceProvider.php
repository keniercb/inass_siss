<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Modules\Settings\Application\Contracts\GeneralSettingsRepositoryInterface;
use App\Modules\Settings\Application\Contracts\GeneralSettingsServiceInterface;
use App\Modules\Settings\Application\Services\GeneralSettingsService;
use App\Modules\Settings\Infrastructure\Persistence\EloquentGeneralSettingsRepository;
use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use App\Modules\Shared\Support\AuditableObserver;
use Illuminate\Support\ServiceProvider;

final class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12, ADR-16): Presentation and
     * Application depend on ports only. The service resolves the
     * vigencia in force through the pure Domain resolver and the
     * Shared Clock port, so "now" stays testable. The resolver has no
     * dependencies and is auto-resolved by the container.
     */
    public function register(): void
    {
        $this->app->bind(
            GeneralSettingsRepositoryInterface::class,
            EloquentGeneralSettingsRepository::class,
        );

        $this->app->bind(
            GeneralSettingsServiceInterface::class,
            GeneralSettingsService::class,
        );
    }

    public function boot(): void
    {
        // Authorship stamping (ADR-14): created_by/updated_by of every
        // settings version are stamped by the Shared observer, so the
        // "who froze these parameters" trail exists from day one.
        GeneralSetting::observe(AuditableObserver::class);
    }
}
