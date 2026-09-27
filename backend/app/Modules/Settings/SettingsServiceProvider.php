<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Modules\Settings\Application\Contracts\GeneralSettingsRepositoryInterface;
use App\Modules\Settings\Application\Contracts\GeneralSettingsServiceInterface;
use App\Modules\Settings\Application\Services\GeneralSettingsService;
use App\Modules\Settings\Infrastructure\Persistence\EloquentGeneralSettingsRepository;
use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use App\Modules\Settings\Infrastructure\Persistence\Models\NumberingSequence;
use App\Modules\Settings\Infrastructure\Persistence\MysqlSequenceGenerator;
use App\Modules\Settings\Presentation\Console\EmitSequenceCommand;
use App\Modules\Shared\Contracts\SequenceGeneratorInterface;
use App\Modules\Shared\Support\AuditableObserver;
use App\Modules\Shared\Support\AuditTrailObserver;
use Illuminate\Support\ServiceProvider;

final class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Module wiring (ADR-11, ADR-12, ADR-16, ADR-17): Presentation and
     * Application depend on ports only. The service resolves the
     * vigencia in force through the pure Domain resolver and the
     * Shared Clock port, so "now" stays testable. The resolver has no
     * dependencies and is auto-resolved by the container.
     */
    public function register(): void
    {
        // ADR-17: dedicated sequences session, cloned from the default
        // MySQL connection (same server and schema, independent
        // session). Sequence increments commit here so they survive
        // business rollbacks: RN-009 — numbers are never reused, gaps
        // are accepted by design. Cloning instead of duplicating the
        // config block keeps a single source of connection truth.
        config()->set(
            'database.connections.'.NumberingSequence::CONNECTION_NAME,
            config('database.connections.mysql'),
        );

        $this->app->bind(
            GeneralSettingsRepositoryInterface::class,
            EloquentGeneralSettingsRepository::class,
        );

        $this->app->bind(
            GeneralSettingsServiceInterface::class,
            GeneralSettingsService::class,
        );

        // ADR-17: the Shared port resolves to the MySQL adapter owned
        // by this module; consumer modules (PensionCases, Payments)
        // type-hint the port and never learn about Settings.
        $this->app->bind(
            SequenceGeneratorInterface::class,
            MysqlSequenceGenerator::class,
        );
    }

    public function boot(): void
    {
        // Authorship stamping (ADR-14): created_by/updated_by of every
        // settings version are stamped by the Shared observer, so the
        // "who froze these parameters" trail exists from day one.
        GeneralSetting::observe(AuditableObserver::class);

        // Activity trail (RF-AUD-001, ADR-19): every settings write is
        // recorded in the append-only bitácora with old and new values.
        GeneralSetting::observe(AuditTrailObserver::class);

        // ADR-17: emission probe used by the concurrency test and by
        // operations (each run burns real numbers, RN-009).
        $this->commands([
            EmitSequenceCommand::class,
        ]);
    }
}
