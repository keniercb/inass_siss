<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Application\Services;

use App\Modules\Catalogs\Application\Contracts\CatalogRepositoryInterface;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\CatalogModel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\EducationalLevel;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\IncomeConcept;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OccupationalCategory;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PensionRegime;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\PensionType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Position;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\ScientificCategory;
use App\Modules\Organizations\Application\Contracts\EntityRepositoryInterface;
use App\Modules\Organizations\Application\Contracts\OfficeRepositoryInterface;
use App\Modules\PensionCases\Application\Contracts\PensionCaseRepositoryInterface;
use App\Modules\PensionCases\Application\Contracts\PensionCaseServiceInterface;
use App\Modules\PensionCases\Application\Exceptions\CaseNotEditableException;
use App\Modules\PensionCases\Application\Exceptions\DuplicateIncomeConceptException;
use App\Modules\PensionCases\Application\Exceptions\DuplicateSalaryYearException;
use App\Modules\PensionCases\Application\Exceptions\OpenCaseExistsException;
use App\Modules\PensionCases\Application\Exceptions\PersonNotEligibleException;
use App\Modules\PensionCases\Domain\CaseNumber;
use App\Modules\PensionCases\Domain\CaseStatus;
use App\Modules\PensionCases\Domain\DeclaredService;
use App\Modules\PensionCases\Domain\SalarySeries;
use App\Modules\PensionCases\Domain\ServiceDeclarationForm;
use App\Modules\PensionCases\Domain\ServicePeriods;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\IncomeConceptRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use App\Modules\People\Application\Contracts\PeopleRepositoryInterface;
use App\Modules\People\Application\Contracts\PeopleServiceInterface;
use App\Modules\Shared\Contracts\ClockInterface;
use App\Modules\Shared\Contracts\SequenceGeneratorInterface;
use App\Modules\Shared\Contracts\TransactionManager;
use App\Modules\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for the pension case aggregate, Sprint 5 (RF-EXP-001..004,
 * plan S5.2-S5.5) + user rules 0-5 (ADR-32/ADR-33).
 *
 * Creation (S5.2): the applicant must be alive and active — decided
 * by People's own state rule (`canStartNewProcess`, RF-SEG-003) so
 * the ownership of the rule stays in People; the office — the one
 * the REGISTERING USER belongs to, resolved by the Presentation
 * layer through the Shared office port (user rule 0/ADR-33) — and
 * the employer entity must exist and stay active; every catalog
 * reference (including the pension type/regime of user rule 4) is
 * probed before writing. The filer (Task 35, user correction
 * over Task 34; English column name filed_by_person_id since Task
 * 36) is a REFERENCE to a registered person: the optional
 * filed_by_person_id is probed against the ACTIVE registry surface of
 * People (unknown or deactivated answers 422 on filed_by_person_id)
 * before any number is burned or row written. The
 * one-open-case-per-person rule answers 409 with the open case,
 * backed physically by the open_case_key generated column.
 *
 * Task 37 (user correction, SGP-31): the case carries the promovente
 * contact pair — phone and popular_council, nullable free text
 * normalized like the filer reference (absent, null or empty wire
 * value all mean NULL) — and the internationalist flag, REQUIRED at
 * the wire exactly like rebel_army_member (assertMandatoryKeys) with
 * the boolean cast resolving the persistence.
 *
 * Task 38 (user correction, SGP-32): the case carries the promovente's
 * fecha de desvinculación — termination_date, an OPTIONAL wire date
 * normalized with the SAME rule as the contact pair (absent, null or
 * empty all mean NULL). No semantic probe: the user correction
 * declares the field plain optional, so only the Y-m-d shape rule of
 * the FormRequest guards it.
 *
 * The NUMBER (user rule 2/ADR-34) is eleven contiguous digits: the
 * registering office's province (2) and municipality (2) codes, the
 * last two digits of the current year (2) and the TERRITORIAL
 * consecutive of the shared sequence (5, zero padded) — PPMMAACCCCC
 * — emitted before the business transaction, so a failed insert may
 * burn it: holes are accepted by design, reuse never (RN-009).
 * Each year, province and municipality keep their own consecutive.
 *
 * Atomicity (S5.5): the case row plus every declared subrecord —
 * salaries (at most FIFTEEN, user rule 1), services, cycles and
 * income concept records (user rule 5) — inserts inside one
 * TransactionManager boundary: everything or nothing. Subrecord
 * rules (S5.3): the (case, year) and (case, concept) pairs are
 * probed semantically (422), the year range is decided against the
 * clock (1950…current+1, RF-EXP-002), money flows through the Money
 * value object (RN-005) and — since the Task 37 user correction —
 * every service period is CLOSED (mandatory end_date strictly after
 * the start, 422) and DISJOINT from its siblings: overlapping rows
 * inside the payload answer 422 on service_records before anything
 * is written. Writes are gated by the editable state: only
 * `submitted` accepts subrecord changes (plan S5.4) —
 * CaseNotEditableException answers 409 with the current status.
 *
 * The advisory analysis (warnings) is delegated to the pure Domain
 * values: only the missing interior salary years of SalarySeries
 * remain an ADVERTISEMENT for the specialist — the overlapping and
 * open-link analysis of ServicePeriods became a REJECTION with Task
 * 37 (closed disjoint periods), so the warnings envelope carries
 * the salary analysis alone.
 *
 * Missing cases are the caller's null (controller answers 404):
 * services never raise HTTP semantics.
 */
