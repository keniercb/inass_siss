<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application;

use App\Modules\Catalogs\Application\DTO\CatalogDefinition;
use App\Modules\Catalogs\Application\Exceptions\UnknownCatalogException;

/**
 * Single source of truth for the uniform catalogs exposed through the
 * generic resource /api/v1/catalogs/{type} (ADR-15).
 *
 * Municipalities and agencies are deliberately NOT listed here: they
 * carry specific business rules (nested natural key, RN-04 coherence)
 * and therefore get dedicated services and endpoints
 * (RF-CAT-002, RF-CAT-003).
 *
 * The registry lives in the Application layer, not in Presentation,
 * so both the request rules and the service invariants are driven by
 * the same map; it is pure data and never touches the database.
 */
final class CatalogRegistry
{
    private const MODELS = 'App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\';

    /** @var array<string, CatalogDefinition>|null */
    private static ?array $definitions = null;

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    public static function has(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    /**
     * @throws UnknownCatalogException when the key is not a known catalog
     */
    public static function definition(string $key): CatalogDefinition
    {
        $definition = self::definitions()[$key] ?? null;

        if ($definition === null) {
            throw new UnknownCatalogException($key);
        }

        return $definition;
    }

    /**
     * Reverse lookup used by the generic CatalogResource to know which
     * optional columns the serialized model carries. Returns null for
     * the dedicated models (municipalities, agencies), which have
     * their own resources.
     */
    public static function definitionForModel(string $modelClass): ?CatalogDefinition
    {
        foreach (self::definitions() as $definition) {
            if ($definition->model === $modelClass) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Models that must be observed for authorship stamping (ADR-14):
     * the module provider iterates this list, so a new catalog entry
     * is automatically covered by the observer without further wiring.
     *
     * @return list<class-string>
     */
    public static function observedModels(): array
    {
        $models = [];

        foreach (self::definitions() as $definition) {
            $models[] = $definition->model;
        }

        $models[] = self::MODELS.'Municipality';
        $models[] = self::MODELS.'Agency';

        return $models;
    }

    /**
     * @return array<string, CatalogDefinition>
     */
    private static function definitions(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $definitions = [
            'provinces' => new CatalogDefinition(
                key: 'provinces',
                label: 'provinces',
                model: self::MODELS.'Province',
                hasCode: true,
                codeMax: 4,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [
                    self::MODELS.'Municipality' => 'province_id',
                    self::MODELS.'Agency' => 'province_id',
                ],
            ),
            'agency-types' => new CatalogDefinition(
                key: 'agency-types',
                label: 'agency types',
                model: self::MODELS.'AgencyType',
                hasCode: true,
                codeMax: 4,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [
                    self::MODELS.'Agency' => 'agency_type_id',
                ],
            ),
            'organizations' => new CatalogDefinition(
                key: 'organizations',
                label: 'organizations',
                model: self::MODELS.'Organization',
                hasCode: true,
                codeMax: 10,
                nameMax: 120,
                hasDescription: false,
                extraRules: [],
                dependents: [],
            ),
            'entity-types' => new CatalogDefinition(
                key: 'entity-types',
                label: 'entity types',
                model: self::MODELS.'EntityType',
                hasCode: true,
                codeMax: 10,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [],
            ),
            'office-types' => new CatalogDefinition(
                key: 'office-types',
                label: 'office types',
                model: self::MODELS.'OfficeType',
                hasCode: true,
                codeMax: 10,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [],
            ),
            'legal-basis-types' => new CatalogDefinition(
                key: 'legal-basis-types',
                label: 'legal basis types',
                model: self::MODELS.'LegalBasisType',
                hasCode: true,
                codeMax: 10,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [],
            ),
            'scientific-categories' => new CatalogDefinition(
                key: 'scientific-categories',
                label: 'scientific categories',
                model: self::MODELS.'ScientificCategory',
                hasCode: true,
                codeMax: 10,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [],
            ),
            'educational-levels' => new CatalogDefinition(
                key: 'educational-levels',
                label: 'educational levels',
                model: self::MODELS.'EducationalLevel',
                hasCode: false,
                codeMax: 0,
                nameMax: 80,
                hasDescription: true,
                extraRules: [],
                dependents: [],
            ),
            'occupational-categories' => new CatalogDefinition(
                key: 'occupational-categories',
                label: 'occupational categories',
                model: self::MODELS.'OccupationalCategory',
                hasCode: true,
                codeMax: 10,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [],
            ),
            'pension-types' => new CatalogDefinition(
                key: 'pension-types',
                label: 'pension types',
                model: self::MODELS.'PensionType',
                hasCode: true,
                codeMax: 10,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [],
            ),
            'beneficiary-types' => new CatalogDefinition(
                key: 'beneficiary-types',
                label: 'beneficiary types',
                model: self::MODELS.'BeneficiaryType',
                hasCode: false,
                codeMax: 0,
                nameMax: 80,
                hasDescription: true,
                extraRules: [],
                dependents: [],
            ),
            'races' => new CatalogDefinition(
                key: 'races',
                label: 'races',
                model: self::MODELS.'Race',
                hasCode: false,
                codeMax: 0,
                nameMax: 80,
                hasDescription: false,
                extraRules: [],
                dependents: [],
            ),
            'positions' => new CatalogDefinition(
                key: 'positions',
                label: 'positions',
                model: self::MODELS.'Position',
                hasCode: false,
                codeMax: 0,
                nameMax: 80,
                hasDescription: true,
                extraRules: [],
                dependents: [],
            ),
            'pension-regimes' => new CatalogDefinition(
                key: 'pension-regimes',
                label: 'pension regimes',
                model: self::MODELS.'PensionRegime',
                hasCode: false,
                codeMax: 0,
                nameMax: 80,
                hasDescription: true,
                extraRules: ['months_per_year' => 'required|integer|min:1|max:12'],
                dependents: [],
            ),
            'payment-types' => new CatalogDefinition(
                key: 'payment-types',
                label: 'payment types',
                model: self::MODELS.'PaymentType',
                hasCode: false,
                codeMax: 0,
                nameMax: 80,
                hasDescription: true,
                extraRules: [],
                dependents: [],
            ),
            'income-concepts' => new CatalogDefinition(
                key: 'income-concepts',
                label: 'income concepts',
                model: self::MODELS.'IncomeConcept',
                hasCode: false,
                codeMax: 0,
                nameMax: 80,
                hasDescription: true,
                extraRules: ['applies_base_salary' => 'boolean'],
                dependents: [],
            ),
        ];

        self::$definitions = $definitions;

        return $definitions;
    }
}
