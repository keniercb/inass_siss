<?php

declare(strict_types=1);

// CaseStatus (sección 2.4 de requisitos): el catálogo normativo de
// estados del expediente. La MATRIZ de transiciones — qué estado admite
// pasar a cuál — llega en S6 como dataset de Pest primero (plan 7.4),
// así que este enum hoy solo fija los valores y las dos propiedades
// que S5 necesita: qué estados admiten edición de subregistros
// (solo `submitted`, el estado previo a la revisión) y cuáles son
// terminales.

namespace App\Modules\PensionCases\Tests\Unit;

use App\Modules\PensionCases\Domain\CaseStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaseStatus::class)]
final class CaseStatusTest extends TestCase
{
    /** @return list<array{string}> */
    public static function validValues(): array
    {
        return [
            ['submitted'],
            ['under_review'],
            ['approved'],
            ['rejected'],
        ];
    }

    #[DataProvider('validValues')]
    public function test_backs_every_normative_value(string $value): void
    {
        self::assertSame($value, CaseStatus::from($value)->value);
    }

    public function test_rejects_values_outside_the_catalog(): void
    {
        $this->expectException(\ValueError::class);

        // The 2.4 matrix has no draft/closed state: inventing one is
        // a domain error, not a business flow.
        CaseStatus::from('draft');
    }

    /** @return array<string, array{CaseStatus, bool}> */
    public static function editableStates(): array
    {
        return [
            'submitted is the only editable state' => [CaseStatus::Submitted, true],
            'under review locks the subrecords' => [CaseStatus::UnderReview, false],
            'approved locks the subrecords' => [CaseStatus::Approved, false],
            'rejected locks the subrecords' => [CaseStatus::Rejected, false],
        ];
    }

    #[DataProvider('editableStates')]
    public function test_only_the_pre_review_state_is_editable(CaseStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isEditable());
    }

    /** @return array<string, array{CaseStatus, bool}> */
    public static function terminalStates(): array
    {
        return [
            'submitted is not terminal' => [CaseStatus::Submitted, false],
            'under review is not terminal' => [CaseStatus::UnderReview, false],
            'approved is terminal' => [CaseStatus::Approved, true],
            'rejected is terminal' => [CaseStatus::Rejected, true],
        ];
    }

    #[DataProvider('terminalStates')]
    public function test_terminals_are_the_two_resolution_states(CaseStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isTerminal());
    }
}