final class PensionCaseService implements PensionCaseServiceInterface
{
    private const string CASE_SEQUENCE = 'pension_case';

    private const array PAYLOAD_COLUMNS = [
        'requested_at',
        'applicant_person_id',
        'office_id',
        'employer_entity_id',
        'position_id',
        'occupational_category_id',
        'educational_level_id',
        'scientific_category_id',
        'pension_type_id',
        'pension_regime_id',
        'rebel_army_member',
        'rebel_army_join_date',
        'internationalist',
        'filed_by_person_id',
        'phone',
        'popular_council',
        'termination_date',
        'last_salary',
    ];

    private const array UPDATE_COLUMNS = [
        'employer_entity_id',
        'position_id',
        'occupational_category_id',
        'educational_level_id',
        'scientific_category_id',
        'pension_type_id',
        'pension_regime_id',
        'last_salary',
        'requested_at',
    ];

    /**
     * @param  CatalogRepositoryInterface<CatalogModel>  $catalogs
     */
    public function __construct(
        private readonly PensionCaseRepositoryInterface $cases,
        private readonly PeopleServiceInterface $people,
        private readonly PeopleRepositoryInterface $peopleRegistry,
        private readonly OfficeRepositoryInterface $offices,
        private readonly EntityRepositoryInterface $entities,
        private readonly CatalogRepositoryInterface $catalogs,
        private readonly SequenceGeneratorInterface $sequences,
        private readonly ClockInterface $clock,
        private readonly TransactionManager $transactions,
    ) {}

