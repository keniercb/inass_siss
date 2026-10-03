<?php

declare(strict_types=1);

use App\Modules\Catalogs\Application\CatalogRegistry;
use App\Modules\Catalogs\Application\Exceptions\UnknownCatalogException;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Agency;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;

// ---------------------------------------------------------------------------
// Registry integrity
// ---------------------------------------------------------------------------

it('exposes the fifteen uniform catalogs of phase 1', function () {
    expect(CatalogRegistry::keys())->toBe([
        'provinces',
        'agency-types',
        'organizations',
        'entity-types',
        'office-types',
        'legal-basis-types',
        'scientific-categories',
        'educational-levels',
        'occupational-categories',
        'pension-types',
        'beneficiary-types',
        'races',
        'positions',
        'pension-regimes',
        'income-concepts',
    ]);
});

it('resolves every definition to an existing catalog model', function (string $key) {
    $definition = CatalogRegistry::definition($key);

    expect(class_exists($definition->model))->toBeTrue()
        ->and(is_subclass_of($definition->model, CatalogModel::class))->toBeTrue()
        ->and(CatalogRegistry::has($key))->toBeTrue()
        ->and($definition->model)->toBeString()
        ->and($definition->fillableColumns())->toContain('name');
})->with(CatalogRegistry::keys());

it('rejects unknown catalogs and the dedicated resources', function (string $key) {
    expect(CatalogRegistry::has($key))->toBeFalse();
})->with([
    'municipalities',
    'agencies',
    'bank-controls',
    'unknown-thing',
    // Task 42 (SGP-36): the payment_types catalog was ELIMINATED —
    // its key answers like any other unknown catalog now.
    'payment-types',
]);

it('fails with a typed exception for unknown catalogs', function () {
    CatalogRegistry::definition('unknown-thing');
})->throws(UnknownCatalogException::class);

// ---------------------------------------------------------------------------
// Models
// ---------------------------------------------------------------------------

it('observes the seventeen catalog models for authorship stamping', function () {
    $models = CatalogRegistry::observedModels();

    expect($models)->toHaveLength(17)
        ->and($models)->toContain(Agency::class)
        ->and($models)->not->toContain(CatalogModel::class)
        ->and(array_unique($models))->toHaveLength(17);
});

it('gives every catalog model an audit-ready fillable set', function (string $model) {
    /** @var class-string<CatalogModel> $model */
    $reflection = new ReflectionClass($model);
    $fillable = $reflection->getDefaultProperties()['fillable'] ?? null;

    expect($fillable)->toBeArray()
        ->and($fillable)->toContain('code')
        ->and($fillable)->toContain('name')
        ->and($fillable)->toContain('created_by')
        ->and($fillable)->toContain('updated_by');
})->with(CatalogRegistry::observedModels());

it('marks the catalogs with code as such', function (string $key, bool $hasCode) {
    expect(CatalogRegistry::definition($key)->hasCode)->toBe($hasCode);
})->with([
    ['provinces', true],
    ['agency-types', true],
    ['pension-types', true],
    ['races', true],
    ['pension-regimes', true],
    ['income-concepts', true],
    ['educational-levels', true],
    ['beneficiary-types', true],
    ['positions', true],
]);

it('declares the reference guards of the geographic catalogs', function () {
    $provinces = CatalogRegistry::definition('provinces');
    $agencyTypes = CatalogRegistry::definition('agency-types');

    expect($provinces->dependents)->toHaveCount(2)
        ->and(array_values($provinces->dependents))->toContain('province_id')
        ->and($agencyTypes->dependents)->toHaveCount(1)
        ->and(CatalogRegistry::definition('races')->dependents)->toBe([]);
});

// Task 42 (user correction, SGP-36): the agency types carry the
// payment form of the collection — the lowercase-unified enum the
// user fixed, riding the generic extraRules machinery.
it('declares the payment form rule of the agency types', function () {
    expect(CatalogRegistry::definition('agency-types')->extraRules)->toBe([
        'payment_form' => 'in:tarjeta magnetica,nomina electronica',
    ]);
});
