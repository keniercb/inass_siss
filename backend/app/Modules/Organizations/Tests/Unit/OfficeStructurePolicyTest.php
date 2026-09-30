<?php

declare(strict_types=1);

// Estructura territorial de las oficinas (S4.1): la política pura
// codifica la cadena nacional -> provincial -> municipal y la
// unicidad por ámbito: una sola oficina nacional, una provincial por
// provincia y una municipal por provincia y municipio. Los tipos
// fuera de la tríada sembrada (NAC/PRO/MUN) conservan la jerarquía
// opcional genérica de RN-003, así que el catálogo puede crecer sin
// que esta política lo secuestre. Todo es dato plano: la política es
// demostrable sin base de datos.

use App\Modules\Organizations\Domain\OfficeStructurePolicy;

describe('OfficeStructurePolicy (estructura territorial)', function () {
    it('reconoce la tríada territorial sembrada', function () {
        expect(OfficeStructurePolicy::isTerritorialType('NAC'))->toBeTrue()
            ->and(OfficeStructurePolicy::isTerritorialType('PRO'))->toBeTrue()
            ->and(OfficeStructurePolicy::isTerritorialType('MUN'))->toBeTrue();
    });

    it('no secuestra tipos ajenos a la tríada', function () {
        expect(OfficeStructurePolicy::isTerritorialType('REG'))->toBeFalse()
            ->and(OfficeStructurePolicy::isTerritorialType(''))->toBeFalse()
            ->and(OfficeStructurePolicy::isTerritorialType('nac'))->toBeFalse();
    });

    it('la oficina nacional es la raíz: no depende de nadie', function () {
        expect(OfficeStructurePolicy::parentTypeCode('NAC'))->toBeNull();
    });

    it('las provinciales dependen de la nacional', function () {
        expect(OfficeStructurePolicy::parentTypeCode('PRO'))->toBe('NAC');
    });

    it('las municipales dependen de la provincial de su provincia', function () {
        expect(OfficeStructurePolicy::parentTypeCode('MUN'))->toBe('PRO');
    });

    it('la nacional es única en todo el país', function (array $office) {
        $scope = OfficeStructurePolicy::uniquenessScope('NAC', $office['province_id'], $office['municipality_id']);

        expect($scope)->toBe([
            'type_code' => 'NAC',
            'province_id' => null,
            'municipality_id' => null,
        ]);
    })->with([[['office_type_id' => 1, 'province_id' => 12, 'municipality_id' => 42]]]);

    it('la provincial es única por provincia', function () {
        $scope = OfficeStructurePolicy::uniquenessScope('PRO', 12, 42);

        expect($scope)->toBe([
            'type_code' => 'PRO',
            'province_id' => 12,
            'municipality_id' => null,
        ]);
    });

    it('la municipal es única por provincia y municipio', function () {
        $scope = OfficeStructurePolicy::uniquenessScope('MUN', 12, 42);

        expect($scope)->toBe([
            'type_code' => 'MUN',
            'province_id' => 12,
            'municipality_id' => 42,
        ]);
    });

    it('es pura: nunca muta los argumentos escalares', function () {
        $provinceId = 12;
        $municipalityId = 42;

        $scope = OfficeStructurePolicy::uniquenessScope('MUN', $provinceId, $municipalityId);

        expect($scope['province_id'])->toBe(12)
            ->and($scope['municipality_id'])->toBe(42)
            ->and($provinceId)->toBe(12)
            ->and($municipalityId)->toBe(42);
    });
});
