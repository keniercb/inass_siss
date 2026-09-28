<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Infrastructure\Persistence;

use App\Modules\PensionCases\Application\Contracts\PensionCaseRepositoryInterface;
use App\Modules\PensionCases\Domain\CaseStatus;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\PensionCase;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\SalaryRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\ServiceRecord;
use App\Modules\PensionCases\Infrastructure\Persistence\Models\WorkCycle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent persistence for pension cases (ADR-11): the single
 * data-access point of the module. Search implements the listing
 * filters over idx_cases_office_status; the open-case probe feeds
 * the service's one-open-case rule whose physical backstop is the
 * open_case_key generated column + UNIQUE index; subrecord writes
 * are deliberately naive — the service validates everything first,
 * so the repository only inserts what the domain already accepted
 * inside the caller's transaction.
 */
final class EloquentPensionCaseRepository implements PensionCaseRepositoryInterface
{
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = PensionCase::query()
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']->value);
        }

        if (isset($filters['office_id'])) {
            $query->where('office_id', $filters['office_id']);
        }

        if (isset($filters['applicant_person_id'])) {
            $query->where('applicant_person_id', $filters['applicant_person_id']);
        }

        if (isset($filters['number']) && $filters['number'] !== '') {
            $query->where('number', $filters['number']);
        }

        if (isset($filters['requested_from']) && $filters['requested_from'] !== '') {
            $query->where('requested_at', '>=', $filters['requested_from']);
        }

        if (isset($filters['requested_to']) && $filters['requested_to'] !== '') {
            $query->where('requested_at', '<=', $filters['requested_to']);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $paginator;
    }

    public function find(int $id): ?PensionCase
    {
        return PensionCase::query()->find($id);
    }

    public function findDetailed(int $id): ?PensionCase
    {
        return PensionCase::query()
            ->with(['salaryRecords', 'serviceRecords', 'workCycles', 'applicant'])
            ->find($id);
    }

    public function findOpenCaseForPerson(int $personId): ?PensionCase
    {
        return PensionCase::query()
            ->where('applicant_person_id', $personId)
            ->whereNotIn('status', [CaseStatus::Approved->value, CaseStatus::Rejected->value])
            ->orderByDesc('id')
            ->first();
    }

    public function create(array $attributes): PensionCase
    {
        $case = new PensionCase;
        $case->fill($attributes);
        $case->save();

        return $case->refresh();
    }

    public function createSalaryRecords(PensionCase $case, array $rows): void
    {
        foreach ($rows as $row) {
            $case->salaryRecords()->create([
                'year' => $row['year'],
                'earned_salary' => $row['earned_salary'],
            ]);
        }
    }

    public function createServiceRecords(PensionCase $case, array $rows): void
    {
        foreach ($rows as $row) {
            $case->serviceRecords()->create([
                'entity_id' => $row['entity_id'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'is_appendix' => $row['is_appendix'],
            ]);
        }
    }

    public function createWorkCycles(PensionCase $case, array $rows): void
    {
        foreach ($rows as $row) {
            $case->workCycles()->create([
                'planned_days' => $row['planned_days'],
                'actual_days' => $row['actual_days'],
                'cycles_count' => $row['cycles_count'],
            ]);
        }
    }

    public function addSalaryRecord(PensionCase $case, int $year, string $earnedSalary): SalaryRecord
    {
        return $case->salaryRecords()->create([
            'year' => $year,
            'earned_salary' => $earnedSalary,
        ]);
    }

    public function removeSalaryRecord(PensionCase $case, int $recordId): bool
    {
        // Instance delete (never a mass delete): the deleted event —
        // and with it the bitácora entry with the previous values —
        // only fires through the model lifecycle (ADR-19).
        $record = $case->salaryRecords()->whereKey($recordId)->first();

        return $record !== null && (bool) $record->delete();
    }

    public function addServiceRecord(PensionCase $case, array $attributes): ServiceRecord
    {
        return $case->serviceRecords()->create($attributes);
    }

    public function removeServiceRecord(PensionCase $case, int $recordId): bool
    {
        // Instance delete for the audit trail (same as salary rows).
        $record = $case->serviceRecords()->whereKey($recordId)->first();

        return $record !== null && (bool) $record->delete();
    }

    public function addWorkCycle(PensionCase $case, array $attributes): WorkCycle
    {
        return $case->workCycles()->create($attributes);
    }

    public function removeWorkCycle(PensionCase $case, int $recordId): bool
    {
        // Instance delete for the audit trail (same as salary rows).
        $record = $case->workCycles()->whereKey($recordId)->first();

        return $record !== null && (bool) $record->delete();
    }

    public function salaryYearExists(int $caseId, int $year): bool
    {
        return SalaryRecord::query()
            ->where('pension_case_id', $caseId)
            ->where('year', $year)
            ->exists();
    }
}
