<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of POST /pension-cases/{id}/service-records
 * (RF-EXP-003). Task 37 (user correction, SGP-31): the end date is
 * MANDATORY — the open link (vínculo vigente) no longer exists —
 * and the strictly-posterior (end > start) and no-overlap rules are
 * semantic probes in the service (the database CHECK is the last
 * line), so this class pins presence, formats and types only.
 */
final class StoreServiceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'entity_id' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            // Task 37: mandatory end — the service probes that it is
            // strictly after the start and disjoint from every
            // stored period of the case.
            'end_date' => ['required', 'date_format:Y-m-d'],
            // Coletilla: recognized additional service.
            'is_appendix' => ['nullable', 'boolean'],
            // Declaration form: Documental by default or Testifical
            // (RF-EXP-003, Task 32; English column name since Task 36).
            // The default is resolved by the presentation layer.
            'declaration_form' => ['nullable', 'string', 'in:Documental,Testifical'],
        ];
    }
}
