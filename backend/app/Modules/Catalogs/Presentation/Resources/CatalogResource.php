<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Presentation\Resources;

use App\Modules\Catalogs\Application\CatalogRegistry;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin CatalogModel
 *
 * @property-read int|null $id
 * @property-read string|null $name
 * @property-read string|null $code
 * @property-read string|null $description
 * @property-read CarbonImmutable|null $deleted_at
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[OA\Schema(
    schema: 'CatalogItem',
    title: 'Entrada de catálogo',
    description: 'Entrada de un catálogo uniforme servida por /api/v1/catalogs/{type}. Los campos opcionales dependen del catálogo.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'code', type: 'string', nullable: true, example: 'EDAD', description: 'Clave natural inmutable presente en TODOS los catálogos (Task 31); null solo en filas legadas previas al código'),
        new OA\Property(property: 'name', type: 'string', example: 'Por edad'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Pensión por edad'),
        new OA\Property(property: 'months_per_year', type: 'integer', nullable: true, example: 12, description: 'Solo pension-regimes'),
        new OA\Property(property: 'applies_base_salary', type: 'boolean', nullable: true, example: true, description: 'Solo income-concepts'),
        new OA\Property(property: 'sector', type: 'integer', nullable: true, example: 2, description: 'Solo pension-regimes (Task 38): sector opcional del régimen de jubilación, devuelto por todos los endpoints'),
        new OA\Property(property: 'deceased_person', type: 'boolean', nullable: false, example: false, description: 'Solo pension-types (Task 38): persona fallecida, booleano con default false, devuelto por todos los endpoints'),
        new OA\Property(property: 'deactivated_at', type: 'string', format: 'date-time', nullable: true, description: 'Borrado lógico (RF-CAT-001)'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
final class CatalogResource extends JsonResource
{
    /**
     * Serializes the entry from its CatalogDefinition, so optional
     * columns appear exactly where the registry says they exist.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = CatalogRegistry::definitionForModel($this->resource::class);
        $hasCode = $definition !== null && $definition->hasCode;
        $hasDescription = $definition !== null && $definition->hasDescription;
        $extraColumns = $definition === null ? [] : array_keys($definition->extraRules);

        $data = [
            'id' => $this->id,
            'name' => $this->name,
        ];

        if ($hasCode) {
            $data['code'] = $this->code;
        }

        if ($hasDescription) {
            $data['description'] = $this->description;
        }

        foreach ($extraColumns as $column) {
            $data[$column] = $this->{$column};
        }

        $data['deactivated_at'] = $this->deleted_at?->toISOString();
        $data['created_at'] = $this->created_at?->toISOString();
        $data['updated_at'] = $this->updated_at?->toISOString();

        return $data;
    }
}
