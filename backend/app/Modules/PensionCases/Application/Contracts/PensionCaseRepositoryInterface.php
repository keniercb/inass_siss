<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Contracts;

use App\Modules\PensionCases\Domain\CaseStatus;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\IncomeConceptRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port of the PensionCases module (ADR-11). Presentation
 * and Application code type-hint this contract; only Infrastructure
 * knows about Eloquent. Signatures follow the module's use cases —
 * search, detail with subrecords, open-case probe, atomic creation
 * with subrecords and the subrecord highs/removals — instead of a
 * generic CRUD surface.
 */
interface PensionCaseRepositoryInterface
{
    /**
     * Basic listing (RF-EXP-011 shape: status, office, person, date
     * range, number). Tuned search and volume arrive in S6. The
     * applicant travels EAGERLY with every row (user rule 3: the
     * listing answers the FULL promovente projection).
     *
     * @param  array{status?: CaseStatus, office_id?: int, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string}  $filters
     * @return LengthAwarePaginator<int, PensionCase>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    public function find(int $id): ?PensionCase;

    /**
     * Detail projection: subrecords and the applicant eagerly
     * loaded so the response and the warning analysis never trigger
     * lazy queries.
     */
    public function findDetailed(int $id): ?PensionCase;

    /**
     * The open (non-terminal) case of a person, if any — the S5.1
     * "one open case per person" probe. Soft-deleted cases are
     * excluded here like every other listing.
     */
    public function findOpenCaseForPerson(int $personId): ?PensionCase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PensionCase;

    /**
     * Atomic subrecord creation for the S5.5 all-or-nothing flow:
     * the caller drives the TransactionManager, the repository just
     * inserts the rows it is given (already validated).
     *
     * @param  list<array{year: int, earned_salary: string}>  $rows
     */
    public function createSalaryRecords(PensionCase $case, array $rows): void;

    /**
     * @param  list<array{entity_id: int, start_date: string, end_date: string|null, is_appendix: bool, declaration_form: string}>  $rows
     */
    public function createServiceRecords(PensionCase $case, array $rows): void;

    /**
     * @param  list<array{planned_days: int, actual_days: int, cycles_count: int}>  $rows
     */
    public function createWorkCycles(PensionCase $case, array $rows): void;

    /**
     * Atomic income concept creation for the all-or-nothing flow
     * (user rule 5): the caller drives the TransactionManager, the
     * repository just inserts the rows it is given (already
     * validated).
     *
     * @param  list<array{income_concept_id: int, amount: string}>  $rows
     */
    public function createIncomeConceptRecords(PensionCase $case, array $rows): void;

    public function addSalaryRecord(PensionCase $case, int $year, string $earnedSalary): SalaryRecord;

    public function removeSalaryRecord(PensionCase $case, int $recordId): bool;

    /**
     * @param  array{entity_id: int, start_date: string, end_date: string|null, is_appendix: bool, declaration_form?: string}  $attributes
     */
    public function addServiceRecord(PensionCase $case, array $attributes): ServiceRecord;

    public function removeServiceRecord(PensionCase $case, int $recordId): bool;

    /**
     * @param  array{planned_days: int, actual_days: int, cycles_count: int}  $attributes
     */
    public function addWorkCycle(PensionCase $case, array $attributes): WorkCycle;

    public function removeWorkCycle(PensionCase $case, int $recordId): bool;

    public function addIncomeConceptRecord(PensionCase $case, int $incomeConceptId, string $amount): IncomeConceptRecord;

    public function removeIncomeConceptRecord(PensionCase $case, int $recordId): bool;

    /**
     * Semantic probe of the (case, year) UNIQUE before the insert
     * (RN-008 convention): answers a 422 instead of a driver error.
     */
    public function salaryYearExists(int $caseId, int $year): bool;

    /**
     * Semantic probe of the (case, concept) UNIQUE (user rule 5):
     * same RN-008 convention — a 422 instead of a driver error.
     */
    public function incomeConceptExists(int $caseId, int $incomeConceptId): bool;

    /**
     * Live salary rows of the case — the FIFTEEN row ceiling (user
     * rule 1) counts what stands, not what was declared and removed.
     */
    public function countSalaryRecords(int $caseId): int;
}
