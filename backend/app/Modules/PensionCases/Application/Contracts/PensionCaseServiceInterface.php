<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Contracts;

use App\Modules\PensionCases\Application\Exceptions\CaseNotEditableException;
use App\Modules\PensionCases\Application\Exceptions\DuplicateIncomeConceptException;
use App\Modules\PensionCases\Application\Exceptions\DuplicateSalaryYearException;
use App\Modules\PensionCases\Application\Exceptions\OpenCaseExistsException;
use App\Modules\PensionCases\Application\Exceptions\PersonNotEligibleException;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\IncomeConceptRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Use cases of the PensionCases module for Sprint 5 (RF-EXP-001..004)
 * plus the user rules 0-5 (ADR-32/ADR-33/ADR-34): case creation with
 * the composed territorial number PPMMAACCCCC and atomic subrecords —
 * salaries capped at fifteen, services, cycles and income concept
 * records —, the subrecord highs/removals gated by the editable
 * state, and the advisory analysis the responses carry (missing
 * salary years). Since the Task 37 user correction the service
 * periods are CLOSED (mandatory end strictly after the start) and
 * DISJOINT (no overlap between subrecords): both rules answer 422
 * at every write path.
 *
 * Since the Task 40 user correction (SGP-34) the aggregate itself is
 * writable: update() edits the case fields while the PROMOVENTE
 * stays immutable (person-sphere fields answer 422 at the wire), and
 * delete() soft-deletes a SUBMITTED case — the row survives with
 * its deleted_at and the one-open-case reservation is released so
 * the operator can re-capture the applicant after a mistaken
 * registration.
 *
 * Since the Task 42 user correction (SGP-36) the case carries the
 * promovente residence + collection group (all required at the wire
 * except the CONDITIONALLY demanded bank account) and every income
 * concept declaration carries its applied percent (0-100, two
 * decimals); the group is EDITABLE through update() — the user
 * explicitly decided it can be modified.
 *
 * The OFFICE of a new case is the registering user's — the
 * Presentation layer resolves it through the Shared office port and
 * injects it into the attributes (user rule 0/ADR-33): never a
 * client-supplied value.
 *
 * Missing cases surface as null — the controller translates that to
 * HTTP 404; the service layer never speaks HTTP.
 */
interface PensionCaseServiceInterface
{
    /**
     * Creates the case (RF-EXP-001) with its composed number —
     * registering office's province and municipality codes, the last
     * two digits of the current year and the territorial consecutive,
     * PPMMAACCCCC (user rule 2/ADR-34) — and, when the
     * payload carries them, its subrecords — everything or nothing
     * (plan S5.5): the number may end up burned by a rollback, which
     * RN-009 accepts by design.
     *
     * @param  array<string, mixed>  $attributes  case fields (office_id resolved from the acting user by the controller) plus the optional salary_records / service_records / work_cycles / income_concept_records arrays
     *
     * @throws PersonNotEligibleException deceased or deactivated applicant (422)
     * @throws OpenCaseExistsException the person already holds an open case (409)
     * @throws DuplicateSalaryYearException a repeated year against stored rows (422)
     */
    public function create(array $attributes): PensionCase;

    /**
     * TERRITORIALLY scoped search (SGP-35, user correction): only
     * the cases of the acting user's office load — the office is
     * resolved by the Presentation layer through the Shared office
     * port, never the wire. An absent/null office_id answers an
     * EMPTY page (fail-closed), never the unscoped directory.
     *
     * @param  array{status?: string, office_id?: int|null, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string}  $filters
     * @return LengthAwarePaginator<int, PensionCase>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /**
     * Detail projection with subrecords and applicant loaded.
     */
    public function get(int $id): ?PensionCase;

    /**
     * Edits the case fields (SGP-34, user correction) while the
     * promovente stays immutable: the FormRequest already rejected
     * every person-sphere field (422), so the payload reaching this
     * port only carries case proper keys — PATCH semantics: the
     * declared keys change, the omitted ones keep their stored
     * value. Semantic probes mirror the store: active entity and
     * catalog references, non-future request date, RN-005 money.
     *
     * @param  array<string, mixed>  $attributes  editable case fields (employer_entity_id, position_id, occupational_category_id, educational_level_id, scientific_category_id, pension_type_id, pension_regime_id, last_salary, requested_at, current_address, residence_province_id, residence_municipality_id, collection_agency_type_id, collection_agency_id, bank_account)
     * @return null when the case does not exist (controller: 404)
     *
     * @throws CaseNotEditableException case already left submitted (409)
     */
    public function update(int $caseId, array $attributes): ?PensionCase;

    /**
     * Soft-deletes a SUBMITTED case (SGP-34, user correction): the
     * row survives with its deleted_at — the evidence and the audit
     * trail stay answerable (RN-001) — and the one-open-case
     * reservation is RELEASED (the open_case_key generated column
     * turns NULL on deleted rows) so the operator can re-capture the
     * applicant after eliminating a mistaken registration. The
     * subrecords are never touched: the history stays physically.
     *
     * @return null when the case does not exist (controller: 404)
     *
     * @throws CaseNotEditableException case already left submitted (409)
     */
    public function delete(int $caseId): ?bool;

    /**
     * Advisory analysis of the declared evidence (RF-EXP-002): the
     * missing interior salary years of the series. Since Task 37 the
     * service periods are closed and disjoint by construction — both
     * conditions are rejected (422) at every write path — so the
     * overlap/open analysis left the envelope. Pure reading of the
     * loaded relations (call get() first) — never a write, never a
     * block.
     *
     * @return array{missing_salary_years: list<int>}
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
     * Adds one work service to a submitted case (RF-EXP-003). Task 37:
     * the period is CLOSED (mandatory end_date strictly after the
     * start) and DISJOINT (no overlap with the stored services of
     * the case) — both violations answer 422.
     *
     * @param  array{entity_id: int, start_date: string, end_date: string, is_appendix: bool, declaration_form?: string}  $attributes
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

    /**
     * Declares the value of one income concept (user rule 5) with its
     * percent to apply (Task 42, user correction: REQUIRED Double
     * materialized as an exact decimal string, range 0-100 with at
     * most two decimals).
     *
     * @return null when the case does not exist (controller: 404)
     *
     * @throws CaseNotEditableException
     * @throws DuplicateIncomeConceptException the concept is already declared (422)
     */
    public function addIncomeConceptRecord(int $caseId, int $incomeConceptId, string $amount, string $appliedPercent): ?IncomeConceptRecord;

    /**
     * @return null when the case does not exist; false when the row was not found
     *
     * @throws CaseNotEditableException
     */
    public function removeIncomeConceptRecord(int $caseId, int $recordId): ?bool;
}