    public function create(array $attributes): PensionCase
    {
        $payload = $this->acceptedPayload($attributes);

        $this->assertMandatoryKeys($payload, [
            'applicant_person_id', 'office_id', 'employer_entity_id', 'position_id',
            'occupational_category_id', 'educational_level_id', 'scientific_category_id',
            'pension_type_id', 'pension_regime_id', 'rebel_army_member', 'internationalist',
            'last_salary',
        ]);

        // Task 35: the filer reference normalized BEFORE the
        // probes — an absent, null or empty wire value all mean
        // "no filer" (NULL).
        $filedByPersonId = $this->normalizeFiledByPersonId($payload);

        $this->assertApplicantIsEligible((int) $payload['applicant_person_id']);
        $this->assertReferencesAreActive($payload);
        $this->assertFiledByPersonIsRegistered($filedByPersonId);
        $this->assertRebelArmyPairIsCoherent($payload);
        $this->assertNoOpenCase((int) $payload['applicant_person_id']);
        $this->assertRequestedAtIsNotFuture($payload);

        $lastSalary = Money::fromString((string) $payload['last_salary']);

        $salaryRows = $this->salaryRows($attributes['salary_records'] ?? []);
        $serviceRows = $this->serviceRows($attributes['service_records'] ?? []);
        $cycleRows = $this->cycleRows($attributes['work_cycles'] ?? []);
        $incomeRows = $this->incomeConceptRows($attributes['income_concept_records'] ?? []);

        // User rule 2: the province and municipality sections of the
        // number come from the REGISTERING office (which the
        // Presentation layer took from the acting user, user rule 0).
        $territory = $this->territoryCodesOfRegisteringOffice((int) $payload['office_id']);

        $rebelArmyMember = (bool) $payload['rebel_army_member'];
        $rebelArmyJoinDate = $rebelArmyMember && isset($payload['rebel_army_join_date']) && $payload['rebel_army_join_date'] !== ''
            ? (string) $payload['rebel_army_join_date']
            : null;

        // Task 37: promovente classification flag (required at the
        // wire — assertMandatoryKeys above) and contact pair
        // (normalized like the filer: absent/null/'' all mean NULL).
        $internationalist = (bool) $payload['internationalist'];
        $phone = $this->normalizePromoventeText($payload, 'phone');
        $popularCouncil = $this->normalizePromoventeText($payload, 'popular_council');

        // Task 38: fecha de desvinculación — optional wire date with
        // the same normalization as the contact pair (absent/null/''
        // all mean NULL), never a silent drop (the lesson of Task 33).
        $terminationDate = $this->normalizePromoventeText($payload, 'termination_date');

        // The number is emitted BEFORE the business transaction: a
        // failed insert burns it (hole accepted by RN-009), but two
        // concurrent creations can never share it (ADR-17/ADR-34).
        $year = (int) $this->clock->now()->format('Y');
        $consecutive = $this->sequences->nextForTerritory(
            self::CASE_SEQUENCE,
            $year,
            $territory['province'],
            $territory['municipality'],
        );
        $number = CaseNumber::fromParts($territory['province'], $territory['municipality'], $year, $consecutive)
            ->__toString();

        /** @var PensionCase $case */
        $case = $this->transactions->execute(
            function () use ($payload, $lastSalary, $number, $rebelArmyMember, $rebelArmyJoinDate, $filedByPersonId, $internationalist, $phone, $popularCouncil, $terminationDate, $salaryRows, $serviceRows, $cycleRows, $incomeRows): PensionCase {
                $case = $this->cases->create([
                    'number' => $number,
                    'requested_at' => $payload['requested_at'] ?? $this->clock->now()->format('Y-m-d'),
                    'status' => CaseStatus::Submitted->value,
                    'applicant_person_id' => (int) $payload['applicant_person_id'],
                    'office_id' => (int) $payload['office_id'],
                    'employer_entity_id' => (int) $payload['employer_entity_id'],
                    'position_id' => (int) $payload['position_id'],
                    'occupational_category_id' => (int) $payload['occupational_category_id'],
                    'educational_level_id' => (int) $payload['educational_level_id'],
                    'scientific_category_id' => (int) $payload['scientific_category_id'],
                    'pension_type_id' => (int) $payload['pension_type_id'],
                    'pension_regime_id' => (int) $payload['pension_regime_id'],
                    'last_salary' => $lastSalary->__toString(),
                    'rebel_army_member' => $rebelArmyMember,
                    'rebel_army_join_date' => $rebelArmyJoinDate,
                    // Task 37: internationalist flag plus promovente
                    // contact pair — never a silent drop (the lesson
                    // of Task 33).
                    'internationalist' => $internationalist,
                    'phone' => $phone,
                    'popular_council' => $popularCouncil,
                    // Task 38: fecha de desvinculación — never a
                    // silent drop (the lesson of Task 33).
                    'termination_date' => $terminationDate,
                    // Task 35: reference to a registered person,
                    // normalized before the probes (never a silent
                    // discard — the lesson of Task 33).
                    'filed_by_person_id' => $filedByPersonId,
                ]);

                if ($salaryRows !== []) {
                    $this->cases->createSalaryRecords($case, $salaryRows);
                }

                if ($serviceRows !== []) {
                    $this->cases->createServiceRecords($case, $serviceRows);
                }

                if ($cycleRows !== []) {
                    $this->cases->createWorkCycles($case, $cycleRows);
                }

                if ($incomeRows !== []) {
                    $this->cases->createIncomeConceptRecords($case, $incomeRows);
                }

                return $case;
            },
        );

        return $this->cases->findDetailed((int) $case->id) ?? $case;
    }

    /**
     * @param  array{status?: string, office_id?: int, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string}  $filters
     * @return LengthAwarePaginator<int, PensionCase>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;

        unset($filters['status']);

        /** @var array{office_id?: int, applicant_person_id?: int, number?: string, requested_from?: string, requested_to?: string} $criteria */
        $criteria = array_filter(
            $filters,
            static fn (string|int|null $value): bool => $value !== null && $value !== '',
        );

        if (is_string($status) && $status !== '') {
            $criteria['status'] = CaseStatus::from($status);
        }

