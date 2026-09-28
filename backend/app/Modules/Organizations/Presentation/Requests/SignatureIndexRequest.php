<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Presentation\Requests;

use App\Modules\Organizations\Domain\SignatureStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query filters for the signature search (RF-ENT-003): exact
 * reference filters plus the derived status. The wire status maps to
 * the Domain enum, whose resolution owns the semantics.
 */
final class SignatureIndexRequest extends FormRequest
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
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'person_id' => ['nullable', 'integer', 'min:1'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:active,future,expired'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{entity_id?: int, person_id?: int, position_id?: int, status?: SignatureStatus}
     */
    public function filters(): array
    {
        /** @var array{entity_id?: int|string|null, person_id?: int|string|null, position_id?: int|string|null} $filters */
        $filters = $this->only(['entity_id', 'person_id', 'position_id']);

        /** @var array{entity_id?: int, person_id?: int, position_id?: int} $filtered */
        $filtered = array_filter($filters, fn (int|string|null $value): bool => $value !== null && $value !== '');

        // The enum is resolved from the validated wire value, not from
        // the filtered array (whose PHPDoc already carries the enum).
        $status = (string) $this->query('status', '');
        if ($status !== '') {
            $filtered['status'] = SignatureStatus::from($status);
        }

        return $filtered;
    }
}
