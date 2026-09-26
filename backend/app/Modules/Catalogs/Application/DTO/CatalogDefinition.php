<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\DTO;

use App\Modules\Catalogs\Application\CatalogRegistry;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;

/**
 * Immutable description of one uniform catalog (ADR-15).
 *
 * Declared in the Application layer so every participant asks the same
 * source of truth: Presentation builds request rules from it, the
 * generic CatalogService enforces the business invariants it declares
 * (immutable code, unique natural keys, deactivation guards) and the
 * Eloquent repository resolves the model class-string it carries.
 *
 * @see CatalogRegistry
 */
final class CatalogDefinition
{
    /**
     * @param  class-string<CatalogModel>  $model
     * @param  array<string, string>  $extraRules  Laravel validation rules for type-specific columns
     * @param  array<string, string>  $dependents  Dependent model class-string => foreign key column
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $model,
        public readonly bool $hasCode,
        public readonly int $codeMax,
        public readonly int $nameMax,
        public readonly bool $hasDescription,
        public readonly array $extraRules,
        public readonly array $dependents,
    ) {}

    /**
     * Columns that may be filled from a request payload.
     *
     * @return list<string>
     */
    public function fillableColumns(): array
    {
        $columns = $this->hasCode ? ['code'] : [];
        $columns[] = 'name';

        if ($this->hasDescription) {
            $columns[] = 'description';
        }

        return [...$columns, ...array_keys($this->extraRules)];
    }

    /**
     * Request validation rules for the payload of this catalog,
     * format-level only (required, types, lengths). Natural-key
     * uniqueness and code immutability live in the CatalogService,
     * where they can be tested without HTTP.
     *
     * @return array<string, string|array<string>>
     */
    public function storeRules(): array
    {
        $rules = [
            'name' => 'required|string|max:'.$this->nameMax,
        ];

        if ($this->hasCode) {
            $rules['code'] = 'required|string|alpha_num|max:'.$this->codeMax;
        }

        if ($this->hasDescription) {
            $rules['description'] = 'nullable|string|max:255';
        }

        return [...$rules, ...$this->extraRules];
    }

    /**
     * Same as storeRules() but every field is optional (PATCH
     * semantics); the code stays immutable either way.
     *
     * @return array<string, string|array<string>>
     */
    public function updateRules(): array
    {
        $rules = [
            'name' => 'sometimes|string|max:'.$this->nameMax,
        ];

        if ($this->hasCode) {
            $rules['code'] = 'sometimes|string|alpha_num|max:'.$this->codeMax;
        }

        if ($this->hasDescription) {
            $rules['description'] = 'sometimes|nullable|string|max:255';
        }

        return [...$rules, ...$this->extraRules];
    }
}
