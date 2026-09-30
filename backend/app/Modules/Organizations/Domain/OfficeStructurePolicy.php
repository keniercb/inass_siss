<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain;

/**
 * Territorial structure of the office hierarchy (ADR-31): the
 * NAC/PRO/MUN triad seeded in the office type catalog forms a
 * three-level tree whose shape is not negotiable — a single
 * national office, a single provincial office per province, a
 * single municipal office per province and municipality, and the
 * parent chain fixed by the type (provincial -> national,
 * municipal -> provincial of the same province). Registering a
 * provincial office requires the national one to exist, and a
 * municipal office requires the provincial of its province.
 *
 * The policy is pure: it answers from the type code and the plain
 * geographic ids, so the rules are provable without a database and
 * the OfficeService only orchestrates the lookups. Types outside
 * the seeded triad are not territorial: they keep the generic
 * optional parent of RN-003 and no uniqueness, so the catalog can
 * grow without this policy kidnapping it.
 */
final class OfficeStructurePolicy
{
    public const TYPE_NATIONAL = 'NAC';

    public const TYPE_PROVINCIAL = 'PRO';

    public const TYPE_MUNICIPAL = 'MUN';

    /**
     * Whether an office type belongs to the territorial triad and
     * therefore follows the forced chain and the per-scope
     * uniqueness.
     */
    public static function isTerritorialType(string $officeTypeCode): bool
    {
        return in_array($officeTypeCode, [self::TYPE_NATIONAL, self::TYPE_PROVINCIAL, self::TYPE_MUNICIPAL], true);
    }

    /**
     * The type code an office of the given type must depend on: the
     * provincial offices depend on the national one and the
     * municipal ones on the provincial of their province, while the
     * national office is the root and depends on nothing. Only
     * meaningful for territorial types.
     */
    public static function parentTypeCode(string $officeTypeCode): ?string
    {
        return match ($officeTypeCode) {
            self::TYPE_PROVINCIAL => self::TYPE_NATIONAL,
            self::TYPE_MUNICIPAL => self::TYPE_PROVINCIAL,
            default => null,
        };
    }

    /**
     * The uniqueness scope of an office as the repository lookup
     * that detects it: the national office is unique country-wide,
     * the provincial one per province and the municipal one per
     * province and municipality. Non-territorial types carry no
     * scope (empty narrowing).
     *
     * @return array{type_code: string, province_id: int|null, municipality_id: int|null}
     */
    public static function uniquenessScope(string $officeTypeCode, int $provinceId, int $municipalityId): array
    {
        return match ($officeTypeCode) {
            self::TYPE_NATIONAL => [
                'type_code' => self::TYPE_NATIONAL,
                'province_id' => null,
                'municipality_id' => null,
            ],
            self::TYPE_PROVINCIAL => [
                'type_code' => self::TYPE_PROVINCIAL,
                'province_id' => $provinceId,
                'municipality_id' => null,
            ],
            self::TYPE_MUNICIPAL => [
                'type_code' => self::TYPE_MUNICIPAL,
                'province_id' => $provinceId,
                'municipality_id' => $municipalityId,
            ],
            default => [
                'type_code' => $officeTypeCode,
                'province_id' => null,
                'municipality_id' => null,
            ],
        };
    }
}
