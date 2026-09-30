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
 * probed before writing. The one-open-case-per-person rule answers
 * 409 with the open case, backed physically by the open_case_key
 * generated column.
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
 * value object (RN-005) and service date order is validated against
 * the resulting pair before the CHECK gets a chance to speak.
 * Writes are gated by the editable state: only `submitted` accepts
 * subrecord changes (plan S5.4) — CaseNotEditableException answers
 * 409 with the current status.
 *
 * The advisory analysis (warnings) is delegated to the pure Domain
 * values (SalarySeries, ServicePeriods): missing interior salary
 * years, overlapping service periods and links still open are
 * ADVERTISEMENTS for the specialist, never blocks.
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
        'last_salary',
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
            'pension_type_id', 'pension_regime_id', 'rebel_army_member', 'last_salary',
        ]);

        $this->assertApplicantIsEligible((int) $payload['applicant_person_id']);
        $this->assertReferencesAreActive($payload);
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
            function () use ($payload, $lastSalary, $number, $rebelArmyMember, $rebelArmyJoinDate, $salaryRows, $serviceRows, $cycleRows, $incomeRows): PensionCase {
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
        $services = [];

        foreach ($case->salaryRecords as $record) {
            $years[] = (int) $record->year;
        }

        foreach ($case->serviceRecords as $record) {
            $services[] = new DeclaredService(
                (int) $record->id,
                $record->start_date->format('Y-m-d'),
                $record->end_date?->format('Y-m-d'),
                (bool) $record->is_appendix,
            );
        }

        return [
            'missing_salary_years' => SalarySeries::missingConsecutiveYears($years),
            'overlapping_services' => ServicePeriods::overlappingPairs($services),
            'open_services' => ServicePeriods::openServiceIds($services),
        ];
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
     * @param  array{entity_id: int, start_date: string, end_date: string|null, is_appendix: bool, forma_declaracion?: string}  $attributes
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
        $endDate = $attributes['end_date'] !== null ? (string) $attributes['end_date'] : null;

        if ($endDate !== null && $endDate < $startDate) {
            // RN-006 semantic probe: the database CHECK is the last
            // line, not the answer.
            throw ValidationException::withMessages([
                'end_date' => 'The service end date cannot precede its start date.',
            ]);
        }

        return $this->transactions->execute(
            fn (): ServiceRecord => $this->cases->addServiceRecord($case, [
                'entity_id' => $entityId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_appendix' => $attributes['is_appendix'],
                'forma_declaracion' => (string) ($attributes['forma_declaracion'] ?? ServiceDeclarationForm::Documental->value),
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
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{entity_id: int, start_date: string, end_date: string|null, is_appendix: bool}>
     */
    private function serviceRows(array $rows): array
    {
        $normalized = [];

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
                : null;

            if ($endDate !== null && $endDate < $startDate) {
                throw ValidationException::withMessages([
                    "service_records.{$index}.end_date" => 'The service end date cannot precede its start date.',
                ]);
            }

            $normalized[] = [
                'entity_id' => $entityId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_appendix' => (bool) ($row['is_appendix'] ?? false),
            ];
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
