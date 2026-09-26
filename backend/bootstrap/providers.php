<?php

use App\Modules\Catalogs\CatalogsServiceProvider;
use App\Modules\LegalBasis\LegalBasisServiceProvider;
use App\Modules\Organizations\OrganizationsServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\PensionCalculation\PensionCalculationServiceProvider;
use App\Modules\PensionCases\PensionCasesServiceProvider;
use App\Modules\Pensioners\PensionersServiceProvider;
use App\Modules\People\PeopleServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Security\SecurityServiceProvider;
use App\Modules\Settings\SettingsServiceProvider;
use App\Modules\Shared\SharedServiceProvider;
use App\Providers\AppServiceProvider;

/*
 * Module service providers of the SGP modular monolith.
 * Dependency order matters: Shared first, consumers afterwards
 * (rules enforced by deptrac, see backend/deptrac.yaml).
 */
return [
    AppServiceProvider::class,

    SharedServiceProvider::class,
    CatalogsServiceProvider::class,
    SettingsServiceProvider::class,
    PeopleServiceProvider::class,
    OrganizationsServiceProvider::class,
    LegalBasisServiceProvider::class,
    PensionCasesServiceProvider::class,
    PensionCalculationServiceProvider::class,
    PensionersServiceProvider::class,
    PaymentsServiceProvider::class,
    SecurityServiceProvider::class,
    ReportingServiceProvider::class,
];
