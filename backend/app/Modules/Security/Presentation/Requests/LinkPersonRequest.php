<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LinkPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The person must be a registered one (RF-SEG-004: any person of
     * the single registry, active or deactivated — a deactivated
     * person keeps its identity reserved). The route-level
     * permission guard (users.manage) carries the RBAC rule; the
     * uniqueness rule stays in the application layer plus the
     * database backstop.
     *
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'person_id' => ['required', 'integer', 'exists:people,id'],
        ];
    }
}
