<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Contracts;

use App\Modules\PensionCases\Application\Exceptions\CaseNotEditableException;
use App\Modules\PensionCases\Application\Exceptions\DuplicateSalaryYearException;
use App\Modules\PensionCases\Application\Exceptions\OpenCaseExistsException;
use App\Modules\PensionCases\Application\Exceptions\PersonNotEligibleException;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Use cases of the PensionCases module for Sprint 5 (RF-EXP-001..004):
 * case creation with sequential number and atomic subrecords, the
 * subrecord highs/removals gated by the editable state, and the
 * advisory analysis the responses carry (missing salary years,
 * overlapping and open services).
 *
 * Missing cases surface as null — the controller translates that to
 * HTTP 404; the service layer never speaks HTTP.
 */
interface PensionCaseServiceInterface
{
    /**
     * Creates the case (RF-EXP-001) with its sequential number and,
     * when the payload carries them, its subrecords — everything or
     * nothing (plan S5.5): the number may end up burned by a
     * rollback, which RN-009 accepts by design.
     *
     * @param  array<string, mixed>  $attributes  case fields plus the optional
     *                                            salary_records / service_records / work_cycles arrays
     *
     * @throws PersonNotEligibleException deceased or deactivated applicant (422)
     * @throws OpenCaseExistsException the person already holds an open case (409)
     * @throws DuplicateSalaryYearException a repeated year against stored rows (422)
     */
    public function create(array $attributes): PensionCase;

    /**
     * @param  array{status?: string, office_id?: int, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string}  $filters
     * @return LengthAwarePaginator<int, PensionCase>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /**
     * Detail projection with subrecords and applicant loaded.
     */
    public function get(int $id): ?PensionCase;

    /**
     * Advisory analysis of the declared evidence (RF-EXP-002/003):
     * missing interior salary years, overlapping service pairs and
     * services still without an end date. Pure reading of the loaded
     * relations (call get() first) — never a write, never a block.
     *
     * @return array{missing_salary_years: list<int>, overlapping_services: list<array{int, int}>, open_services: list<int>}
     */
    public function warnings(PensionCase $case): array;

    /**
     * @return null when the case does not exist (controller: 404)
     *
     * @throws CaseNotEditableException case already left submitted (409)
     * @throws DuplicateSalaryYearException year already declared (422)
     */
    public function addSalaryRecord(int $caseId, int $year, string $earnedSalary): ?SalaryRecord;

    /**
     * @return null when the case does not exist; false when the row was not found
     *
     * @throws CaseNotEditableException
     */
    public function removeSalaryRecord(int $caseId, int $recordId): ?bool;

    /**
     * @param  array{entity_id: int, start_date: string, end_date: string|null, is_appendix: bool}  $attributes
     * @return null when the case does not exist (controller: 404)
     *
     * @throws CaseNotEditableException
     */
    public function addServiceRecord(int $caseId, array $attributes): ?ServiceRecord;

    /**
     * @return null when the case does not exist; false when the row was not found
     *
     * @throws CaseNotEditableException
     */
    public function removeServiceRecord(int $caseId, int $recordId): ?bool;

    /**
     * @param  array{planned_days: int, actual_days: int, cycles_count: int}  $attributes
     * @return null when the case does not exist (controller: 404)
     *
     * @throws CaseNotEditableException
     */
    public function addWorkCycle(int $caseId, array $attributes): ?WorkCycle;

    /**
     * @return null when the case does not exist; false when the row was not found
     *
     * @throws CaseNotEditableException
     */
    public function removeWorkCycle(int $caseId, int $recordId): ?bool;
}
