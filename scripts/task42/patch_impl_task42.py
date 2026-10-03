#!/usr/bin/env python3
"""Task 42 / SGP-36 implementation patch (user correction, validated
by the user's "implemnta"): the four model adjustments.

Anchored, asserted, idempotent edits over the production code:
(a) payment_types eliminated (registry entry, model file, seeder,
    OA type enumeration),
(b) agency_types.payment_form (enum lowercase unified, default
    'tarjeta magnetica', extraRules machinery),
(c) pension_cases promovente residence + collection group,
(d) income_concept_records.applied_percent.
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/backend")

EDITS: list[tuple[Path, list[tuple[str, str]]]] = []


def add(path: str, edits: list[tuple[str, str]]) -> None:
    EDITS.append((ROOT / path, edits))


# ---------------------------------------------------------------------------
# CatalogRegistry — payment-types out, agency-types payment_form in.
# ---------------------------------------------------------------------------
add(
    "app/Modules/Catalogs/Application/CatalogRegistry.php",
    [
        (
            " * Since the Task 38 correction two catalogs carry type-specific extra\n"
            " * columns: the pension regimes add the OPTIONAL sector (integer) and\n"
            " * the pension types add the persona fallecida flag (deceased_person,\n"
            " * boolean with database DEFAULT false) — both ride the generic\n"
            " * extraRules machinery, so every endpoint of the generic resource\n"
            " * answers with them.\n"
            " *",
            " * Since the Task 38 correction two catalogs carry type-specific extra\n"
            " * columns: the pension regimes add the OPTIONAL sector (integer) and\n"
            " * the pension types add the persona fallecida flag (deceased_person,\n"
            " * boolean with database DEFAULT false) — both ride the generic\n"
            " * extraRules machinery, so every endpoint of the generic resource\n"
            " * answers with them.\n"
            " *\n"
            " * Since the Task 42 correction (SGP-36) the payment_types catalog is\n"
            " * ELIMINATED — fifteen uniform types remain, because the payment form\n"
            " * of the collection now lives in the agency type — and the agency\n"
            " * types carry their own extra column: payment_form, the\n"
            " * lowercase-unified enum 'tarjeta magnetica'|'nomina electronica'\n"
            " * (written exactly as the user fixed it, no tildes) with database\n"
            " * DEFAULT 'tarjeta magnetica' — the deceased_person precedent: an\n"
            " * omitted store payload answers the default, and the value decides\n"
            " * the conditional bank-account demand of the case's collection\n"
            " * group (RF-EXP-001).\n"
            " *",
        ),
        (
            "            'agency-types' => new CatalogDefinition(\n"
            "                key: 'agency-types',\n"
            "                label: 'agency types',\n"
            "                model: self::MODELS.'AgencyType',\n"
            "                hasCode: true,\n"
            "                codeMax: 4,\n"
            "                nameMax: 80,\n"
            "                hasDescription: false,\n"
            "                extraRules: [],\n",
            "            'agency-types' => new CatalogDefinition(\n"
            "                key: 'agency-types',\n"
            "                label: 'agency types',\n"
            "                model: self::MODELS.'AgencyType',\n"
            "                hasCode: true,\n"
            "                codeMax: 4,\n"
            "                nameMax: 80,\n"
            "                hasDescription: false,\n"
            "                // Task 42 (user correction, SGP-36): payment form of\n"
            "                // the collection — lowercase-unified enum, omission\n"
            "                // falls to the database DEFAULT.\n"
            "                extraRules: ['payment_form' => 'in:tarjeta magnetica,nomina electronica'],\n",
        ),
        (
            "            'payment-types' => new CatalogDefinition(\n"
            "                key: 'payment-types',\n"
            "                label: 'payment types',\n"
            "                model: self::MODELS.'PaymentType',\n"
            "                hasCode: true,\n"
            "                codeMax: 10,\n"
            "                nameMax: 80,\n"
            "                hasDescription: true,\n"
            "                extraRules: [],\n"
            "                dependents: [],\n"
            "            ),\n",
            "",
        ),
    ],
)

# ---------------------------------------------------------------------------
# AgencyType model — fillable, in-memory default, docblock.
# ---------------------------------------------------------------------------
add(
    "app/Modules/Catalogs/Infrastructure/Persistence/Models/AgencyType.php",
    [
        (
            " * Conventions (ADR-11): the model lives in the module's Infrastructure\n"
            " * layer and business rules live in the module's Application services.\n"
            " * The code is immutable after creation and rows are logically\n"
            " * deactivated through soft deletes (RF-CAT-001). Authorship is stamped\n"
            " * by the Shared AuditableObserver, so this class deliberately imports\n"
            " * no Security types (deptrac: Catalogs depends on Shared only).\n"
            " *",
            " * Conventions (ADR-11): the model lives in the module's Infrastructure\n"
            " * layer and business rules live in the module's Application services.\n"
            " * The code is immutable after creation and rows are logically\n"
            " * deactivated through soft deletes (RF-CAT-001). Authorship is stamped\n"
            " * by the Shared AuditableObserver, so this class deliberately imports\n"
            " * no Security types (deptrac: Catalogs depends on Shared only).\n"
            " *\n"
            " * Task 42 (user correction, SGP-36): the type carries the payment form\n"
            " * of the collection — payment_form, the lowercase-unified enum\n"
            " * 'tarjeta magnetica'|'nomina electronica' (written exactly as the\n"
            " * user fixed it, no tildes) with database DEFAULT 'tarjeta magnetica'.\n"
            " * The in-memory default mirrors the column default so an omitted\n"
            " * store payload answers 'tarjeta magnetica' in the 201 projection\n"
            " * without a reload (the PensionType/deceased_person precedent of\n"
            " * Task 38), and the value decides the conditional bank-account demand\n"
            " * of the case's collection group.\n"
            " *",
        ),
        (
            " * @property int $id\n"
            " * @property string $code\n"
            " * @property string $name\n"
            " * @property int|null $created_by\n",
            " * @property int $id\n"
            " * @property string $code\n"
            " * @property string $name\n"
            " * @property string $payment_form\n"
            " * @property int|null $created_by\n",
        ),
        (
            "class AgencyType extends CatalogModel\n"
            "{\n"
            "    /** @var list<string> */\n"
            "    protected $fillable = [\n"
            "        'code',\n"
            "        'name',\n"
            "        'created_by',\n"
            "        'updated_by',\n"
            "    ];\n"
            "}",
            "class AgencyType extends CatalogModel\n"
            "{\n"
            "    /**\n"
            "     * Task 42: the in-memory default mirrors the database DEFAULT, so\n"
            "     * a store payload that omits the payment form answers\n"
            "     * 'tarjeta magnetica' in the 201 projection without a reload — the\n"
            "     * same observable contract the column default gives the stored\n"
            "     * row (the PensionType/deceased_person precedent of Task 38).\n"
            "     *\n"
            "     * @var array<string, string>\n"
            "     */\n"
            "    protected $attributes = [\n"
            "        'payment_form' => 'tarjeta magnetica',\n"
            "    ];\n"
            "\n"
            "    /** @var list<string> */\n"
            "    protected $fillable = [\n"
            "        'code',\n"
            "        'name',\n"
            "        // Task 42 (user correction, SGP-36): payment form of the\n"
            "        // collection.\n"
            "        'payment_form',\n"
            "        'created_by',\n"
            "        'updated_by',\n"
            "    ];\n"
            "}",
        ),
    ],
)

# ---------------------------------------------------------------------------
# CatalogsSeeder — payment types out, agency types carry payment_form.
# ---------------------------------------------------------------------------
add(
    "database/seeders/CatalogsSeeder.php",
    [
        (
            "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\PaymentType;\n",
            "",
        ),
        (
            "        AgencyType::upsert(\n"
            "            self::codeRows([\n"
            "                ['AG', 'Agencia'], ['SU', 'Sucursal'], ['PS', 'Punto de servicio'],\n"
            "            ]),\n"
            "            ['code'],\n"
            "            ['name'],\n"
            "        );",
            "        // Task 42 (user correction, SGP-36): the agency types seed\n"
            "        // their payment form explicitly (the column default would\n"
            "        // answer the same value — this keeps the reference data\n"
            "        // honest about the field's existence; official assignment\n"
            "        // pending the analista, P-06).\n"
            "        AgencyType::upsert(\n"
            "            [\n"
            "                ['code' => 'AG', 'name' => 'Agencia', 'payment_form' => 'tarjeta magnetica'],\n"
            "                ['code' => 'SU', 'name' => 'Sucursal', 'payment_form' => 'tarjeta magnetica'],\n"
            "                ['code' => 'PS', 'name' => 'Punto de servicio', 'payment_form' => 'tarjeta magnetica'],\n"
            "            ],\n"
            "            ['code'],\n"
            "            ['name', 'payment_form'],\n"
            "        );",
        ),
        (
            "        PaymentType::upsert(\n"
            "            self::codedRows([\n"
            "                ['ABN', 'Abono bancario', 'Abono en cuenta bancaria'],\n"
            "                ['CHQ', 'Cheque', 'Pago por cheque'],\n"
            "                ['EFE', 'Efectivo', 'Pago en efectivo'],\n"
            "            ]),\n"
            "            ['code'],\n"
            "            ['name', 'description'],\n"
            "        );\n"
            "\n",
            "",
        ),
    ],
)

# ---------------------------------------------------------------------------
# CatalogController — OA: type enumeration, payment_form properties.
# ---------------------------------------------------------------------------
add(
    "app/Modules/Catalogs/Presentation/Controllers/CatalogController.php",
    [
        (
            "Tipos válidos: provinces, agency-types, organizations, entity-types, "
            "office-types, legal-basis-types, scientific-categories, "
            "educational-levels, occupational-categories, pension-types, "
            "beneficiary-types, races, positions, pension-regimes, payment-types, "
            "income-concepts.",
            "Tipos válidos: provinces, agency-types, organizations, entity-types, "
            "office-types, legal-basis-types, scientific-categories, "
            "educational-levels, occupational-categories, pension-types, "
            "beneficiary-types, races, positions, pension-regimes, "
            "income-concepts.",
        ),
        (
            "                    new OA\\Property(property: 'deceased_person', type: 'boolean', default: false, example: false, description: 'Solo pension-types (Task 38): persona fallecida; la omisión persiste el default false'),\n"
            "                ],",
            "                    new OA\\Property(property: 'deceased_person', type: 'boolean', default: false, example: false, description: 'Solo pension-types (Task 38): persona fallecida; la omisión persiste el default false'),\n"
            "                    new OA\\Property(property: 'payment_form', type: 'string', enum: ['tarjeta magnetica', 'nomina electronica'], default: 'tarjeta magnetica', example: 'tarjeta magnetica', description: 'Solo agency-types (Task 42): forma de pago del cobro — la omisión persiste el default tarjeta magnetica; decide la exigencia de la cuenta bancaria del cobro del expediente'),\n"
            "                ],",
        ),
        (
            "                    new OA\\Property(property: 'deceased_person', type: 'boolean', description: 'Solo pension-types (Task 38): persona fallecida'),\n"
            "                ],",
            "                    new OA\\Property(property: 'deceased_person', type: 'boolean', description: 'Solo pension-types (Task 38): persona fallecida'),\n"
            "                    new OA\\Property(property: 'payment_form', type: 'string', enum: ['tarjeta magnetica', 'nomina electronica'], description: 'Solo agency-types (Task 42): forma de pago del cobro'),\n"
            "                ],",
        ),
    ],
)

# ---------------------------------------------------------------------------
# CatalogResource — OA mirror of payment_form.
# ---------------------------------------------------------------------------
add(
    "app/Modules/Catalogs/Presentation/Resources/CatalogResource.php",
    [
        (
            "        new OA\\Property(property: 'deceased_person', type: 'boolean', nullable: false, example: false, description: 'Solo pension-types (Task 38): persona fallecida, booleano con default false, devuelto por todos los endpoints'),\n",
            "        new OA\\Property(property: 'deceased_person', type: 'boolean', nullable: false, example: false, description: 'Solo pension-types (Task 38): persona fallecida, booleano con default false, devuelto por todos los endpoints'),\n"
            "        new OA\\Property(property: 'payment_form', type: 'string', enum: ['tarjeta magnetica', 'nomina electronica'], example: 'tarjeta magnetica', description: 'Solo agency-types (Task 42): forma de pago del cobro, devuelta por todos los endpoints'),\n",
        ),
    ],
)

# ---------------------------------------------------------------------------
# StorePensionCaseRequest — the residence + collection group + applied
# percent of the nested income rows.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Presentation/Requests/StorePensionCaseRequest.php",
    [
        (
            " * Task 38 (user correction, SGP-32): the fecha de desvinculación —\n"
            " * termination_date, an OPTIONAL date with the Y-m-d shape rule only\n"
            " * (no semantic probe: the user correction declares it plain\n"
            " * optional), exactly like requested_at's shape treatment.\n"
            " */",
            " * Task 38 (user correction, SGP-32): the fecha de desvinculación —\n"
            " * termination_date, an OPTIONAL date with the Y-m-d shape rule only\n"
            " * (no semantic probe: the user correction declares it plain\n"
            " * optional), exactly like requested_at's shape treatment.\n"
            " *\n"
            " * Task 42 (user correction, SGP-36): the promovente residence and\n"
            " * collection group — current_address, residence geography and\n"
            " * collection point, every field REQUIRED at the wire EXCEPT the bank\n"
            " * account, whose conditional demand depends on the payment form of\n"
            " * the collection agency type (a semantic probe of the service, 422\n"
            " * on bank_account). The nested income rows additionally carry the\n"
            " * REQUIRED applied_percent (range 0-100, at most two decimals — the\n"
            " * RN-005 doctrine: exact decimal string, never float).\n"
            " */",
        ),
        (
            "            // Task 38: fecha de desvinculación — optional wire date,\n"
            "            // shape rule only (no semantic probe).\n"
            "            'termination_date' => ['nullable', 'date_format:Y-m-d'],",
            "            // Task 38: fecha de desvinculación — optional wire date,\n"
            "            // shape rule only (no semantic probe).\n"
            "            'termination_date' => ['nullable', 'date_format:Y-m-d'],\n"
            "            // Task 42: promovente residence + collection group — all\n"
            "            // required EXCEPT the bank account: the service demands\n"
            "            // it (422 on bank_account) when the payment form of the\n"
            "            // collection agency type is 'tarjeta magnetica'.\n"
            "            'current_address' => ['required', 'string', 'max:255'],\n"
            "            'residence_province_id' => ['required', 'integer', 'min:1'],\n"
            "            'residence_municipality_id' => ['required', 'integer', 'min:1'],\n"
            "            'collection_agency_type_id' => ['required', 'integer', 'min:1'],\n"
            "            'collection_agency_id' => ['required', 'integer', 'min:1'],\n"
            "            'bank_account' => ['nullable', 'string', 'max:34'],",
        ),
        (
            "            'income_concept_records.*.amount' => ['required_with:income_concept_records', ...$money],",
            "            'income_concept_records.*.amount' => ['required_with:income_concept_records', ...$money],\n"
            "            // Task 42: percent to apply — exact decimal string with\n"
            "            // range 0-100 and at most two decimals (RN-005 doctrine).\n"
            "            'income_concept_records.*.applied_percent' => [\n"
            "                'required_with:income_concept_records',\n"
            "                'numeric',\n"
            "                'between:0,100',\n"
            "                'regex:/^\\d{1,3}(\\.\\d{1,2})?$/',\n"
            "            ],",
        ),
    ],
)

# ---------------------------------------------------------------------------
# UpdatePensionCaseRequest — the group is EDITABLE (explicit user
# decision: it can be modified).
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Presentation/Requests/UpdatePensionCaseRequest.php",
    [
        (
            " * The EDITABLE surface is the case proper — the labour link and the\n"
            " * pension classification (entity, position, both category pairs, type\n"
            " * and regime), the last salary and the request date — with PATCH\n"
            " * semantics: every field is optional, only the declared keys change\n"
            " * and the omission of a field never uproots its stored value.",
            " * The EDITABLE surface is the case proper — the labour link and the\n"
            " * pension classification (entity, position, both category pairs, type\n"
            " * and regime), the last salary and the request date — plus, since\n"
            " * Task 42 (user correction, SGP-36), the promovente residence and\n"
            " * collection group (current_address, residence geography, collection\n"
            " * point and bank account: the user explicitly decided they CAN be\n"
            " * modified) — with PATCH semantics: every field is optional, only the\n"
            " * declared keys change and the omission of a field never uproots its\n"
            " * stored value. An explicit null bank_account CLEARS it, and the\n"
            " * service re-evaluates the conditional demand (422 on bank_account\n"
            " * when the RESULTING payment form is 'tarjeta magnetica' and the\n"
            " * account ended up NULL).",
        ),
        (
            "            // RN-005: money travels as an exact decimal string.\n"
            "            'last_salary' => ['sometimes', ...$money],\n"
            "            'requested_at' => ['sometimes', 'date_format:Y-m-d'],\n"
            "        ];",
            "            // RN-005: money travels as an exact decimal string.\n"
            "            'last_salary' => ['sometimes', ...$money],\n"
            "            'requested_at' => ['sometimes', 'date_format:Y-m-d'],\n"
            "            // Task 42: the promovente residence + collection group is\n"
            "            // EDITABLE (explicit user decision) — PATCH semantics, with\n"
            "            // the store's mirror probes and the conditional bank\n"
            "            // account demand re-evaluated against the RESULTING state.\n"
            "            'current_address' => ['sometimes', 'string', 'max:255'],\n"
            "            'residence_province_id' => ['sometimes', 'integer', 'min:1'],\n"
            "            'residence_municipality_id' => ['sometimes', 'integer', 'min:1'],\n"
            "            'collection_agency_type_id' => ['sometimes', 'integer', 'min:1'],\n"
            "            'collection_agency_id' => ['sometimes', 'integer', 'min:1'],\n"
            "            'bank_account' => ['sometimes', 'nullable', 'string', 'max:34'],\n"
            "        ];",
        ),
    ],
)

# ---------------------------------------------------------------------------
# StoreIncomeConceptRecordRequest — applied_percent.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Presentation/Requests/StoreIncomeConceptRecordRequest.php",
    [
        (
            " * Structural validation only: the concept reference shape and the\n"
            " * money shape (RN-05: exact decimal string, never float). The\n"
            " * semantic rules — concept exists and stays active, the (case,\n"
            " * concept) pair still undeclared, editable state — live in the\n"
            " * service behind the port.",
            " * Structural validation only: the concept reference shape, the\n"
            " * money shape (RN-05: exact decimal string, never float) and —\n"
            " * since Task 42 (user correction, SGP-36) — the applied percent\n"
            " * shape: REQUIRED Double materialized as an exact decimal string\n"
            " * with range 0-100 and at most two decimals. The semantic rules —\n"
            " * concept exists and stays active, the (case, concept) pair still\n"
            " * undeclared, editable state — live in the service behind the port.",
        ),
        (
            "        return [\n"
            "            'income_concept_id' => ['required', 'integer', 'min:1'],\n"
            "            // RN-005: money travels as an exact decimal string.\n"
            "            'amount' => ['required', 'regex:/^\\d{1,10}(\\.\\d{1,2})?$/'],\n"
            "        ];",
            "        return [\n"
            "            'income_concept_id' => ['required', 'integer', 'min:1'],\n"
            "            // RN-005: money travels as an exact decimal string.\n"
            "            'amount' => ['required', 'regex:/^\\d{1,10}(\\.\\d{1,2})?$/'],\n"
            "            // Task 42: percent to apply — same doctrine as the money\n"
            "            // shape: exact decimal string, range 0-100, at most two\n"
            "            // decimals (never a binary float).\n"
            "            'applied_percent' => [\n"
            "                'required',\n"
            "                'numeric',\n"
            "                'between:0,100',\n"
            "                'regex:/^\\d{1,3}(\\.\\d{1,2})?$/',\n"
            "            ],\n"
            "        ];",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCaseServiceInterface — signature + docblocks.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Application/Contracts/PensionCaseServiceInterface.php",
    [
        (
            " * Since the Task 40 user correction (SGP-34) the aggregate itself is\n"
            " * writable: update() edits the case fields while the PROMOVENTE\n"
            " * stays immutable (person-sphere fields answer 422 at the wire), and\n"
            " * delete() soft-deletes a SUBMITTED case — the row survives with\n"
            " * its deleted_at and the one-open-case reservation is released so\n"
            " * the operator can re-capture the applicant after a mistaken\n"
            " * registration.",
            " * Since the Task 40 user correction (SGP-34) the aggregate itself is\n"
            " * writable: update() edits the case fields while the PROMOVENTE\n"
            " * stays immutable (person-sphere fields answer 422 at the wire), and\n"
            " * delete() soft-deletes a SUBMITTED case — the row survives with\n"
            " * its deleted_at and the one-open-case reservation is released so\n"
            " * the operator can re-capture the applicant after a mistaken\n"
            " * registration.\n"
            " *\n"
            " * Since the Task 42 user correction (SGP-36) the case carries the\n"
            " * promovente residence + collection group (all required at the wire\n"
            " * except the CONDITIONALLY demanded bank account) and every income\n"
            " * concept declaration carries its applied percent (0-100, two\n"
            " * decimals); the group is EDITABLE through update() — the user\n"
            " * explicitly decided it can be modified.",
        ),
        (
            "     * @param  array<string, mixed>  $attributes  editable case fields (employer_entity_id, position_id, occupational_category_id, educational_level_id, scientific_category_id, pension_type_id, pension_regime_id, last_salary, requested_at)",
            "     * @param  array<string, mixed>  $attributes  editable case fields (employer_entity_id, position_id, occupational_category_id, educational_level_id, scientific_category_id, pension_type_id, pension_regime_id, last_salary, requested_at, current_address, residence_province_id, residence_municipality_id, collection_agency_type_id, collection_agency_id, bank_account)",
        ),
        (
            "    /**\n"
            "     * Declares the value of one income concept (user rule 5).\n"
            "     *\n"
            "     * @return null when the case does not exist (controller: 404)\n"
            "     *\n"
            "     * @throws CaseNotEditableException\n"
            "     * @throws DuplicateIncomeConceptException the concept is already declared (422)\n"
            "     */\n"
            "    public function addIncomeConceptRecord(int $caseId, int $incomeConceptId, string $amount): ?IncomeConceptRecord;",
            "    /**\n"
            "     * Declares the value of one income concept (user rule 5) with its\n"
            "     * percent to apply (Task 42, user correction: REQUIRED Double\n"
            "     * materialized as an exact decimal string, range 0-100 with at\n"
            "     * most two decimals).\n"
            "     *\n"
            "     * @return null when the case does not exist (controller: 404)\n"
            "     *\n"
            "     * @throws CaseNotEditableException\n"
            "     * @throws DuplicateIncomeConceptException the concept is already declared (422)\n"
            "     */\n"
            "    public function addIncomeConceptRecord(int $caseId, int $incomeConceptId, string $amount, string $appliedPercent): ?IncomeConceptRecord;",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCaseRepositoryInterface — rows shape + signature.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Application/Contracts/PensionCaseRepositoryInterface.php",
    [
        (
            "     * @param  list<array{income_concept_id: int, amount: string}>  $rows\n"
            "     */\n"
            "    public function createIncomeConceptRecords(PensionCase $case, array $rows): void;",
            "     * @param  list<array{income_concept_id: int, amount: string, applied_percent: string}>  $rows\n"
            "     */\n"
            "    public function createIncomeConceptRecords(PensionCase $case, array $rows): void;",
        ),
        (
            "    public function addIncomeConceptRecord(PensionCase $case, int $incomeConceptId, string $amount): IncomeConceptRecord;",
            "    public function addIncomeConceptRecord(PensionCase $case, int $incomeConceptId, string $amount, string $appliedPercent): IncomeConceptRecord;",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCaseService — the group probes, columns and applied percent.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Application/Services/PensionCaseService.php",
    [
        # Imports: the geography/agency models + the PaymentForm enum.
        (
            "use App\\Modules\\Catalogs\\Application\\Contracts\\CatalogRepositoryInterface;\n"
            "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\CatalogModel;\n",
            "use App\\Modules\\Catalogs\\Application\\Contracts\\CatalogRepositoryInterface;\n"
            "use App\\Modules\\Catalogs\\Domain\\PaymentForm;\n"
            "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Agency;\n"
            "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\AgencyType;\n"
            "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\CatalogModel;\n"
            "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Municipality;\n"
            "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Province;\n",
        ),
        # Class docblock — the Task 42 paragraph.
        (
            " * Task 38 (user correction, SGP-32): the case carries the promovente's\n"
            " * fecha de desvinculación — termination_date, an OPTIONAL wire date\n"
            " * normalized with the SAME rule as the contact pair (absent, null or\n"
            " * empty all mean NULL). No semantic probe: the user correction\n"
            " * declares the field plain optional, so only the Y-m-d shape rule of\n"
            " * the FormRequest guards it.\n"
            " *",
            " * Task 38 (user correction, SGP-32): the case carries the promovente's\n"
            " * fecha de desvinculación — termination_date, an OPTIONAL wire date\n"
            " * normalized with the SAME rule as the contact pair (absent, null or\n"
            " * empty all mean NULL). No semantic probe: the user correction\n"
            " * declares the field plain optional, so only the Y-m-d shape rule of\n"
            " * the FormRequest guards it.\n"
            " *\n"
            " * Task 42 (user correction, SGP-36): the case carries the promovente\n"
            " * residence + collection group — current_address, the residence\n"
            " * geography (province + municipality, RN-04 coherent: the municipality\n"
            " * belongs to the declared province; the special municipality stays out\n"
            " * of the residence domain, P-09), the collection point (agency type +\n"
            " * agency, the agency ACTIVE and of the declared type) and the bank\n"
            " * account, REQUIRED CONDITIONALLY: demanded (422 on bank_account) when\n"
            " * the payment form of the collection agency type is 'tarjeta\n"
            " * magnetica', optional with 'nomina electronica'. Every field is\n"
            " * required at the wire EXCEPT the account, and the whole group is\n"
            " * EDITABLE through update() — the user explicitly decided it can be\n"
            " * modified — with the conditional demand re-evaluated against the\n"
            " * RESULTING state of the PATCH semantics. The income concept records\n"
            " * additionally carry their applied percent (0-100, two decimals,\n"
            " * RN-005 doctrine: exact decimal string, never a binary float).\n"
            " *",
        ),
        # PAYLOAD_COLUMNS.
        (
            "        'termination_date',\n"
            "        'last_salary',\n"
            "    ];",
            "        'termination_date',\n"
            "        'current_address',\n"
            "        'residence_province_id',\n"
            "        'residence_municipality_id',\n"
            "        'collection_agency_type_id',\n"
            "        'collection_agency_id',\n"
            "        'bank_account',\n"
            "        'last_salary',\n"
            "    ];",
        ),
        # UPDATE_COLUMNS.
        (
            "        'last_salary',\n"
            "        'requested_at',\n"
            "    ];",
            "        'last_salary',\n"
            "        'requested_at',\n"
            "        'current_address',\n"
            "        'residence_province_id',\n"
            "        'residence_municipality_id',\n"
            "        'collection_agency_type_id',\n"
            "        'collection_agency_id',\n"
            "        'bank_account',\n"
            "    ];",
        ),
        # create(): mandatory keys gain the five required group fields.
        (
            "        $this->assertMandatoryKeys($payload, [\n"
            "            'applicant_person_id', 'office_id', 'employer_entity_id', 'position_id',\n"
            "            'occupational_category_id', 'educational_level_id', 'scientific_category_id',\n"
            "            'pension_type_id', 'pension_regime_id', 'rebel_army_member', 'internationalist',\n"
            "            'last_salary',\n"
            "        ]);",
            "        $this->assertMandatoryKeys($payload, [\n"
            "            'applicant_person_id', 'office_id', 'employer_entity_id', 'position_id',\n"
            "            'occupational_category_id', 'educational_level_id', 'scientific_category_id',\n"
            "            'pension_type_id', 'pension_regime_id', 'rebel_army_member', 'internationalist',\n"
            "            'current_address', 'residence_province_id', 'residence_municipality_id',\n"
            "            'collection_agency_type_id', 'collection_agency_id',\n"
            "            'last_salary',\n"
            "        ]);",
        ),
        # create(): the residence + collection probe.
        (
            "        $this->assertApplicantIsEligible((int) $payload['applicant_person_id']);\n"
            "        $this->assertReferencesAreActive($payload);\n"
            "        $this->assertFiledByPersonIsRegistered($filedByPersonId);",
            "        $this->assertApplicantIsEligible((int) $payload['applicant_person_id']);\n"
            "        $this->assertReferencesAreActive($payload);\n"
            "        // Task 42: residence + collection coherence (including the\n"
            "        // conditional bank-account demand).\n"
            "        $this->assertResidenceAndCollectionAreCoherent($payload);\n"
            "        $this->assertFiledByPersonIsRegistered($filedByPersonId);",
        ),
        # create(): the transaction attributes gain the group.
        (
            "                    // Task 35: reference to a registered person,\n"
            "                    // normalized before the probes (never a silent\n"
            "                    // discard — the lesson of Task 33).\n"
            "                    'filed_by_person_id' => $filedByPersonId,\n"
            "                ]);",
            "                    // Task 35: reference to a registered person,\n"
            "                    // normalized before the probes (never a silent\n"
            "                    // discard — the lesson of Task 33).\n"
            "                    'filed_by_person_id' => $filedByPersonId,\n"
            "                    // Task 42: residence + collection group — the\n"
            "                    // bank account normalized like the contact pair\n"
            "                    // (absent/null/'' all mean NULL); the conditional\n"
            "                    // demand was already decided by the probe above.\n"
            "                    'current_address' => (string) $payload['current_address'],\n"
            "                    'residence_province_id' => (int) $payload['residence_province_id'],\n"
            "                    'residence_municipality_id' => (int) $payload['residence_municipality_id'],\n"
            "                    'collection_agency_type_id' => (int) $payload['collection_agency_type_id'],\n"
            "                    'collection_agency_id' => (int) $payload['collection_agency_id'],\n"
            "                    'bank_account' => $this->normalizePromoventeText($payload, 'bank_account'),\n"
            "                ]);",
        ),
        # update(): the mirror probe over the RESULTING state.
        (
            "        $this->assertUpdateReferencesAreActive($payload);\n"
            "        $this->assertRequestedAtIsNotFuture($payload);",
            "        $this->assertUpdateReferencesAreActive($payload);\n"
            "        // Task 42: the residence + collection group is editable — the\n"
            "        // probe mirrors the store against the RESULTING state (the\n"
            "        // stored case answers every key the PATCH did not declare).\n"
            "        $this->assertResidenceAndCollectionAreCoherent($payload, $case);\n"
            "        $this->assertRequestedAtIsNotFuture($payload);",
        ),
        # update(): bank_account normalization before persisting.
        (
            "        if (isset($payload['last_salary'])) {\n"
            "            $payload['last_salary'] = Money::fromString((string) $payload['last_salary'])->__toString();\n"
            "        }",
            "        if (isset($payload['last_salary'])) {\n"
            "            $payload['last_salary'] = Money::fromString((string) $payload['last_salary'])->__toString();\n"
            "        }\n"
            "\n"
            "        if (array_key_exists('bank_account', $payload)) {\n"
            "            // Task 42: an explicit null/'' CLEARS the account (the\n"
            "            // conditional demand was already re-evaluated against the\n"
            "            // resulting state by the probe above).\n"
            "            $payload['bank_account'] = $this->normalizePromoventeText($payload, 'bank_account');\n"
            "        }",
        ),
        # incomeConceptRows: the applied percent per row.
        (
            "            $seen[$conceptId] = true;\n"
            "\n"
            "            $normalized[] = [\n"
            "                'income_concept_id' => $conceptId,\n"
            "                'amount' => Money::fromString((string) ($row['amount'] ?? ''))->__toString(),\n"
            "            ];",
            "            $seen[$conceptId] = true;\n"
            "\n"
            "            // Task 42: the percent to apply is REQUIRED per row — same\n"
            "            // doctrine as the money shape (RN-005).\n"
            "            $appliedPercent = (string) ($row['applied_percent'] ?? '');\n"
            "            $this->assertAppliedPercentIsWellFormed(\n"
            "                $appliedPercent,\n"
            "                \"income_concept_records.{$index}.applied_percent\",\n"
            "            );\n"
            "\n"
            "            $normalized[] = [\n"
            "                'income_concept_id' => $conceptId,\n"
            "                'amount' => Money::fromString((string) ($row['amount'] ?? ''))->__toString(),\n"
            "                'applied_percent' => $appliedPercent,\n"
            "            ];",
        ),
        # addIncomeConceptRecord: signature + validation + repository call.
        (
            "    public function addIncomeConceptRecord(int $caseId, int $incomeConceptId, string $amount): ?IncomeConceptRecord\n"
            "    {\n"
            "        $case = $this->caseOrNull($caseId);\n"
            "\n"
            "        if ($case === null) {\n"
            "            return null;\n"
            "        }\n"
            "\n"
            "        $this->assertCaseIsEditable($case);\n"
            "\n"
            "        if ($this->catalogs->find(IncomeConcept::class, $incomeConceptId) === null) {\n"
            "            throw ValidationException::withMessages([\n"
            "                'income_concept_id' => \"Income concept {$incomeConceptId} does not exist or is deactivated.\",\n"
            "            ]);\n"
            "        }\n"
            "\n"
            "        if ($this->cases->incomeConceptExists($caseId, $incomeConceptId)) {\n"
            "            // User rule 5: one declared value per (case, concept) —\n"
            "            // the semantic probe of the UNIQUE, 422 not a driver\n"
            "            // error (RN-008 convention).\n"
            "            throw new DuplicateIncomeConceptException($incomeConceptId, $caseId);\n"
            "        }\n"
            "\n"
            "        $money = Money::fromString($amount);\n"
            "\n"
            "        return $this->transactions->execute(\n"
            "            fn (): IncomeConceptRecord => $this->cases->addIncomeConceptRecord($case, $incomeConceptId, $money->__toString()),\n"
            "        );\n"
            "    }",
            "    public function addIncomeConceptRecord(int $caseId, int $incomeConceptId, string $amount, string $appliedPercent): ?IncomeConceptRecord\n"
            "    {\n"
            "        $case = $this->caseOrNull($caseId);\n"
            "\n"
            "        if ($case === null) {\n"
            "            return null;\n"
            "        }\n"
            "\n"
            "        $this->assertCaseIsEditable($case);\n"
            "\n"
            "        if ($this->catalogs->find(IncomeConcept::class, $incomeConceptId) === null) {\n"
            "            throw ValidationException::withMessages([\n"
            "                'income_concept_id' => \"Income concept {$incomeConceptId} does not exist or is deactivated.\",\n"
            "            ]);\n"
            "        }\n"
            "\n"
            "        if ($this->cases->incomeConceptExists($caseId, $incomeConceptId)) {\n"
            "            // User rule 5: one declared value per (case, concept) —\n"
            "            // the semantic probe of the UNIQUE, 422 not a driver\n"
            "            // error (RN-008 convention).\n"
            "            throw new DuplicateIncomeConceptException($incomeConceptId, $caseId);\n"
            "        }\n"
            "\n"
            "        // Task 42: the percent to apply is REQUIRED — same doctrine as\n"
            "        // the money shape (RN-005).\n"
            "        $this->assertAppliedPercentIsWellFormed($appliedPercent, 'applied_percent');\n"
            "\n"
            "        $money = Money::fromString($amount);\n"
            "\n"
            "        return $this->transactions->execute(\n"
            "            fn (): IncomeConceptRecord => $this->cases->addIncomeConceptRecord(\n"
            "                $case,\n"
            "                $incomeConceptId,\n"
            "                $money->__toString(),\n"
            "                $appliedPercent,\n"
            "            ),\n"
            "        );\n"
            "    }",
        ),
        # New probes: assertResidenceAndCollectionAreCoherent +
        # assertAppliedPercentIsWellFormed (after assertReferencesAreActive).
        (
            "        foreach ($catalogProbes as $key => $modelClass) {\n"
            "            if ($this->catalogs->find($modelClass, (int) $payload[$key]) === null) {\n"
            "                throw ValidationException::withMessages([\n"
            "                    $key => 'The referenced catalog entry does not exist or is deactivated.',\n"
            "                ]);\n"
            "            }\n"
            "        }\n"
            "    }",
            "        foreach ($catalogProbes as $key => $modelClass) {\n"
            "            if ($this->catalogs->find($modelClass, (int) $payload[$key]) === null) {\n"
            "                throw ValidationException::withMessages([\n"
            "                    $key => 'The referenced catalog entry does not exist or is deactivated.',\n"
            "                ]);\n"
            "            }\n"
            "        }\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * Task 42 (user correction, SGP-36): coherence of the promovente\n"
            "     * residence + collection group. Probes the ACTIVE surface of every\n"
            "     * reference (province, municipality, agency type, agency), the\n"
            "     * RN-04 municipality-province coherence of the residence, the\n"
            "     * agency-of-the-declared-type rule of the collection point and the\n"
            "     * CONDITIONAL bank-account demand: the account is required (422 on\n"
            "     * bank_account) when the payment form of the collection agency\n"
            "     * type is 'tarjeta magnetica', optional with 'nomina electronica'.\n"
            "     *\n"
            "     * The optional $stored case carries the RESULTING state on the\n"
            "     * update path (PATCH semantics: every key the wire did not declare\n"
            "     * keeps its stored value), so the very same probe guards both\n"
            "     * write paths.\n"
            "     *\n"
            "     * @param  array<string, mixed>  $payload\n"
            "     */\n"
            "    private function assertResidenceAndCollectionAreCoherent(array $payload, ?PensionCase $stored = null): void\n"
            "    {\n"
            "        $groupKeys = [\n"
            "            'current_address',\n"
            "            'residence_province_id',\n"
            "            'residence_municipality_id',\n"
            "            'collection_agency_type_id',\n"
            "            'collection_agency_id',\n"
            "            'bank_account',\n"
            "        ];\n"
            "\n"
            "        if (array_intersect($groupKeys, array_keys($payload)) === []) {\n"
            "            return;\n"
            "        }\n"
            "\n"
            "        // Residence geography: ACTIVE surface + RN-04 coherence.\n"
            "        $provinceId = (int) ($payload['residence_province_id'] ?? $stored?->residence_province_id);\n"
            "        $municipalityId = (int) ($payload['residence_municipality_id'] ?? $stored?->residence_municipality_id);\n"
            "\n"
            "        if ($this->catalogs->find(Province::class, $provinceId) === null) {\n"
            "            throw ValidationException::withMessages([\n"
            "                'residence_province_id' => 'The residence province does not exist or is deactivated.',\n"
            "            ]);\n"
            "        }\n"
            "\n"
            "        $municipality = $this->catalogs->find(Municipality::class, $municipalityId);\n"
            "\n"
            "        if ($municipality === null) {\n"
            "            throw ValidationException::withMessages([\n"
            "                'residence_municipality_id' => 'The residence municipality does not exist or is deactivated.',\n"
            "            ]);\n"
            "        }\n"
            "\n"
            "        if ($municipality->province_id !== $provinceId) {\n"
            "            // RN-04: the municipality belongs to the declared province —\n"
            "            // the special municipality (province NULL) stays out of the\n"
            "            // residence domain (P-09).\n"
            "            throw ValidationException::withMessages([\n"
            "                'residence_municipality_id' => 'The residence municipality does not belong to the declared residence province.',\n"
            "            ]);\n"
            "        }\n"
            "\n"
            "        // Collection point: ACTIVE agency of the declared type.\n"
            "        $agencyTypeId = (int) ($payload['collection_agency_type_id'] ?? $stored?->collection_agency_type_id);\n"
            "        $agencyId = (int) ($payload['collection_agency_id'] ?? $stored?->collection_agency_id);\n"
            "\n"
            "        $agencyType = $this->catalogs->find(AgencyType::class, $agencyTypeId);\n"
            "\n"
            "        if ($agencyType === null) {\n"
            "            throw ValidationException::withMessages([\n"
            "                'collection_agency_type_id' => 'The collection agency type does not exist or is deactivated.',\n"
            "            ]);\n"
            "        }\n"
            "\n"
            "        $agency = $this->catalogs->find(Agency::class, $agencyId);\n"
            "\n"
            "        if ($agency === null) {\n"
            "            throw ValidationException::withMessages([\n"
            "                'collection_agency_id' => 'The collection agency does not exist or is deactivated.',\n"
            "            ]);\n"
            "        }\n"
            "\n"
            "        if ($agency->agency_type_id !== $agencyTypeId) {\n"
            "            throw ValidationException::withMessages([\n"
            "                'collection_agency_id' => 'The collection agency does not belong to the declared collection agency type.',\n"
            "            ]);\n"
            "        }\n"
            "\n"
            "        // Conditional bank account: demanded by the payment form of the\n"
            "        // collection agency type — an explicit null CLEARS on the update\n"
            "        // path, so array_key_exists (not ??) resolves the resulting\n"
            "        // value.\n"
            "        $bankAccount = array_key_exists('bank_account', $payload)\n"
            "            ? $payload['bank_account']\n"
            "            : $stored?->bank_account;\n"
            "        $bankAccount = $bankAccount === null || $bankAccount === '' ? null : (string) $bankAccount;\n"
            "\n"
            "        if ($agencyType->payment_form === PaymentForm::TarjetaMagnetica->value && $bankAccount === null) {\n"
            "            throw ValidationException::withMessages([\n"
            "                'bank_account' => 'The bank account is required when the payment form of the collection agency type is tarjeta magnetica.',\n"
            "            ]);\n"
            "        }\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * Task 42 (user correction, SGP-36): shape of the applied percent\n"
            "     * — range 0-100 with at most two decimals, exact decimal string\n"
            "     * (the RN-005 doctrine: never a binary float).\n"
            "     */\n"
            "    private function assertAppliedPercentIsWellFormed(string $percent, string $field): void\n"
            "    {\n"
            "        if (preg_match(self::APPLIED_PERCENT_PATTERN, $percent) !== 1 || (float) $percent > 100.0) {\n"
            "            throw ValidationException::withMessages([\n"
            "                $field => 'The applied percent must be a decimal between 0 and 100 with at most two decimals.',\n"
            "            ]);\n"
            "        }\n"
            "    }",
        ),
        # The pattern constant next to CASE_SEQUENCE.
        (
            "    private const string CASE_SEQUENCE = 'pension_case';\n",
            "    private const string CASE_SEQUENCE = 'pension_case';\n"
            "\n"
            "    /**\n"
            "     * Task 42: shape of the applied percent (range 0-100, at most two\n"
            "     * decimals) — the same doctrine the money regex applies to every\n"
            "     * importe (RN-005: exact decimal string, never a binary float).\n"
            "     */\n"
            "    private const string APPLIED_PERCENT_PATTERN = '/^\\d{1,3}(\\.\\d{1,2})?$/';\n",
        ),
    ],
)

# ---------------------------------------------------------------------------
# EloquentPensionCaseRepository — eager loads + income rows.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Infrastructure/Persistence/EloquentPensionCaseRepository.php",
    [
        (
            "                'incomeConceptRecords',\n"
            "                'applicant',\n"
            "                'filedBy',\n"
            "            ])",
            "                'incomeConceptRecords',\n"
            "                'applicant',\n"
            "                'filedBy',\n"
            "                // Task 42: the promovente residence + collection\n"
            "                // projections (the agency carries its geography and\n"
            "                // type so the full AgencyResource shape answers).\n"
            "                'residenceProvince',\n"
            "                'residenceMunicipality',\n"
            "                'collectionAgencyType',\n"
            "                'collectionAgency.province',\n"
            "                'collectionAgency.municipality',\n"
            "                'collectionAgency.agencyType',\n"
            "            ])",
        ),
        (
            "        foreach ($rows as $row) {\n"
            "            $case->incomeConceptRecords()->create([\n"
            "                'income_concept_id' => $row['income_concept_id'],\n"
            "                'amount' => $row['amount'],\n"
            "            ]);\n"
            "        }",
            "        foreach ($rows as $row) {\n"
            "            $case->incomeConceptRecords()->create([\n"
            "                'income_concept_id' => $row['income_concept_id'],\n"
            "                'amount' => $row['amount'],\n"
            "                'applied_percent' => $row['applied_percent'],\n"
            "            ]);\n"
            "        }",
        ),
        (
            "    public function addIncomeConceptRecord(PensionCase $case, int $incomeConceptId, string $amount): IncomeConceptRecord\n"
            "    {\n"
            "        return $case->incomeConceptRecords()->create([\n"
            "            'income_concept_id' => $incomeConceptId,\n"
            "            'amount' => $amount,\n"
            "        ]);\n"
            "    }",
            "    public function addIncomeConceptRecord(PensionCase $case, int $incomeConceptId, string $amount, string $appliedPercent): IncomeConceptRecord\n"
            "    {\n"
            "        return $case->incomeConceptRecords()->create([\n"
            "            'income_concept_id' => $incomeConceptId,\n"
            "            'amount' => $amount,\n"
            "            'applied_percent' => $appliedPercent,\n"
            "        ]);\n"
            "    }",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCase model — columns, casts, relations.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Infrastructure/Persistence/Models/PensionCase.php",
    [
        (
            " * fecha de desvinculación — termination_date, a plain nullable date\n"
            " * with no semantic probe (the user correction declares it optional).\n"
            " * Authorship is stamped by the Shared AuditableObserver and every\n"
            " * write lands in the append-only trail through the Shared\n"
            " * AuditTrailObserver, both registered in the\n"
            " * PensionCasesServiceProvider.",
            " * fecha de desvinculación — termination_date, a plain nullable date\n"
            " * with no semantic probe (the user correction declares it optional).\n"
            " * Since Task 42 (user correction, SGP-36) the case carries the\n"
            " * promovente residence + collection group: current_address, the\n"
            " * residence geography (province + municipality, RN-04 coherent), the\n"
            " * collection point (agency type + agency, the agency of the declared\n"
            " * type) and the bank account — REQUIRED CONDITIONALLY on the payment\n"
            " * form of the collection agency type ('tarjeta magnetica' demands it,\n"
            " * 'nomina electronica' leaves it optional); the group is EDITABLE\n"
            " * through the PUT. Authorship is stamped by the Shared\n"
            " * AuditableObserver and every write lands in the append-only trail\n"
            " * through the Shared AuditTrailObserver, both registered in the\n"
            " * PensionCasesServiceProvider.",
        ),
        (
            " * @property CarbonImmutable|null $termination_date\n"
            " * @property int|null $approval_legal_basis_id\n",
            " * @property CarbonImmutable|null $termination_date\n"
            " * @property string $current_address\n"
            " * @property int $residence_province_id\n"
            " * @property int $residence_municipality_id\n"
            " * @property int $collection_agency_type_id\n"
            " * @property int $collection_agency_id\n"
            " * @property string|null $bank_account\n"
            " * @property int|null $approval_legal_basis_id\n",
        ),
        (
            " * @property-read \\App\\Modules\\People\\Infrastructure\\Persistence\\Models\\Person|null $applicant\n"
            " * @property-read \\App\\Modules\\People\\Infrastructure\\Persistence\\Models\\Person|null $filedBy\n"
            " */",
            " * @property-read \\App\\Modules\\People\\Infrastructure\\Persistence\\Models\\Person|null $applicant\n"
            " * @property-read \\App\\Modules\\People\\Infrastructure\\Persistence\\Models\\Person|null $filedBy\n"
            " * @property-read \\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Province|null $residenceProvince\n"
            " * @property-read \\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Municipality|null $residenceMunicipality\n"
            " * @property-read \\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\AgencyType|null $collectionAgencyType\n"
            " * @property-read \\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Agency|null $collectionAgency\n"
            " */",
        ),
        (
            "        'termination_date',\n"
            "        'approval_legal_basis_id',",
            "        'termination_date',\n"
            "        // Task 42: promovente residence + collection group.\n"
            "        'current_address',\n"
            "        'residence_province_id',\n"
            "        'residence_municipality_id',\n"
            "        'collection_agency_type_id',\n"
            "        'collection_agency_id',\n"
            "        'bank_account',\n"
            "        'approval_legal_basis_id',",
        ),
        (
            "            'termination_date' => 'immutable_date',\n"
            "            'filed_by_person_id' => 'integer',",
            "            'termination_date' => 'immutable_date',\n"
            "            'residence_province_id' => 'integer',\n"
            "            'residence_municipality_id' => 'integer',\n"
            "            'collection_agency_type_id' => 'integer',\n"
            "            'collection_agency_id' => 'integer',\n"
            "            'filed_by_person_id' => 'integer',",
        ),
        (
            "    public function filedBy(): BelongsTo\n"
            "    {\n"
            "        return $this->belongsTo(\\App\\Modules\\People\\Infrastructure\\Persistence\\Models\\Person::class, 'filed_by_person_id');\n"
            "    }\n"
            "}",
            "    public function filedBy(): BelongsTo\n"
            "    {\n"
            "        return $this->belongsTo(\\App\\Modules\\People\\Infrastructure\\Persistence\\Models\\Person::class, 'filed_by_person_id');\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * Task 42: residence geography of the promovente — the municipality\n"
            "     * belongs to the province (RN-04, probed by the service).\n"
            "     *\n"
            "     * @return BelongsTo<\\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Province, $this>\n"
            "     */\n"
            "    public function residenceProvince(): BelongsTo\n"
            "    {\n"
            "        return $this->belongsTo(\\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Province::class, 'residence_province_id');\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * @return BelongsTo<\\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Municipality, $this>\n"
            "     */\n"
            "    public function residenceMunicipality(): BelongsTo\n"
            "    {\n"
            "        return $this->belongsTo(\\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Municipality::class, 'residence_municipality_id');\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * Task 42: collection point — the agency type carries the payment\n"
            "     * form that decides the conditional bank-account demand.\n"
            "     *\n"
            "     * @return BelongsTo<\\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\AgencyType, $this>\n"
            "     */\n"
            "    public function collectionAgencyType(): BelongsTo\n"
            "    {\n"
            "        return $this->belongsTo(\\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\AgencyType::class, 'collection_agency_type_id');\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * @return BelongsTo<\\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Agency, $this>\n"
            "     */\n"
            "    public function collectionAgency(): BelongsTo\n"
            "    {\n"
            "        return $this->belongsTo(\\App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Agency::class, 'collection_agency_id');\n"
            "    }\n"
            "}",
        ),
    ],
)

# ---------------------------------------------------------------------------
# IncomeConceptRecord model — applied_percent.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Infrastructure/Persistence/Models/IncomeConceptRecord.php",
    [
        (
            " * Eloquent model for the declared income concepts of a case (user\n"
            " * rule 5, model data 5.7 sibling of salary_records): ONE value per\n"
            " * (case, concept) pair, DECIMAL(12,2) exact money (RN-005).",
            " * Eloquent model for the declared income concepts of a case (user\n"
            " * rule 5, model data 5.7 sibling of salary_records): ONE value per\n"
            " * (case, concept) pair, DECIMAL(12,2) exact money (RN-005) plus the\n"
            " * percent to apply (Task 42, user correction, SGP-36) — a Double in\n"
            " * the user's words materialized as DECIMAL(5,2) with range 0-100 and\n"
            " * the database DEFAULT 0.00 covering only the writes outside the\n"
            " * wire (both write paths demand it: 422 when omitted, out of range\n"
            " * or carrying a third decimal).",
        ),
        (
            " * @property int $income_concept_id\n"
            " * @property string $amount\n"
            " * @property IncomeConcept|null $incomeConcept\n"
            " */",
            " * @property int $income_concept_id\n"
            " * @property string $amount\n"
            " * @property string $applied_percent\n"
            " * @property IncomeConcept|null $incomeConcept\n"
            " */",
        ),
        (
            "    /** @var list<string> */\n"
            "    protected $fillable = [\n"
            "        'pension_case_id',\n"
            "        'income_concept_id',\n"
            "        'amount',\n"
            "    ];",
            "    /** @var list<string> */\n"
            "    protected $fillable = [\n"
            "        'pension_case_id',\n"
            "        'income_concept_id',\n"
            "        'amount',\n"
            "        'applied_percent',\n"
            "    ];",
        ),
        (
            "        return [\n"
            "            'pension_case_id' => 'integer',\n"
            "            'income_concept_id' => 'integer',\n"
            "            'amount' => 'decimal:2',\n"
            "        ];",
            "        return [\n"
            "            'pension_case_id' => 'integer',\n"
            "            'income_concept_id' => 'integer',\n"
            "            'amount' => 'decimal:2',\n"
            "            'applied_percent' => 'decimal:2',\n"
            "        ];",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCaseResource — the group fields + projections.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Presentation/Resources/PensionCaseResource.php",
    [
        (
            "use App\\Modules\\PensionCases\\Infrastructure\\Persistence\\Models\\PensionCase;\n"
            "use App\\Modules\\People\\Presentation\\Resources\\PersonResource;\n",
            "use App\\Modules\\Catalogs\\Presentation\\Resources\\AgencyResource;\n"
            "use App\\Modules\\Catalogs\\Presentation\\Resources\\CatalogResource;\n"
            "use App\\Modules\\PensionCases\\Infrastructure\\Persistence\\Models\\PensionCase;\n"
            "use App\\Modules\\People\\Presentation\\Resources\\PersonResource;\n",
        ),
        (
            " * Task 38 user correction (SGP-32) the case also answers the\n"
            " * promovente's fecha de desvinculación — termination_date, an\n"
            " * optional date serialized as Y-m-d and null when absent.",
            " * Task 38 user correction (SGP-32) the case also answers the\n"
            " * promovente's fecha de desvinculación — termination_date, an\n"
            " * optional date serialized as Y-m-d and null when absent. Since the\n"
            " * Task 42 user correction (SGP-36) the case answers the promovente\n"
            " * residence + collection group — current_address, the residence\n"
            " * geography and the collection point with their projections\n"
            " * (province, municipality, agency type carrying its payment form,\n"
            " * full agency) and the bank account, null when the payment form of\n"
            " * the collection agency type leaves it optional.",
        ),
        (
            "        new OA\\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true, example: '2025-07-31', description: 'Fecha de desvinculación del promovente (Task 38, corrección de usuario): opcional, Y-m-d; la omisión persiste null'),\n"
            "        new OA\\Property(property: 'filed_by', nullable: true, allOf: [new OA\\Schema(ref: '#/components/schemas/Person')], description: 'Proyección COMPLETA de la persona por (Task 35): misma forma que applicant'),",
            "        new OA\\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true, example: '2025-07-31', description: 'Fecha de desvinculación del promovente (Task 38, corrección de usuario): opcional, Y-m-d; la omisión persiste null'),\n"
            "        new OA\\Property(property: 'current_address', type: 'string', example: 'Calle 8 #10 entre 5 y 7, Playa', description: 'Dirección actual del promovente (Task 42, corrección de usuario): obligatoria en el alta'),\n"
            "        new OA\\Property(property: 'residence_province_id', type: 'integer', format: 'int64', example: 11, description: 'Provincia de residencia del promovente (Task 42): obligatoria, coherente con el municipio (RN-04)'),\n"
            "        new OA\\Property(property: 'residence_municipality_id', type: 'integer', format: 'int64', example: 3, description: 'Municipio de residencia del promovente (Task 42): obligatorio, pertenece a la provincia declarada'),\n"
            "        new OA\\Property(property: 'collection_agency_type_id', type: 'integer', format: 'int64', example: 1, description: 'Tipo de agencia de cobro (Task 42): obligatorio; su forma de pago decide la exigencia de la cuenta bancaria'),\n"
            "        new OA\\Property(property: 'collection_agency_id', type: 'integer', format: 'int64', example: 7, description: 'Agencia de cobro (Task 42): obligatoria, activa y del tipo declarado'),\n"
            "        new OA\\Property(property: 'bank_account', type: 'string', nullable: true, maxLength: 34, example: '01234567890123456789012345678', description: 'Cuenta bancaria del cobro (Task 42): OBLIGATORIA cuando la forma de pago del tipo de agencia de cobro es tarjeta magnetica (422 si falta), opcional con nomina electronica'),\n"
            "        new OA\\Property(property: 'residence_province', nullable: true, type: 'object', description: 'Provincia de residencia (Task 42)', properties: [\n"
            "            new OA\\Property(property: 'id', type: 'integer', format: 'int64'),\n"
            "            new OA\\Property(property: 'code', type: 'string'),\n"
            "            new OA\\Property(property: 'name', type: 'string'),\n"
            "        ]),\n"
            "        new OA\\Property(property: 'residence_municipality', nullable: true, type: 'object', description: 'Municipio de residencia (Task 42)', properties: [\n"
            "            new OA\\Property(property: 'id', type: 'integer', format: 'int64'),\n"
            "            new OA\\Property(property: 'code', type: 'string'),\n"
            "            new OA\\Property(property: 'name', type: 'string'),\n"
            "        ]),\n"
            "        new OA\\Property(property: 'collection_agency_type', nullable: true, allOf: [new OA\\Schema(ref: '#/components/schemas/CatalogItem')], description: 'Tipo de agencia de cobro con su payment_form (Task 42): misma forma que el catálogo agency-types'),\n"
            "        new OA\\Property(property: 'collection_agency', nullable: true, allOf: [new OA\\Schema(ref: '#/components/schemas/Agency')], description: 'Agencia de cobro completa (Task 42): misma forma que el catálogo de agencias'),\n"
            "        new OA\\Property(property: 'filed_by', nullable: true, allOf: [new OA\\Schema(ref: '#/components/schemas/Person')], description: 'Proyección COMPLETA de la persona por (Task 35): misma forma que applicant'),",
        ),
        (
            "            'termination_date' => $this->termination_date?->format('Y-m-d'),\n"
            "            'approval_legal_basis_id' => $this->approval_legal_basis_id,",
            "            'termination_date' => $this->termination_date?->format('Y-m-d'),\n"
            "            // Task 42: promovente residence + collection group.\n"
            "            'current_address' => $this->current_address,\n"
            "            'residence_province_id' => $this->residence_province_id,\n"
            "            'residence_municipality_id' => $this->residence_municipality_id,\n"
            "            'collection_agency_type_id' => $this->collection_agency_type_id,\n"
            "            'collection_agency_id' => $this->collection_agency_id,\n"
            "            'bank_account' => $this->bank_account,\n"
            "            'approval_legal_basis_id' => $this->approval_legal_basis_id,",
        ),
        (
            "            'filed_by' => $this->whenLoaded(\n"
            "                'filedBy',\n"
            "                fn () => $this->filedBy === null ? null : new PersonResource($this->filedBy),\n"
            "            ),\n"
            "        ];",
            "            'filed_by' => $this->whenLoaded(\n"
            "                'filedBy',\n"
            "                fn () => $this->filedBy === null ? null : new PersonResource($this->filedBy),\n"
            "            ),\n"
            "            // Task 42: residence + collection projections — the agency\n"
            "            // type rides the generic catalog resource (it carries the\n"
            "            // payment form) and the agency its full resource shape.\n"
            "            'residence_province' => $this->whenLoaded('residenceProvince', fn () => [\n"
            "                'id' => $this->residenceProvince?->id,\n"
            "                'code' => $this->residenceProvince?->code,\n"
            "                'name' => $this->residenceProvince?->name,\n"
            "            ]),\n"
            "            'residence_municipality' => $this->whenLoaded('residenceMunicipality', fn () => [\n"
            "                'id' => $this->residenceMunicipality?->id,\n"
            "                'code' => $this->residenceMunicipality?->code,\n"
            "                'name' => $this->residenceMunicipality?->name,\n"
            "            ]),\n"
            "            'collection_agency_type' => $this->whenLoaded(\n"
            "                'collectionAgencyType',\n"
            "                fn () => $this->collectionAgencyType === null ? null : new CatalogResource($this->collectionAgencyType),\n"
            "            ),\n"
            "            'collection_agency' => $this->whenLoaded(\n"
            "                'collectionAgency',\n"
            "                fn () => $this->collectionAgency === null ? null : new AgencyResource($this->collectionAgency),\n"
            "            ),\n"
            "        ];",
        ),
    ],
)

# ---------------------------------------------------------------------------
# IncomeConceptRecordResource — applied_percent.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Presentation/Resources/IncomeConceptRecordResource.php",
    [
        (
            "    description: 'Valor declarado de un concepto de ingreso del expediente (regla de usuario 5): el par expediente-concepto es único y el valor es DECIMAL(12,2) no negativo (RN-005).',",
            "    description: 'Valor declarado de un concepto de ingreso del expediente (regla de usuario 5): el par expediente-concepto es único, el valor es DECIMAL(12,2) no negativo (RN-005) y el porciento a aplicar (Task 42, corrección de usuario) es DECIMAL(5,2) en el rango 0-100 con dos decimales exactos.',",
        ),
        (
            "        new OA\\Property(property: 'amount', type: 'string', example: '150.00', description: 'Importe exacto con dos decimales (RN-005)'),\n"
            "    ],",
            "        new OA\\Property(property: 'amount', type: 'string', example: '150.00', description: 'Importe exacto con dos decimales (RN-005)'),\n"
            "        new OA\\Property(property: 'applied_percent', type: 'string', example: '100.00', description: 'Porciento a aplicar (Task 42, corrección de usuario): Double como decimal exacto en el rango 0-100 con dos decimales — obligatorio en el alta'),\n"
            "    ],",
        ),
        (
            "            'income_concept_id' => $this->income_concept_id,\n"
            "            'amount' => (string) $this->amount,\n"
            "        ];",
            "            'income_concept_id' => $this->income_concept_id,\n"
            "            'amount' => (string) $this->amount,\n"
            "            'applied_percent' => (string) $this->applied_percent,\n"
            "        ];",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCaseController — OA of store/update/income + the service call.
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Presentation/Controllers/PensionCaseController.php",
    [
        # store description.
        (
            "Task 37: la marca internacionalista del promovente es booleana OBLIGATORIA (paralelo del par rebelde) y el par de contacto (phone, popular_council) viaja opcional; los subregistros de servicio exigen end_date OBLIGATORIA, estrictamente posterior a start_date y SIN solapamiento entre filas (422 con nada creado).",
            "Task 37: la marca internacionalista del promovente es booleana OBLIGATORIA (paralelo del par rebelde) y el par de contacto (phone, popular_council) viaja opcional; los subregistros de servicio exigen end_date OBLIGATORIA, estrictamente posterior a start_date y SIN solapamiento entre filas (422 con nada creado). Task 42: el domicilio y cobro del promovente viajan en el alta — dirección actual, provincia y municipio de residencia (coherentes, RN-04), tipo de agencia de cobro, agencia de cobro (del tipo declarado) y cuenta bancaria OBLIGATORIA CONDICIONAL a la forma de pago del tipo de agencia (exigida con tarjeta magnetica, opcional con nomina electronica: 422 sobre bank_account) — y cada concepto de ingreso declarado viaja con su porciento a aplicar (0-100, dos decimales).",
        ),
        # store required list.
        (
            "                required: ['applicant_person_id', 'employer_entity_id', 'position_id', 'occupational_category_id', 'educational_level_id', 'scientific_category_id', 'pension_type_id', 'pension_regime_id', 'rebel_army_member', 'internationalist', 'last_salary'],",
            "                required: ['applicant_person_id', 'employer_entity_id', 'position_id', 'occupational_category_id', 'educational_level_id', 'scientific_category_id', 'pension_type_id', 'pension_regime_id', 'rebel_army_member', 'internationalist', 'current_address', 'residence_province_id', 'residence_municipality_id', 'collection_agency_type_id', 'collection_agency_id', 'last_salary'],",
        ),
        # store properties: the group after termination_date.
        (
            "                    new OA\\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true, example: '2025-07-31', description: 'Fecha de desvinculación del promovente (Task 38, corrección de usuario): opcional, Y-m-d; 422 con formato inválido, la omisión persiste null'),\n"
            "                    new OA\\Property(property: 'last_salary', type: 'string', example: '5000.00', description: 'Último salario, decimal exacto no negativo (RN-005)'),",
            "                    new OA\\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true, example: '2025-07-31', description: 'Fecha de desvinculación del promovente (Task 38, corrección de usuario): opcional, Y-m-d; 422 con formato inválido, la omisión persiste null'),\n"
            "                    new OA\\Property(property: 'current_address', type: 'string', example: 'Calle 8 #10 entre 5 y 7, Playa', description: 'Dirección actual del promovente (Task 42, corrección de usuario): OBLIGATORIA; 422 si se omite'),\n"
            "                    new OA\\Property(property: 'residence_province_id', type: 'integer', format: 'int64', example: 11, description: 'Provincia de residencia del promovente (Task 42): OBLIGATORIA, activa; el municipio debe pertenecerle (RN-04, 422)'),\n"
            "                    new OA\\Property(property: 'residence_municipality_id', type: 'integer', format: 'int64', example: 3, description: 'Municipio de residencia del promovente (Task 42): OBLIGATORIO, activo y de la provincia declarada (422)'),\n"
            "                    new OA\\Property(property: 'collection_agency_type_id', type: 'integer', format: 'int64', example: 1, description: 'Tipo de agencia de cobro (Task 42): OBLIGATORIO, activo; su payment_form (tarjeta magnetica|nomina electronica) decide la exigencia de la cuenta bancaria'),\n"
            "                    new OA\\Property(property: 'collection_agency_id', type: 'integer', format: 'int64', example: 7, description: 'Agencia de cobro (Task 42): OBLIGATORIA, activa y del tipo declarado (422)'),\n"
            "                    new OA\\Property(property: 'bank_account', type: 'string', nullable: true, maxLength: 34, example: '01234567890123456789012345678', description: 'Cuenta bancaria del cobro (Task 42): OBLIGATORIA CONDICIONAL — exigida (422 sobre bank_account) cuando la forma de pago del tipo de agencia de cobro es tarjeta magnetica, opcional con nomina electronica (la omisión persiste null)'),\n"
            "                    new OA\\Property(property: 'last_salary', type: 'string', example: '5000.00', description: 'Último salario, decimal exacto no negativo (RN-005)'),",
        ),
        # store nested income rows gain applied_percent.
        (
            "                            properties: [\n"
            "                                new OA\\Property(property: 'income_concept_id', type: 'integer', example: 3),\n"
            "                                new OA\\Property(property: 'amount', type: 'string', example: '150.00'),\n"
            "                            ],",
            "                            properties: [\n"
            "                                new OA\\Property(property: 'income_concept_id', type: 'integer', example: 3),\n"
            "                                new OA\\Property(property: 'amount', type: 'string', example: '150.00'),\n"
            "                                new OA\\Property(property: 'applied_percent', type: 'string', example: '100.00', description: 'Porciento a aplicar (Task 42): OBLIGATORIO por fila — decimal exacto 0-100 con dos decimales (422 si se omite, fuera de rango o con tercera decimal)'),\n"
            "                            ],",
        ),
        # update description.
        (
            "La edición solo corre mientras el expediente está en submitted (409 fuera, con el estado actual). Las advertencias de la serie salarial viajan junto a data.",
            "La edición solo corre mientras el expediente está en submitted (409 fuera, con el estado actual). Task 42: el grupo de DOMICILIO y COBRO del promovente — dirección actual, provincia y municipio de residencia, tipo de agencia de cobro, agencia de cobro y cuenta bancaria — SÍ es editable (decisión explícita del usuario: pueden modificarse) con probes espejo del alta y la exigencia condicional de la cuenta re-evaluada contra el estado RESULTANTE (cambiar el tipo de agencia de cobro a uno con forma de pago tarjeta magnetica exige la cuenta si acabó en null). Las advertencias de la serie salarial viajan junto a data.",
        ),
        # update properties: the editable group after requested_at.
        (
            "                    new OA\\Property(property: 'requested_at', type: 'string', format: 'date', example: '2026-09-30', description: 'Fecha de solicitud (editable, nunca futura)'),\n"
            "                    new OA\\Property(property: 'applicant_person_id', type: 'integer', example: 7, description: 'PROHIBIDO (SGP-34): el promovente de la pensión es no modificable — 422 si se envía'),",
            "                    new OA\\Property(property: 'requested_at', type: 'string', format: 'date', example: '2026-09-30', description: 'Fecha de solicitud (editable, nunca futura)'),\n"
            "                    new OA\\Property(property: 'current_address', type: 'string', example: 'Calle 23 #100, Vedado', description: 'Dirección actual del promovente (Task 42, EDITABLE): semántica PATCH'),\n"
            "                    new OA\\Property(property: 'residence_province_id', type: 'integer', format: 'int64', example: 11, description: 'Provincia de residencia (Task 42, EDITABLE): coherente con el municipio resultante (RN-04)'),\n"
            "                    new OA\\Property(property: 'residence_municipality_id', type: 'integer', format: 'int64', example: 3, description: 'Municipio de residencia (Task 42, EDITABLE): de la provincia resultante'),\n"
            "                    new OA\\Property(property: 'collection_agency_type_id', type: 'integer', format: 'int64', example: 1, description: 'Tipo de agencia de cobro (Task 42, EDITABLE): su payment_form decide la exigencia de la cuenta resultante'),\n"
            "                    new OA\\Property(property: 'collection_agency_id', type: 'integer', format: 'int64', example: 7, description: 'Agencia de cobro (Task 42, EDITABLE): activa y del tipo resultante'),\n"
            "                    new OA\\Property(property: 'bank_account', type: 'string', nullable: true, maxLength: 34, example: '01234567890123456789012345678', description: 'Cuenta bancaria del cobro (Task 42, EDITABLE): null explícito la LIMPIA; 422 si el estado resultante exige cuenta (tarjeta magnetica) y acabó null'),\n"
            "                    new OA\\Property(property: 'applicant_person_id', type: 'integer', example: 7, description: 'PROHIBIDO (SGP-34): el promovente de la pensión es no modificable — 422 si se envía'),",
        ),
        # income endpoint: description + required + property + call.
        (
            "        description: 'Declara el valor de un concepto de ingreso del expediente (regla de usuario 5) mientras el expediente está en submitted. El par concepto-expediente es único (422 semántico) y el importe es decimal exacto no negativo (RN-005).',",
            "        description: 'Declara el valor de un concepto de ingreso del expediente (regla de usuario 5) mientras el expediente está en submitted. El par concepto-expediente es único (422 semántico), el importe es decimal exacto no negativo (RN-005) y — desde la Task 42 (corrección de usuario) — el porciento a aplicar es OBLIGATORIO: decimal exacto en el rango 0-100 con dos decimales (422 si se omite, fuera de rango o con tercera decimal).',",
        ),
        (
            "                required: ['income_concept_id', 'amount'],\n"
            "                properties: [\n"
            "                    new OA\\Property(property: 'income_concept_id', type: 'integer', example: 3, description: 'Concepto del catálogo de conceptos de ingreso'),\n"
            "                    new OA\\Property(property: 'amount', type: 'string', example: '150.00', description: 'Importe exacto con dos decimales (RN-005)'),\n"
            "                ],",
            "                required: ['income_concept_id', 'amount', 'applied_percent'],\n"
            "                properties: [\n"
            "                    new OA\\Property(property: 'income_concept_id', type: 'integer', example: 3, description: 'Concepto del catálogo de conceptos de ingreso'),\n"
            "                    new OA\\Property(property: 'amount', type: 'string', example: '150.00', description: 'Importe exacto con dos decimales (RN-005)'),\n"
            "                    new OA\\Property(property: 'applied_percent', type: 'string', example: '50.25', description: 'Porciento a aplicar (Task 42, corrección de usuario): OBLIGATORIO — decimal exacto 0-100 con dos decimales'),\n"
            "                ],",
        ),
        (
            "        return $this->addSubrecord(\n"
            "            fn (): ?IncomeConceptRecord => $this->cases->addIncomeConceptRecord(\n"
            "                $id,\n"
            "                (int) $validated['income_concept_id'],\n"
            "                (string) $validated['amount'],\n"
            "            ),\n"
            "            $id,\n"
            "        );",
            "        return $this->addSubrecord(\n"
            "            fn (): ?IncomeConceptRecord => $this->cases->addIncomeConceptRecord(\n"
            "                $id,\n"
            "                (int) $validated['income_concept_id'],\n"
            "                (string) $validated['amount'],\n"
            "                (string) $validated['applied_percent'],\n"
            "            ),\n"
            "            $id,\n"
            "        );",
        ),
    ],
)

# ---------------------------------------------------------------------------
# ApiDoc — the spec version freshness signal.
# ---------------------------------------------------------------------------
add(
    "app/OpenApi/ApiDoc.php",
    [
        (
            "    version: '1.3.0',",
            "    version: '1.4.0',",
        ),
    ],
)


def main() -> int:
    for path, edits in EDITS:
        text = path.read_text(encoding="utf-8")
        applied = 0
        for old, new in edits:
            count = text.count(old)
            if count == 1:
                text = text.replace(old, new, 1)
                applied += 1
            elif count == 0 and new in text:
                # Already applied by a previous run of this patch.
                continue
            else:
                raise AssertionError(
                    f"{path.name}: anchor must be unique, found {count}: {old[:90]!r}"
                )
        if applied:
            path.write_text(text, encoding="utf-8")
            print(f"OK  {path.name}: {applied} edits applied")
        else:
            print(f"SKIP {path.name}: already patched")

    # (a) The PaymentType model leaves with its catalog.
    payment_type = ROOT / "app/Modules/Catalogs/Infrastructure/Persistence/Models/PaymentType.php"
    if payment_type.exists():
        payment_type.unlink()
        print("OK  PaymentType.php deleted")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
