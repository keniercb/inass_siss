<?php

declare(strict_types=1);

namespace App\Modules\People\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query filters for the person search (RF-PER-004): exact identity,
 * name fragments, sex, deceased state and birth-date range. The
 * repository owns the semantics; this class only pins the wire
 * contract.
 */
final class PersonIndexRequest extends FormRequest
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
            'identity' => ['nullable', 'string', 'digits:11'],
            'q' => ['nullable', 'string', 'max:120'],
            'sex' => ['nullable', 'string', 'max:1', 'in:M,F'],
            // Query strings are always text: accept the wire spellings
            // here and cast to a real boolean in filters().
            'deceased' => ['nullable', 'string', 'in:true,false,1,0'],
            'birth_from' => ['nullable', 'date_format:Y-m-d'],
            'birth_to' => ['nullable', 'date_format:Y-m-d', 'gte:birth_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Search filters resolved for the repository, without pagination
     * (the controller resolves page/per_page on its own).
     *
     * @return array{identity?: string, q?: string, sex?: string, deceased?: bool, birth_from?: string, birth_to?: string}
     */
    public function filters(): array
    {
        /** @var array{identity?: string|null, q?: string|null, sex?: string|null, deceased?: bool|string|null, birth_from?: string|null, birth_to?: string|null} $filters */
        $filters = $this->only(['identity', 'q', 'sex', 'deceased', 'birth_from', 'birth_to']);

        // The boolean validation rule accepts the wire spellings but
        // does not cast them; the repository needs a real boolean.
        if (array_key_exists('deceased', $filters) && $filters['deceased'] !== null) {
            $filters['deceased'] = filter_var((string) $filters['deceased'], FILTER_VALIDATE_BOOLEAN);
        }

        /** @var array{identity?: string, q?: string, sex?: string, deceased?: bool, birth_from?: string, birth_to?: string} $filtered */
        $filtered = array_filter($filters, fn (string|bool|null $value): bool => $value !== null && $value !== '');

        return $filtered;
    }
}
