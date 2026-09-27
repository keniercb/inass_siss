<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use App\Modules\Security\Application\DTO\AuditLogFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string contract for the audit trail listing (RF-AUD-003).
 *
 * Authorization lives on the route (permission:audit.view), so the
 * form request only shapes and validates the filters; dates arrive
 * as Y-m-d and become immutable day boundaries inside the DTO.
 */
final class AuditLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'causer_id' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'event' => ['nullable', 'string', 'in:created,updated,deleted,restored'],
            'from' => ['nullable', 'date', 'before_or_equal:to'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): AuditLogFilters
    {
        return new AuditLogFilters(
            causerId: $this->nullableInt('causer_id'),
            subjectType: $this->nullableString('subject_type'),
            subjectId: $this->nullableInt('subject_id'),
            event: $this->nullableString('event'),
            from: $this->nullableDate('from'),
            to: $this->nullableDate('to'),
            page: (int) ($this->validated('page') ?? 1),
            perPage: (int) ($this->validated('per_page') ?? 15),
        );
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->validated($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableDate(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== ''
            ? CarbonImmutable::createFromFormat('Y-m-d', $value)?->setTime(0, 0)
            : null;
    }
}
