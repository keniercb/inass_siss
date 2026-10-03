<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wire contract of PUT /pension-cases/{id} (user correction, SGP-34).
 *
 * Case edition with an IMMUTABLE promovente: the user rule declares
 * that the promovente of the pension cannot be modified, so every
 * person-sphere field the case carries — the applicant reference, the
 * filer reference, the rebel army pair, the internationalist flag,
 * the contact pair (phone, popular_council) and the termination date —
 * answers 422 (prohibited) instead of silently drifting the person
 * the registry already knows. The lifecycle fields follow the same
 * fate: office_id keeps the rule 0 treatment of the store (the case
 * assumes the registering user's office), and number/status only move
 * through their own channels (the sequence at creation, the S6
 * transition machine later).
 *
 * The EDITABLE surface is the case proper — the labour link and the
 * pension classification (entity, position, both category pairs, type
 * and regime), the last salary and the request date — plus, since
 * Task 42 (user correction, SGP-36), the promovente residence and
 * collection group (current_address, residence geography, collection
 * point and bank account: the user explicitly decided they CAN be
 * modified) — with PATCH semantics: every field is optional, only the
 * declared keys change and the omission of a field never uproots its
 * stored value. An explicit null bank_account CLEARS it, and the
 * service re-evaluates the conditional demand (422 on bank_account
 * when the RESULTING payment form is 'tarjeta magnetica' and the
 * account ended up NULL).
 *
 * Structural validation only (formats, types and the RN-005 money
 * shape): the semantic probes — active entity/catalog references, the
 * non-future request date — live in the service, exactly like the
 * store's, so the same probes guard both write paths.
 */
final class UpdatePensionCaseRequest extends FormRequest
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
        $money = ['regex:/^\d{1,10}(\.\d{1,2})?$/'];

        return [
            // User rule (SGP-34): the promovente of the pension is
            // immutable — a client that still sends any of its fields
            // gets a 422 instead of silently believing its value was
            // honored (the lesson of the store's rule 0 shape).
            'applicant_person_id' => ['prohibited'],
            'filed_by_person_id' => ['prohibited'],
            'rebel_army_member' => ['prohibited'],
            'rebel_army_join_date' => ['prohibited'],
            'internationalist' => ['prohibited'],
            'phone' => ['prohibited'],
            'popular_council' => ['prohibited'],
            'termination_date' => ['prohibited'],

            // Lifecycle: never client-supplied on this path.
            'office_id' => ['prohibited'],
            'number' => ['prohibited'],
            'status' => ['prohibited'],

            // Editable case fields (PATCH semantics: optional, only
            // the declared keys change).
            'employer_entity_id' => ['sometimes', 'integer', 'min:1'],
            'position_id' => ['sometimes', 'integer', 'min:1'],
            'occupational_category_id' => ['sometimes', 'integer', 'min:1'],
            'educational_level_id' => ['sometimes', 'integer', 'min:1'],
            'scientific_category_id' => ['sometimes', 'integer', 'min:1'],
            'pension_type_id' => ['sometimes', 'integer', 'min:1'],
            'pension_regime_id' => ['sometimes', 'integer', 'min:1'],
            // RN-005: money travels as an exact decimal string.
            'last_salary' => ['sometimes', ...$money],
            'requested_at' => ['sometimes', 'date_format:Y-m-d'],
            // Task 42: the promovente residence + collection group is
            // EDITABLE (explicit user decision) — PATCH semantics, with
            // the store's mirror probes and the conditional bank
            // account demand re-evaluated against the RESULTING state.
            'current_address' => ['sometimes', 'string', 'max:255'],
            'residence_province_id' => ['sometimes', 'integer', 'min:1'],
            'residence_municipality_id' => ['sometimes', 'integer', 'min:1'],
            'collection_agency_type_id' => ['sometimes', 'integer', 'min:1'],
            'collection_agency_id' => ['sometimes', 'integer', 'min:1'],
            'bank_account' => ['sometimes', 'nullable', 'string', 'max:34'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'applicant_person_id.prohibited' => 'The promovente of the pension cannot be modified: applicant_person_id is immutable.',
            'filed_by_person_id.prohibited' => 'The filer of the case cannot be modified through this endpoint: filed_by_person_id is immutable.',
            'rebel_army_member.prohibited' => 'The promovente of the pension cannot be modified: rebel_army_member is immutable.',
            'rebel_army_join_date.prohibited' => 'The promovente of the pension cannot be modified: rebel_army_join_date is immutable.',
            'internationalist.prohibited' => 'The promovente of the pension cannot be modified: internationalist is immutable.',
            'phone.prohibited' => 'The promovente of the pension cannot be modified: phone is immutable.',
            'popular_council.prohibited' => 'The promovente of the pension cannot be modified: popular_council is immutable.',
            'termination_date.prohibited' => 'The promovente of the pension cannot be modified: termination_date is immutable.',
            'office_id.prohibited' => 'The office_id field is not accepted: the case assumes the office of the registering user.',
            'number.prohibited' => 'The case number is assigned at creation and cannot be modified.',
            'status.prohibited' => 'The case status only moves through its own transition channel.',
        ];
    }
}