        return $this->cases->search($criteria, $page, $perPage);
    }

    public function get(int $id): ?PensionCase
    {
        return $this->cases->findDetailed($id);
    }

    public function warnings(PensionCase $case): array
    {
        $years = [];

        foreach ($case->salaryRecords as $record) {
            $years[] = (int) $record->year;
        }

        // Task 37: the service periods are closed and disjoint by
        // construction now — every write path rejects an open or
        // overlapping period — so the overlap/open analysis left the
        // envelope: only the salary series advice remains.
        return [
            'missing_salary_years' => SalarySeries::missingConsecutiveYears($years),
        ];
    }

    /**
     * SGP-34 (user correction): case edition with an IMMUTABLE
     * promovente. The FormRequest already rejected every
     * person-sphere key (422), so only case proper fields reach this
     * port — PATCH semantics: the declared keys change, the omitted
     * ones keep their stored value. The semantic probes mirror the
     * store (active entity/catalog references, non-future request
     * date) so both write paths answer the same shape, and the money
     * flows through the Money value object exactly like the create.
     *
     * @param  array<string, mixed>  $attributes  editable case fields (employer_entity_id, position_id, occupational_category_id, educational_level_id, scientific_category_id, pension_type_id, pension_regime_id, last_salary, requested_at)
     */
    public function update(int $caseId, array $attributes): ?PensionCase
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        $payload = $this->updatePayload($attributes);

        $this->assertUpdateReferencesAreActive($payload);
        $this->assertRequestedAtIsNotFuture($payload);

        if (isset($payload['last_salary'])) {
            $payload['last_salary'] = Money::fromString((string) $payload['last_salary'])->__toString();
        }

        // An empty update is a no-op that answers the untouched case
        // — never an empty UPDATE statement nor an audit entry.
        if ($payload !== []) {
            $this->cases->update($case, $payload);
        }

        return $this->cases->findDetailed($caseId);
    }

    /**
     * SGP-34 (user correction): SOFT delete of a SUBMITTED case —
     * the row survives with its deleted_at (RN-001: the evidence and
     * the audit trail stay answerable, with the previous values per
     * ADR-19) and the subrecords are never touched: the history
     * stays physically. The one-open-case reservation is RELEASED by
     * the widened open_case_key generated column (NULL on deleted
     * rows) so the operator can re-capture the applicant after
     * eliminating a mistaken registration.
     */
    public function delete(int $caseId): ?bool
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        return $this->cases->delete($case);
    }

    public function addSalaryRecord(int $caseId, int $year, string $earnedSalary): ?SalaryRecord
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        // User rule 1: the ceiling counts LIVE rows — removing one
        // frees its slot for a new year.
        if ($this->cases->countSalaryRecords($caseId) >= SalarySeries::MAX_RECORDS) {
            throw ValidationException::withMessages([
                'salary_records' => 'A case can hold at most '.SalarySeries::MAX_RECORDS.' salary records.',
            ]);
        }

        if ($this->cases->salaryYearExists($caseId, $year)) {
            throw new DuplicateSalaryYearException($year, $caseId);
        }

        $this->assertYearIsWithinRange($year);

        $salary = Money::fromString($earnedSalary);

        return $this->transactions->execute(
            fn (): SalaryRecord => $this->cases->addSalaryRecord($case, $year, $salary->__toString()),
        );
    }

    public function removeSalaryRecord(int $caseId, int $recordId): ?bool
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        return $this->cases->removeSalaryRecord($case, $recordId);
    }

    /**
     * @param  array{entity_id: int, start_date: string, end_date: string, is_appendix: bool, declaration_form?: string}  $attributes
     */
    public function addServiceRecord(int $caseId, array $attributes): ?ServiceRecord
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        $entityId = (int) $attributes['entity_id'];

        if ($this->entities->find($entityId) === null) {
            throw ValidationException::withMessages([
                'entity_id' => "Entity {$entityId} does not exist or is deactivated.",
            ]);
        }

        $startDate = (string) $attributes['start_date'];
        $endDate = (string) $attributes['end_date'];

        if ($endDate <= $startDate) {
            // Task 37: the end is MANDATORY and STRICTLY posterior —
            // the database CHECK is the last line, not the answer.
            throw ValidationException::withMessages([
                'end_date' => 'The service end date must be after its start date.',
            ]);
        }

        $this->assertPeriodDoesNotOverlap($case, $startDate, $endDate);

        return $this->transactions->execute(
            fn (): ServiceRecord => $this->cases->addServiceRecord($case, [
                'entity_id' => $entityId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_appendix' => $attributes['is_appendix'],
                'declaration_form' => (string) ($attributes['declaration_form'] ?? ServiceDeclarationForm::Documental->value),
            ]),
        );
    }

    public function removeServiceRecord(int $caseId, int $recordId): ?bool
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        return $this->cases->removeServiceRecord($case, $recordId);
    }

    /**
     * @param  array{planned_days: int, actual_days: int, cycles_count: int}  $attributes
     */
    public function addWorkCycle(int $caseId, array $attributes): ?WorkCycle
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        return $this->transactions->execute(
            fn (): WorkCycle => $this->cases->addWorkCycle($case, [
                'planned_days' => (int) $attributes['planned_days'],
                'actual_days' => (int) $attributes['actual_days'],
                'cycles_count' => (int) $attributes['cycles_count'],
            ]),
        );
    }

    public function removeWorkCycle(int $caseId, int $recordId): ?bool
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        return $this->cases->removeWorkCycle($case, $recordId);
    }

    public function addIncomeConceptRecord(int $caseId, int $incomeConceptId, string $amount): ?IncomeConceptRecord
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        if ($this->catalogs->find(IncomeConcept::class, $incomeConceptId) === null) {
            throw ValidationException::withMessages([
                'income_concept_id' => "Income concept {$incomeConceptId} does not exist or is deactivated.",
            ]);
        }

        if ($this->cases->incomeConceptExists($caseId, $incomeConceptId)) {
            // User rule 5: one declared value per (case, concept) —
            // the semantic probe of the UNIQUE, 422 not a driver
            // error (RN-008 convention).
            throw new DuplicateIncomeConceptException($incomeConceptId, $caseId);
        }

        $money = Money::fromString($amount);

        return $this->transactions->execute(
            fn (): IncomeConceptRecord => $this->cases->addIncomeConceptRecord($case, $incomeConceptId, $money->__toString()),
        );
    }

    public function removeIncomeConceptRecord(int $caseId, int $recordId): ?bool
    {
        $case = $this->caseOrNull($caseId);

        if ($case === null) {
            return null;
        }

        $this->assertCaseIsEditable($case);

        return $this->cases->removeIncomeConceptRecord($case, $recordId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function acceptedPayload(array $attributes): array
    {
        return collect($attributes)
            ->only(self::PAYLOAD_COLUMNS)
            ->all();
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $payload
     */
    private function assertMandatoryKeys(array $payload, array $keys): void
    {
        $missing = [];

        foreach ($keys as $key) {
            if (! isset($payload[$key]) || $payload[$key] === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                $missing[0] => 'The '.$missing[0].' field is required.',
            ]);
        }
    }

    private function assertApplicantIsEligible(int $personId): void
    {
        try {
            $eligible = $this->people->canStartNewProcess($personId);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'applicant_person_id' => $exception->getMessage(),
            ]);
        }

        if ($eligible) {
            return;
        }

        // Distinguish the two ineligible flavors for an actionable
        // message: the deactivated-inclusive lookup is People's own
        // port (S3.5), the state rule stays in PeopleService.
        $person = $this->peopleRegistry->findByIdIncludingDeactivated($personId);

        if ($person === null) {
            throw ValidationException::withMessages([
                'applicant_person_id' => "Person {$personId} does not exist.",
            ]);
        }

        throw $person->death_date !== null
            ? PersonNotEligibleException::deceased($person)
            : PersonNotEligibleException::deactivated($person);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertReferencesAreActive(array $payload): void
    {
        if ($this->offices->find((int) $payload['office_id']) === null) {
            throw ValidationException::withMessages([
                'office_id' => 'The office does not exist or is deactivated.',
            ]);
        }

        if ($this->entities->find((int) $payload['employer_entity_id']) === null) {
            throw ValidationException::withMessages([
                'employer_entity_id' => 'The employer entity does not exist or is deactivated.',
            ]);
        }

        $catalogProbes = [
            'position_id' => Position::class,
            'occupational_category_id' => OccupationalCategory::class,
            'educational_level_id' => EducationalLevel::class,
            'scientific_category_id' => ScientificCategory::class,
            'pension_type_id' => PensionType::class,
            'pension_regime_id' => PensionRegime::class,
        ];

        foreach ($catalogProbes as $key => $modelClass) {
            if ($this->catalogs->find($modelClass, (int) $payload[$key]) === null) {
                throw ValidationException::withMessages([
                    $key => 'The referenced catalog entry does not exist or is deactivated.',
                ]);
            }
        }
    }

    /**
     * Task 35: normalize the optional filer reference before
     * the probes — an absent, null or empty wire value all mean
     * "no filer" (NULL); anything else becomes the int the registry
     * probe and the FK expect.
     *
     * @param  array<string, mixed>  $payload
     */
    private function normalizeFiledByPersonId(array $payload): ?int
    {
        $value = $payload['filed_by_person_id'] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Task 37: normalize one promovente contact string (phone,
     * popular_council) before the persistence — an absent, null or
     * empty wire value all mean NULL, exactly like the filer
     * reference.
     *
     * @param  array<string, mixed>  $payload
     */
    private function normalizePromoventeText(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Task 37 (user correction, SGP-31): no two service periods of a
     * case may share a single day. The probe runs BEFORE any write —
     * the candidate is compared against every stored row through the
     * pure Domain analysis (ServicePeriods::idsOverlappingWith) and a
     * crossing period answers 422 on end_date naming the stored
     * records it collides with.
     */
    private function assertPeriodDoesNotOverlap(PensionCase $case, string $startDate, string $endDate): void
    {
        $existing = [];

        foreach ($case->serviceRecords as $record) {
            $existing[] = new DeclaredService(
                (int) $record->id,
                $record->start_date->format('Y-m-d'),
                $record->end_date->format('Y-m-d'),
                (bool) $record->is_appendix,
            );
        }

        $candidate = new DeclaredService(0, $startDate, $endDate);
        $overlapping = ServicePeriods::idsOverlappingWith($candidate, $existing);

        if ($overlapping !== []) {
            throw ValidationException::withMessages([
                'end_date' => 'The service period overlaps the existing service record'.(count($overlapping) > 1 ? 's' : '').' #'.implode(', #', $overlapping).' of this case.',
            ]);
        }
    }

    /**
     * Task 35 (user correction over Task 34): the filer is a
     * REFERENCE to a registered person, not free text. The probe uses
     * the ACTIVE registry surface of People — a soft-deleted person
     * is history, not a filer — so an unknown or deactivated id
     * answers 422 on filed_by_person_id BEFORE any number is burned or
     * row written, mirroring assertReferencesAreActive. The rule
     * itself (who may file) stays intentionally open: any active
     * registered person qualifies, no eligibility state is demanded
     * from the filer.
     */
    private function assertFiledByPersonIsRegistered(?int $filedByPersonId): void
    {
        if ($filedByPersonId === null) {
            return;
        }

        if ($this->peopleRegistry->find($filedByPersonId) === null) {
            throw ValidationException::withMessages([
                'filed_by_person_id' => 'The referenced person does not exist or is deactivated.',
            ]);
        }
    }

    /**
     * User rule 4: the rebel army pair is coherent in exactly one
     * shape — a member always carries the join date, a non-member
     * never does. The FormRequest guards the wire; this is the
     * domain-side probe so no caller can drift the pair.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertRebelArmyPairIsCoherent(array $payload): void
    {
        $member = (bool) ($payload['rebel_army_member'] ?? false);
        $joinDate = $payload['rebel_army_join_date'] ?? null;
        $joinDate = $joinDate === '' ? null : $joinDate;

        if ($member && $joinDate === null) {
            throw ValidationException::withMessages([
                'rebel_army_join_date' => 'The rebel army join date is required when the applicant belongs to the rebel army.',
            ]);
        }

        if (! $member && $joinDate !== null) {
            throw ValidationException::withMessages([
                'rebel_army_join_date' => 'The rebel army join date can only be declared when the applicant belongs to the rebel army.',
            ]);
        }
    }

    /**
     * User rule 2: the province and municipality sections of the case
     * number are the registering office's territory codes — resolved
     * through the Organizations directory the service already probes.
     *
     * @return array{province: string, municipality: string}
     */
    private function territoryCodesOfRegisteringOffice(int $officeId): array
    {
        $office = $this->offices->find($officeId);

        if ($office === null) {
            throw ValidationException::withMessages([
                'office_id' => 'The office does not exist or is deactivated.',
            ]);
        }

        $provinceCode = $office->province?->code;

        if (! is_string($provinceCode) || preg_match('/^\d{2}$/', $provinceCode) !== 1) {
            throw ValidationException::withMessages([
                'office_id' => 'The registering office must sit on a province with a two-digit code to derive the case number.',
            ]);
        }

        $municipalityCode = $office->municipality?->code;

        if (! is_string($municipalityCode) || preg_match('/^\d{2}$/', $municipalityCode) !== 1) {
            throw ValidationException::withMessages([
                'office_id' => 'The registering office must sit on a municipality with a two-digit code to derive the case number.',
            ]);
        }

        return ['province' => $provinceCode, 'municipality' => $municipalityCode];
    }

    private function assertNoOpenCase(int $personId): void
    {
        $openCase = $this->cases->findOpenCaseForPerson($personId);

        if ($openCase !== null) {
            throw new OpenCaseExistsException($openCase);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRequestedAtIsNotFuture(array $payload): void
    {
        if (! isset($payload['requested_at'])) {
            return;
        }

        $today = $this->clock->now()->format('Y-m-d');

        if ((string) $payload['requested_at'] > $today) {
            throw ValidationException::withMessages([
                'requested_at' => 'The request date cannot be in the future.',
            ]);
        }
    }

    /**
     * Normalizes the declared salary rows (creation payload): money
     * through the value object, year range against the clock and the
     * FIFTEEN row ceiling of user rule 1.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{year: int, earned_salary: string}>
     */
    private function salaryRows(array $rows): array
    {
        if (count($rows) > SalarySeries::MAX_RECORDS) {
            throw ValidationException::withMessages([
                'salary_records' => 'A case can hold at most '.SalarySeries::MAX_RECORDS.' salary records.',
            ]);
        }

        $normalized = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $year = (int) ($row['year'] ?? 0);

            $this->assertYearIsWithinRange($year);

            if (isset($seen[$year])) {
                // RF-EXP-002 inside one payload: the pair (case, year)
                // is unique, so declaring the year twice is a 422
                // before anything is written.
                throw ValidationException::withMessages([
                    "salary_records.{$index}.year" => "Year {$year} is already declared in this payload.",
                ]);
            }

            $seen[$year] = true;

            $normalized[] = [
                'year' => $year,
                'earned_salary' => Money::fromString((string) ($row['earned_salary'] ?? ''))->__toString(),
            ];
        }

        return $normalized;
    }

    /**
     * Normalizes the declared service rows (creation payload).
     *
     * Task 37: every period is CLOSED and DISJOINT — the end date is
     * mandatory, strictly posterior to the start and may not share a
     * single day with a sibling row of the same payload.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{entity_id: int, start_date: string, end_date: string, is_appendix: bool, declaration_form: string}>
     */
    private function serviceRows(array $rows): array
    {
        $normalized = [];
        $periods = [];

        foreach ($rows as $index => $row) {
            $entityId = (int) ($row['entity_id'] ?? 0);

            if ($this->entities->find($entityId) === null) {
                throw ValidationException::withMessages([
                    "service_records.{$index}.entity_id" => "Entity {$entityId} does not exist or is deactivated.",
                ]);
            }

            $startDate = (string) ($row['start_date'] ?? '');
            $endDate = isset($row['end_date']) && $row['end_date'] !== ''
                ? (string) $row['end_date']
                : '';

            if ($endDate === '') {
                // Task 37: the end date is MANDATORY — the open link
                // (vínculo vigente) of Sprint 5 no longer exists.
                throw ValidationException::withMessages([
                    "service_records.{$index}.end_date" => 'The service end date is required.',
                ]);
            }

            if ($endDate <= $startDate) {
                throw ValidationException::withMessages([
                    "service_records.{$index}.end_date" => 'The service end date must be after its start date.',
                ]);
            }

            $normalized[] = [
                'entity_id' => $entityId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_appendix' => (bool) ($row['is_appendix'] ?? false),
                // Forma de declaración (Task 33): the nested payload
                // travels the same Documental|Testifical enum as the
                // individual endpoint — omitted rows keep the
                // declared default, never a silent drop.
                'declaration_form' => (string) ($row['declaration_form'] ?? ServiceDeclarationForm::Documental->value),
            ];

            // Synthetic id: the row's position in the payload, so
            // the overlap probe can name the offending rows.
            $periods[] = new DeclaredService($index, $startDate, $endDate);
        }

        $pairs = ServicePeriods::overlappingPairs($periods);

        if ($pairs !== []) {
            // Task 37: the rows of one payload are simultaneous — no
            // single row owns the fault, so the rejection lands on
            // the array field naming every overlapping pair.
            $described = array_map(
                static fn (array $pair): string => "rows {$pair[0]} and {$pair[1]}",
                $pairs,
            );

            throw ValidationException::withMessages([
                'service_records' => 'The declared service periods overlap: '.implode('; ', $described).'.',
            ]);
        }

        return $normalized;
    }

    /**
     * Normalizes the declared work cycle rows (creation payload).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{planned_days: int, actual_days: int, cycles_count: int}>
     */
    private function cycleRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $index => $row) {
            $plannedDays = (int) ($row['planned_days'] ?? -1);
            $actualDays = (int) ($row['actual_days'] ?? -1);
            $cyclesCount = (int) ($row['cycles_count'] ?? -1);

            foreach (['planned_days' => $plannedDays, 'actual_days' => $actualDays, 'cycles_count' => $cyclesCount] as $field => $value) {
                if ($value < 0) {
                    throw ValidationException::withMessages([
                        "work_cycles.{$index}.{$field}" => 'Work cycle values must be non-negative integers.',
                    ]);
                }
            }

            $normalized[] = [
                'planned_days' => $plannedDays,
                'actual_days' => $actualDays,
                'cycles_count' => $cyclesCount,
            ];
        }

        return $normalized;
    }

    /**
     * Normalizes the declared income concept rows (creation payload,
     * user rule 5): catalog probe, one value per concept inside the
     * payload and money through the value object.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{income_concept_id: int, amount: string}>
     */
    private function incomeConceptRows(array $rows): array
    {
        $normalized = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $conceptId = (int) ($row['income_concept_id'] ?? 0);

            if ($this->catalogs->find(IncomeConcept::class, $conceptId) === null) {
                throw ValidationException::withMessages([
                    "income_concept_records.{$index}.income_concept_id" => "Income concept {$conceptId} does not exist or is deactivated.",
                ]);
            }

            if (isset($seen[$conceptId])) {
                // User rule 5 inside one payload: the (case, concept)
                // pair is unique, so declaring the concept twice is a
                // 422 before anything is written.
                throw ValidationException::withMessages([
                    "income_concept_records.{$index}.income_concept_id" => "Income concept {$conceptId} is already declared in this payload.",
                ]);
            }

            $seen[$conceptId] = true;

            $normalized[] = [
                'income_concept_id' => $conceptId,
                'amount' => Money::fromString((string) ($row['amount'] ?? ''))->__toString(),
            ];
        }

        return $normalized;
    }

    private function assertYearIsWithinRange(int $year): void
    {
        $floor = SalarySeries::firstValidYear();
        $ceiling = $this->clock->now()->format('Y') + 1;

        if ($year < $floor || $year > $ceiling) {
            throw ValidationException::withMessages([
                'year' => "The year must be between {$floor} and {$ceiling}.",
            ]);
        }
    }

    /**
     * SGP-34: the editable surface of the update — only the case
     * proper columns, only the keys the wire declared. The
     * promovente and lifecycle fields never reach this method: the
     * FormRequest already rejected them as prohibited.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function updatePayload(array $attributes): array
    {
        return collect($attributes)
            ->only(self::UPDATE_COLUMNS)
            ->all();
    }

    /**
     * SGP-34: the update's semantic probes mirror the store's
     * assertReferencesAreActive but only over the PRESENT keys — a
     * PATCH never drags references it did not declare.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertUpdateReferencesAreActive(array $payload): void
    {
        if (isset($payload['employer_entity_id'])
            && $this->entities->find((int) $payload['employer_entity_id']) === null) {
            throw ValidationException::withMessages([
                'employer_entity_id' => 'The employer entity does not exist or is deactivated.',
            ]);
        }

        $catalogProbes = [
            'position_id' => Position::class,
            'occupational_category_id' => OccupationalCategory::class,
            'educational_level_id' => EducationalLevel::class,
            'scientific_category_id' => ScientificCategory::class,
            'pension_type_id' => PensionType::class,
            'pension_regime_id' => PensionRegime::class,
        ];

        foreach ($catalogProbes as $key => $modelClass) {
            if (isset($payload[$key])
                && $this->catalogs->find($modelClass, (int) $payload[$key]) === null) {
                throw ValidationException::withMessages([
                    $key => 'The referenced catalog entry does not exist or is deactivated.',
                ]);
            }
        }
    }

    private function caseOrNull(int $caseId): ?PensionCase
    {
        return $this->cases->find($caseId);
    }

    private function assertCaseIsEditable(PensionCase $case): void
    {
        if (! $case->status->isEditable()) {
            throw new CaseNotEditableException($case, $case->status);
        }
    }
}
