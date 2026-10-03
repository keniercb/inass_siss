#!/usr/bin/env python3
"""Task 42 / SGP-36 test-suite patch: realign the existing suites to
the four model adjustments (fixtures gain the residence + collection
group, income rows gain applied_percent, catalog suites drop
payment_types and cover payment_form, ApiDocsTest pins spec 1.4.0).
"""

from pathlib import Path

ROOT = Path("/home/z/my-project/backend")

EDITS: list[tuple[Path, list[tuple[str, str]]]] = []


def add(path: str, edits: list[tuple[str, str]]) -> None:
    EDITS.append((ROOT / path, edits))


# The shared Task 42 fixture block appended to every seedCase-style
# setUp that already creates a municipality (uses the local vars).
AGENCY_FIXTURES = (
    "\n"
    "        // Task 42 (user correction, SGP-36): the collection point of\n"
    "        // the promovente — the electronic payroll form keeps the bank\n"
    "        // account OPTIONAL, so the fixtures stay focused on their own\n"
    "        // surface (the conditional demand has its own suite).\n"
    "        $collectionAgencyType = AgencyType::query()->create([\n"
    "            'code' => 'NE',\n"
    "            'name' => 'Agencia de nómina',\n"
    "            'payment_form' => PaymentForm::NominaElectronica->value,\n"
    "        ]);\n"
    "        $collectionAgency = Agency::query()->create([\n"
    "            'code' => 'BPA-NE-1',\n"
    "            'name' => 'Agencia BPA nómina',\n"
    "            'province_id' => $province->id,\n"
    "            'municipality_id' => $municipality->id,\n"
    "            'agency_type_id' => $collectionAgencyType->id,\n"
    "        ]);\n"
)

# The six case-row columns appended inside PensionCase::create([...]).
CASE_COLUMNS = (
    "            // Task 42: promovente residence + collection group.\n"
    "            'current_address' => 'Calle 8 #10 entre 5 y 7, Playa',\n"
    "            'residence_province_id' => $province->id,\n"
    "            'residence_municipality_id' => $municipality->id,\n"
    "            'collection_agency_type_id' => $collectionAgencyType->id,\n"
    "            'collection_agency_id' => $collectionAgency->id,\n"
)

IMPORTS_ANCHOR = (
    "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\EducationalLevel;\n"
)
IMPORTS_NEW = (
    "use App\\Modules\\Catalogs\\Domain\\PaymentForm;\n"
    "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\Agency;\n"
    "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\AgencyType;\n"
    "use App\\Modules\\Catalogs\\Infrastructure\\Persistence\\Models\\EducationalLevel;\n"
)

# ---------------------------------------------------------------------------
# PensionCaseCreationApiTest
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Tests/Feature/PensionCaseCreationApiTest.php",
    [
        (IMPORTS_ANCHOR, IMPORTS_NEW),
        (
            "        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);\n"
            "        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);\n",
            "        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);\n"
            "        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);\n"
            "\n"
            "        // Task 42: the collection point of the promovente (the\n"
            "        // electronic payroll form keeps the bank account optional\n"
            "        // for the classic creation assertions; the conditional demand\n"
            "        // lives in its own suite).\n"
            "        $this->collectionAgencyType = AgencyType::query()->create([\n"
            "            'code' => 'NE',\n"
            "            'name' => 'Agencia de nómina',\n"
            "            'payment_form' => PaymentForm::NominaElectronica->value,\n"
            "        ]);\n"
            "        $this->collectionAgency = Agency::query()->create([\n"
            "            'code' => 'BPA-NE-1',\n"
            "            'name' => 'Agencia BPA nómina',\n"
            "            'province_id' => $this->province->id,\n"
            "            'municipality_id' => $municipality->id,\n"
            "            'agency_type_id' => $this->collectionAgencyType->id,\n"
            "        ]);\n"
            "        $this->residenceMunicipalityId = $municipality->id;\n",
        ),
        (
            "    private int $otherIncomeConceptId;\n",
            "    private int $otherIncomeConceptId;\n"
            "\n"
            "    private AgencyType $collectionAgencyType;\n"
            "\n"
            "    private Agency $collectionAgency;\n"
            "\n"
            "    private int $residenceMunicipalityId;\n",
        ),
        (
            "            'internationalist' => false,\n"
            "            'last_salary' => '5000.00',\n"
            "        ], $overrides);",
            "            'internationalist' => false,\n"
            "            'last_salary' => '5000.00',\n"
            "            // Task 42: promovente residence + collection group —\n"
            "            // the payroll form leaves the bank account optional.\n"
            "            'current_address' => 'Calle 8 #10 entre 5 y 7, Playa',\n"
            "            'residence_province_id' => $this->province->id,\n"
            "            'residence_municipality_id' => $this->residenceMunicipalityId,\n"
            "            'collection_agency_type_id' => $this->collectionAgencyType->id,\n"
            "            'collection_agency_id' => $this->collectionAgency->id,\n"
            "        ], $overrides);",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCaseLifecycleApiTest
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Tests/Feature/PensionCaseLifecycleApiTest.php",
    [
        (IMPORTS_ANCHOR, IMPORTS_NEW),
        (
            "        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);\n"
            "        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);\n"
            "\n"
            "        $applicant = Person::factory()->create([\n",
            "        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);\n"
            "        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);\n"
            + AGENCY_FIXTURES
            + "\n"
            "        $applicant = Person::factory()->create([\n",
        ),
        (
            "            'filed_by_person_id' => Person::factory()->create()->id,\n"
            "            'last_salary' => '5000.00',\n"
            "        ]);",
            "            'filed_by_person_id' => Person::factory()->create()->id,\n"
            "            'last_salary' => '5000.00',\n"
            + CASE_COLUMNS
            + "        ]);",
        ),
        (
            "            'internationalist' => false,\n"
            "            'last_salary' => '5000.00',\n"
            "        ];",
            "            'internationalist' => false,\n"
            "            'last_salary' => '5000.00',\n"
            "            // Task 42: the residence + collection group rides the\n"
            "            // re-capture payload (the promovente keeps every id the\n"
            "            // seedCase fixture created).\n"
            "            'current_address' => $case->current_address,\n"
            "            'residence_province_id' => $case->residence_province_id,\n"
            "            'residence_municipality_id' => $case->residence_municipality_id,\n"
            "            'collection_agency_type_id' => $case->collection_agency_type_id,\n"
            "            'collection_agency_id' => $case->collection_agency_id,\n"
            "        ];",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCaseListApiTest
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Tests/Feature/PensionCaseListApiTest.php",
    [
        (IMPORTS_ANCHOR, IMPORTS_NEW),
        (
            "        $this->catalog = [\n"
            "            'employer_entity_id' => $entity->id,\n",
            "        // Task 42: the collection point of the promovente (the\n"
            "        // electronic payroll form keeps the bank account optional).\n"
            "        $collectionAgencyType = AgencyType::query()->create([\n"
            "            'code' => 'NE',\n"
            "            'name' => 'Agencia de nómina',\n"
            "            'payment_form' => PaymentForm::NominaElectronica->value,\n"
            "        ]);\n"
            "        $collectionAgency = Agency::query()->create([\n"
            "            'code' => 'BPA-NE-1',\n"
            "            'name' => 'Agencia BPA nómina',\n"
            "            'province_id' => $north->id,\n"
            "            'municipality_id' => $northMunicipality->id,\n"
            "            'agency_type_id' => $collectionAgencyType->id,\n"
            "        ]);\n"
            "\n"
            "        $this->catalog = [\n"
            "            'employer_entity_id' => $entity->id,\n",
        ),
        (
            "                'months_per_year' => 12,\n"
            "            ])->id,\n"
            "        ];",
            "                'months_per_year' => 12,\n"
            "            ])->id,\n"
            "            // Task 42: the promovente residence + collection group\n"
            "            // shared by both territorial fixtures.\n"
            "            'current_address' => 'Calle 8 #10, Playa',\n"
            "            'residence_province_id' => $north->id,\n"
            "            'residence_municipality_id' => $northMunicipality->id,\n"
            "            'collection_agency_type_id' => $collectionAgencyType->id,\n"
            "            'collection_agency_id' => $collectionAgency->id,\n"
            "        ];",
        ),
    ],
)

# ---------------------------------------------------------------------------
# RbacPensionCasesApiTest
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Tests/Feature/RbacPensionCasesApiTest.php",
    [
        (IMPORTS_ANCHOR, IMPORTS_NEW),
        (
            "        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);\n"
            "        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);\n"
            "\n"
            "        $applicant = Person::factory()->create([\n",
            "        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);\n"
            "        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);\n"
            + AGENCY_FIXTURES
            + "\n"
            "        $applicant = Person::factory()->create([\n",
        ),
        (
            "            'rebel_army_member' => false,\n"
            "            'last_salary' => '5000.00',\n"
            "        ]);\n"
            "    }",
            "            'rebel_army_member' => false,\n"
            "            'last_salary' => '5000.00',\n"
            + CASE_COLUMNS
            + "        ]);\n"
            "    }",
        ),
        (
            "            'internationalist' => false,\n"
            "            'last_salary' => '5000.00',\n"
            "        ];",
            "            'internationalist' => false,\n"
            "            'last_salary' => '5000.00',\n"
            "            // Task 42: the residence + collection group of the\n"
            "            // fixture case rides the writer payload too.\n"
            "            'current_address' => $case->current_address,\n"
            "            'residence_province_id' => $case->residence_province_id,\n"
            "            'residence_municipality_id' => $case->residence_municipality_id,\n"
            "            'collection_agency_type_id' => $case->collection_agency_type_id,\n"
            "            'collection_agency_id' => $case->collection_agency_id,\n"
            "        ];",
        ),
    ],
)

# ---------------------------------------------------------------------------
# PensionCaseSubrecordsApiTest
# ---------------------------------------------------------------------------
add(
    "app/Modules/PensionCases/Tests/Feature/PensionCaseSubrecordsApiTest.php",
    [
        (IMPORTS_ANCHOR, IMPORTS_NEW),
        (
            "        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);\n"
            "        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);\n"
            "\n"
            "        $applicant = Person::factory()->create([\n",
            "        $organization = Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);\n"
            "        $entityType = EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);\n"
            + AGENCY_FIXTURES
            + "\n"
            "        $applicant = Person::factory()->create([\n",
        ),
        (
            "            'rebel_army_member' => false,\n"
            "            'last_salary' => '5000.00',\n"
            "        ]);",
            "            'rebel_army_member' => false,\n"
            "            'last_salary' => '5000.00',\n"
            + CASE_COLUMNS
            + "        ]);",
        ),
        # Income concept payloads gain the applied percent.
        (
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "        ])\n"
            "            ->assertStatus(201)\n"
            "            ->assertJsonPath('data.income_concept_id', $this->incomeConceptId)\n"
            "            ->assertJsonPath('data.amount', '150.00')\n"
            "            ->assertJsonPath('data.pension_case_id', $this->case->id);",
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "            'applied_percent' => '100',\n"
            "        ])\n"
            "            ->assertStatus(201)\n"
            "            ->assertJsonPath('data.income_concept_id', $this->incomeConceptId)\n"
            "            ->assertJsonPath('data.amount', '150.00')\n"
            "            ->assertJsonPath('data.applied_percent', '100.00')\n"
            "            ->assertJsonPath('data.pension_case_id', $this->case->id);",
        ),
        (
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "        ])->assertStatus(201);",
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "            'applied_percent' => '100',\n"
            "        ])->assertStatus(201);",
        ),
        (
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '200.00',\n"
            "        ])",
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '200.00',\n"
            "            'applied_percent' => '100',\n"
            "        ])",
        ),
        (
            "            'income_concept_id' => $this->otherIncomeConceptId,\n"
            "            'amount' => '80.50',\n"
            "        ])->assertStatus(201);",
            "            'income_concept_id' => $this->otherIncomeConceptId,\n"
            "            'amount' => '80.50',\n"
            "            'applied_percent' => '50',\n"
            "        ])->assertStatus(201);",
        ),
        (
            "            'income_concept_id' => 999999,\n"
            "            'amount' => '150.00',\n",
            "            'income_concept_id' => 999999,\n"
            "            'amount' => '150.00',\n"
            "            'applied_percent' => '100',\n",
        ),
        (
            "            'income_concept_id' => $this->otherIncomeConceptId,\n"
            "            'amount' => '80.50',\n"
            "        ])->assertStatus(422)->assertJsonValidationErrors(['income_concept_id']);",
            "            'income_concept_id' => $this->otherIncomeConceptId,\n"
            "            'amount' => '80.50',\n"
            "            'applied_percent' => '50',\n"
            "        ])->assertStatus(422)->assertJsonValidationErrors(['income_concept_id']);",
        ),
        (
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => $amount,\n"
            "        ])",
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => $amount,\n"
            "            'applied_percent' => '100',\n"
            "        ])",
        ),
        (
            "        $id = $this->postJson(\"/api/v1/pension-cases/{$this->case->id}/income-concept-records\", [\n"
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "        ])->json('data.id');",
            "        $id = $this->postJson(\"/api/v1/pension-cases/{$this->case->id}/income-concept-records\", [\n"
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "            'applied_percent' => '100',\n"
            "        ])->json('data.id');",
        ),
        # New: the applied percent demands (Task 42).
        (
            "    public function test_removes_an_income_concept_record(): void\n"
            "    {",
            "    /**\n"
            "     * Task 42 (user correction, SGP-36): the percent to apply is\n"
            "     * REQUIRED at the individual endpoint — 422 when omitted, out of\n"
            "     * the 0-100 range or carrying a third decimal.\n"
            "     */\n"
            "    public function test_rejects_an_income_concept_record_without_a_valid_applied_percent(): void\n"
            "    {\n"
            "        $endpoint = \"/api/v1/pension-cases/{$this->case->id}/income-concept-records\";\n"
            "\n"
            "        $this->postJson($endpoint, [\n"
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "        ])->assertStatus(422)->assertJsonValidationErrors(['applied_percent']);\n"
            "\n"
            "        $this->postJson($endpoint, [\n"
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "            'applied_percent' => '100.01',\n"
            "        ])->assertStatus(422)->assertJsonValidationErrors(['applied_percent']);\n"
            "\n"
            "        $this->postJson($endpoint, [\n"
            "            'income_concept_id' => $this->incomeConceptId,\n"
            "            'amount' => '150.00',\n"
            "            'applied_percent' => '25.505',\n"
            "        ])->assertStatus(422)->assertJsonValidationErrors(['applied_percent']);\n"
            "    }\n"
            "\n"
            "    public function test_removes_an_income_concept_record(): void\n"
            "    {",
        ),
    ],
)

# ---------------------------------------------------------------------------
# CatalogRegistryTest
# ---------------------------------------------------------------------------
add(
    "app/Modules/Catalogs/Tests/Unit/CatalogRegistryTest.php",
    [
        (
            "it('exposes the sixteen uniform catalogs of phase 1', function () {\n"
            "    expect(CatalogRegistry::keys())->toBe([\n"
            "        'provinces',\n"
            "        'agency-types',\n"
            "        'organizations',\n"
            "        'entity-types',\n"
            "        'office-types',\n"
            "        'legal-basis-types',\n"
            "        'scientific-categories',\n"
            "        'educational-levels',\n"
            "        'occupational-categories',\n"
            "        'pension-types',\n"
            "        'beneficiary-types',\n"
            "        'races',\n"
            "        'positions',\n"
            "        'pension-regimes',\n"
            "        'payment-types',\n"
            "        'income-concepts',\n"
            "    ]);\n"
            "});",
            "it('exposes the fifteen uniform catalogs of phase 1', function () {\n"
            "    expect(CatalogRegistry::keys())->toBe([\n"
            "        'provinces',\n"
            "        'agency-types',\n"
            "        'organizations',\n"
            "        'entity-types',\n"
            "        'office-types',\n"
            "        'legal-basis-types',\n"
            "        'scientific-categories',\n"
            "        'educational-levels',\n"
            "        'occupational-categories',\n"
            "        'pension-types',\n"
            "        'beneficiary-types',\n"
            "        'races',\n"
            "        'positions',\n"
            "        'pension-regimes',\n"
            "        'income-concepts',\n"
            "    ]);\n"
            "});",
        ),
        (
            "})->with([\n"
            "    'municipalities',\n"
            "    'agencies',\n"
            "    'bank-controls',\n"
            "    'unknown-thing',\n"
            "]);",
            "})->with([\n"
            "    'municipalities',\n"
            "    'agencies',\n"
            "    'bank-controls',\n"
            "    'unknown-thing',\n"
            "    // Task 42 (SGP-36): the payment_types catalog was ELIMINATED —\n"
            "    // its key answers like any other unknown catalog now.\n"
            "    'payment-types',\n"
            "]);",
        ),
        (
            "it('observes the eighteen catalog models for authorship stamping', function () {\n"
            "    $models = CatalogRegistry::observedModels();\n"
            "\n"
            "    expect($models)->toHaveLength(18)\n"
            "        ->and($models)->toContain(Agency::class)\n"
            "        ->and($models)->not->toContain(CatalogModel::class)\n"
            "        ->and(array_unique($models))->toHaveLength(18);\n"
            "});",
            "it('observes the seventeen catalog models for authorship stamping', function () {\n"
            "    $models = CatalogRegistry::observedModels();\n"
            "\n"
            "    expect($models)->toHaveLength(17)\n"
            "        ->and($models)->toContain(Agency::class)\n"
            "        ->and($models)->not->toContain(CatalogModel::class)\n"
            "        ->and(array_unique($models))->toHaveLength(17);\n"
            "});",
        ),
        (
            "    ['positions', true],\n"
            "    ['payment-types', true],\n"
            "]);",
            "    ['positions', true],\n"
            "]);",
        ),
        (
            "it('declares the reference guards of the geographic catalogs', function () {\n"
            "    $provinces = CatalogRegistry::definition('provinces');\n"
            "    $agencyTypes = CatalogRegistry::definition('agency-types');\n"
            "\n"
            "    expect($provinces->dependents)->toHaveCount(2)\n"
            "        ->and(array_values($provinces->dependents))->toContain('province_id')\n"
            "        ->and($agencyTypes->dependents)->toHaveCount(1)\n"
            "        ->and(CatalogRegistry::definition('races')->dependents)->toBe([]);\n"
            "});",
            "it('declares the reference guards of the geographic catalogs', function () {\n"
            "    $provinces = CatalogRegistry::definition('provinces');\n"
            "    $agencyTypes = CatalogRegistry::definition('agency-types');\n"
            "\n"
            "    expect($provinces->dependents)->toHaveCount(2)\n"
            "        ->and(array_values($provinces->dependents))->toContain('province_id')\n"
            "        ->and($agencyTypes->dependents)->toHaveCount(1)\n"
            "        ->and(CatalogRegistry::definition('races')->dependents)->toBe([]);\n"
            "});\n"
            "\n"
            "// Task 42 (user correction, SGP-36): the agency types carry the\n"
            "// payment form of the collection — the lowercase-unified enum the\n"
            "// user fixed, riding the generic extraRules machinery.\n"
            "it('declares the payment form rule of the agency types', function () {\n"
            "    expect(CatalogRegistry::definition('agency-types')->extraRules)->toBe([\n"
            "        'payment_form' => 'in:tarjeta magnetica,nomina electronica',\n"
            "    ]);\n"
            "});",
        ),
    ],
)

# ---------------------------------------------------------------------------
# CatalogCrudTest
# ---------------------------------------------------------------------------
add(
    "app/Modules/Catalogs/Tests/Feature/CatalogCrudTest.php",
    [
        (
            "            'agency-types' => ['code' => 'OTR', 'name' => 'Otro tipo'],\n",
            "            'agency-types' => ['code' => 'OTR', 'name' => 'Otro tipo', 'payment_form' => 'nomina electronica'],\n",
        ),
        (
            "            'payment-types' => ['code' => 'TARJ', 'name' => 'Tarjeta', 'description' => 'Pago con tarjeta'],\n",
            "",
        ),
        (
            "        foreach (['races', 'educational-levels', 'payment-types'] as $type) {",
            "        foreach (['races', 'educational-levels', 'positions'] as $type) {",
        ),
        (
            "    public function test_search_matches_codes_too(): void\n"
            "    {\n"
            "        $this->postJson('/api/v1/catalogs/pension-types', ['code' => 'SOB', 'name' => 'Por sobrevivencia'])\n"
            "            ->assertCreated();\n"
            "\n"
            "        $this->getJson('/api/v1/catalogs/pension-types?search=SOB')\n"
            "            ->assertOk()\n"
            "            ->assertJsonCount(1, 'data');\n"
            "    }\n"
            "}",
            "    public function test_search_matches_codes_too(): void\n"
            "    {\n"
            "        $this->postJson('/api/v1/catalogs/pension-types', ['code' => 'SOB', 'name' => 'Por sobrevivencia'])\n"
            "            ->assertCreated();\n"
            "\n"
            "        $this->getJson('/api/v1/catalogs/pension-types?search=SOB')\n"
            "            ->assertOk()\n"
            "            ->assertJsonCount(1, 'data');\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * Task 42 (user correction, SGP-36): the payment_types catalog was\n"
            "     * ELIMINATED — its key answers 404 like any other unknown catalog\n"
            "     * (the payment form of the collection lives in the agency type).\n"
            "     */\n"
            "    public function test_payment_types_is_no_longer_a_catalog(): void\n"
            "    {\n"
            "        $this->getJson('/api/v1/catalogs/payment-types')->assertStatus(404);\n"
            "        $this->postJson('/api/v1/catalogs/payment-types', ['code' => 'TARJ', 'name' => 'Tarjeta'])\n"
            "            ->assertStatus(404);\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * Task 42: the payment form of the agency types — the omission of\n"
            "     * the store falls to the DEFAULT 'tarjeta magnetica' (the\n"
            "     * deceased_person precedent of Task 38), unknown values answer\n"
            "     * 422 and the PATCH updates it.\n"
            "     */\n"
            "    public function test_the_agency_type_payment_form_falls_to_the_default_when_omitted(): void\n"
            "    {\n"
            "        $this->postJson('/api/v1/catalogs/agency-types', ['code' => 'TM', 'name' => 'Agencia de tarjeta'])\n"
            "            ->assertCreated()\n"
            "            ->assertJsonPath('data.payment_form', 'tarjeta magnetica');\n"
            "\n"
            "        $this->postJson('/api/v1/catalogs/agency-types', [\n"
            "            'code' => 'NE',\n"
            "            'name' => 'Agencia de nómina',\n"
            "            'payment_form' => 'nomina electronica',\n"
            "        ])\n"
            "            ->assertCreated()\n"
            "            ->assertJsonPath('data.payment_form', 'nomina electronica');\n"
            "    }\n"
            "\n"
            "    public function test_the_agency_type_payment_form_rejects_unknown_values(): void\n"
            "    {\n"
            "        $this->postJson('/api/v1/catalogs/agency-types', [\n"
            "            'code' => 'XX',\n"
            "            'name' => 'Desconocida',\n"
            "            'payment_form' => 'Tarjeta Bancaria',\n"
            "        ])->assertStatus(422)->assertJsonValidationErrors(['payment_form']);\n"
            "    }\n"
            "\n"
            "    public function test_the_agency_type_payment_form_is_patchable(): void\n"
            "    {\n"
            "        $id = $this->postJson('/api/v1/catalogs/agency-types', ['code' => 'TM', 'name' => 'Agencia de tarjeta'])\n"
            "            ->assertCreated()\n"
            "            ->json('data.id');\n"
            "\n"
            "        $this->patchJson(\"/api/v1/catalogs/agency-types/{$id}\", ['payment_form' => 'nomina electronica'])\n"
            "            ->assertOk()\n"
            "            ->assertJsonPath('data.payment_form', 'nomina electronica');\n"
            "\n"
            "        // Absent PATCH keys never uproot the stored value.\n"
            "        $this->patchJson(\"/api/v1/catalogs/agency-types/{$id}\", ['name' => 'Agencia de tarjeta y nómina'])\n"
            "            ->assertOk()\n"
            "            ->assertJsonPath('data.payment_form', 'nomina electronica');\n"
            "    }\n"
            "}",
        ),
    ],
)

# ---------------------------------------------------------------------------
# CatalogSeedingTest
# ---------------------------------------------------------------------------
add(
    "app/Modules/Catalogs/Tests/Feature/CatalogSeedingTest.php",
    [
        (
            "            'payment_types' => 3,\n",
            "",
        ),
        (
            "            'payment_types' => ['ABN', 'CHQ', 'EFE'],\n",
            "",
        ),
        (
            "        foreach ($expected as $table => $codes) {\n"
            "            $rows = \\DB::table($table)->orderBy('id')->get();\n"
            "\n"
            "            $this->assertSame(\n"
            "                $codes,\n"
            "                $rows->pluck('code')->all(),\n"
            "                \"Seeded codes mismatch for [{$table}].\"\n"
            "            );\n"
            "        }\n"
            "    }\n"
            "}",
            "        foreach ($expected as $table => $codes) {\n"
            "            $rows = \\DB::table($table)->orderBy('id')->get();\n"
            "\n"
            "            $this->assertSame(\n"
            "                $codes,\n"
            "                $rows->pluck('code')->all(),\n"
            "                \"Seeded codes mismatch for [{$table}].\"\n"
            "            );\n"
            "        }\n"
            "    }\n"
            "\n"
            "    /**\n"
            "     * Task 42 (user correction, SGP-36): the agency types seed their\n"
            "     * payment form explicitly (the column default answers the same\n"
            "     * value — the reference data stays honest about the field).\n"
            "     */\n"
            "    public function test_the_seeded_agency_types_carry_their_payment_form(): void\n"
            "    {\n"
            "        $this->seed(CatalogsSeeder::class);\n"
            "\n"
            "        $this->assertSame(\n"
            "            ['tarjeta magnetica', 'tarjeta magnetica', 'tarjeta magnetica'],\n"
            "            \\DB::table('agency_types')->orderBy('id')->pluck('payment_form')->all()\n"
            "        );\n"
            "    }\n"
            "}",
        ),
    ],
)

# ---------------------------------------------------------------------------
# ApiDocsTest — version 1.4.0 + the Task 42 anchors.
# ---------------------------------------------------------------------------
add(
    "tests/Feature/ApiDocsTest.php",
    [
        (
            "        // The spec version is the freshness signal of the served schema:\n"
            "        // 1.3.0 is the revision that scopes the case listing to the\n"
            "        // authenticated user's office (SGP-35) on top of the 1.2.0\n"
            "        // lifecycle endpoints (PUT + DELETE, SGP-34).\n"
            "        expect($spec['info']['version'])->toBe('1.3.0')",
            "        // The spec version is the freshness signal of the served schema:\n"
            "        // 1.4.0 is the revision that carries the SGP-36 model adjustments\n"
            "        // (payment_types eliminated, the agency-types payment form, the\n"
            "        // case residence + collection group and the income applied\n"
            "        // percent) on top of the 1.3.0 territorial scope.\n"
            "        expect($spec['info']['version'])->toBe('1.4.0')",
        ),
        (
            "            // Output mirror: CatalogItem answers with both fields so the\n"
            "            // listing, detail and write projections stay symmetric.\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties'])->toHaveKey('sector')\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties']['sector']['type'])->toBe('integer')\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties'])->toHaveKey('deceased_person')\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties']['deceased_person']['type'])->toBe('boolean');\n"
            "    });",
            "            // Output mirror: CatalogItem answers with both fields so the\n"
            "            // listing, detail and write projections stay symmetric.\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties'])->toHaveKey('sector')\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties']['sector']['type'])->toBe('integer')\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties'])->toHaveKey('deceased_person')\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties']['deceased_person']['type'])->toBe('boolean')\n"
            "\n"
            "            // Task 42 (SGP-36): the agency-types payment form rides the\n"
            "            // same generic machinery — the store schema documents the\n"
            "            // lowercase-unified enum with its default, the PATCH schema\n"
            "            // the editable form and the CatalogItem mirror the value.\n"
            "            ->and($store['properties'])->toHaveKey('payment_form')\n"
            "            ->and($store['properties']['payment_form']['type'])->toBe('string')\n"
            "            ->and($store['properties']['payment_form']['default'])->toBe('tarjeta magnetica')\n"
            "            ->and($update['properties'])->toHaveKey('payment_form')\n"
            "            ->and($update['properties']['payment_form']['type'])->toBe('string')\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties'])->toHaveKey('payment_form')\n"
            "            ->and($spec['components']['schemas']['CatalogItem']['properties']['payment_form']['type'])->toBe('string')\n"
            "\n"
            "            // Task 42: the payment_types catalog left the generic resource\n"
            "            // — the index description no longer enumerates it (15\n"
            "            // uniform types).\n"
            "            ->and($spec['paths']['/api/v1/catalogs/{type}']['get']['description'])->not->toContain('payment-types');\n"
            "    });",
        ),
        (
            "    it('documents the response envelope and error shapes', function () {",
            "    // Task 42 (user correction, SGP-36): the case carries the promovente\n"
            "    // residence + collection group — the POST demands it, the PUT\n"
            "    // documents it as EDITABLE (the user decided it can be modified)\n"
            "    // and the PensionCase schema mirrors the six fields — while every\n"
            "    // income concept declaration travels with its applied percent\n"
            "    // (0-100, two decimals).\n"
            "    it('documents the promovente residence and collection group with the applied percent', function () {\n"
            "        $spec = $this->getJson('/api/docs')->json();\n"
            "\n"
            "        $store = $spec['paths']['/api/v1/pension-cases']['post']['requestBody']['content']['application/json']['schema'];\n"
            "        $put = $spec['paths']['/api/v1/pension-cases/{id}']['put']['requestBody']['content']['application/json']['schema'];\n"
            "\n"
            "        expect($store['required'])->toContain('current_address')\n"
            "            ->and($store['required'])->toContain('residence_province_id')\n"
            "            ->and($store['required'])->toContain('residence_municipality_id')\n"
            "            ->and($store['required'])->toContain('collection_agency_type_id')\n"
            "            ->and($store['required'])->toContain('collection_agency_id')\n"
            "            ->and($store['required'])->not->toContain('bank_account')\n"
            "            ->and($store['properties'])->toHaveKey('current_address')\n"
            "            ->and($store['properties'])->toHaveKey('residence_province_id')\n"
            "            ->and($store['properties'])->toHaveKey('residence_municipality_id')\n"
            "            ->and($store['properties'])->toHaveKey('collection_agency_type_id')\n"
            "            ->and($store['properties'])->toHaveKey('collection_agency_id')\n"
            "            ->and($store['properties'])->toHaveKey('bank_account')\n"
            "            ->and($store['properties']['bank_account']['nullable'])->toBe(true)\n"
            "            ->and($put['properties'])->toHaveKey('current_address')\n"
            "            ->and($put['properties'])->toHaveKey('bank_account')\n"
            "            ->and($put['properties']['applicant_person_id']['description'])->toContain('no modificable')\n"
            "            ->and($spec['components']['schemas']['PensionCase']['properties'])->toHaveKey('current_address')\n"
            "            ->and($spec['components']['schemas']['PensionCase']['properties'])->toHaveKey('residence_province_id')\n"
            "            ->and($spec['components']['schemas']['PensionCase']['properties'])->toHaveKey('bank_account')\n"
            "            ->and($spec['components']['schemas']['PensionCase']['properties'])->toHaveKey('collection_agency_type')\n"
            "            ->and($spec['components']['schemas']['PensionCase']['properties'])->toHaveKey('collection_agency')\n"
            "\n"
            "            // The applied percent of the income declarations: the\n"
            "            // individual endpoint demands it and the row schema mirrors\n"
            "            // it.\n"
            "            ->and($spec['paths']['/api/v1/pension-cases/{id}/income-concept-records']['post']['requestBody']['content']['application/json']['schema']['required'])->toContain('applied_percent')\n"
            "            ->and($spec['components']['schemas']['IncomeConceptRecord']['properties'])->toHaveKey('applied_percent');\n"
            "    });\n"
            "\n"
            "    it('documents the response envelope and error shapes', function () {",
        ),
    ],
)


def main() -> int:
    for path, edits in EDITS:
        text = path.read_text(encoding="utf-8")
        for old, new in edits:
            count = text.count(old)
            assert count == 1, (
                f"{path.name}: anchor must be unique, found {count}: {old[:90]!r}"
            )
            text = text.replace(old, new, 1)
        path.write_text(text, encoding="utf-8")
        print(f"OK  {path.name}: {len(edits)} edits applied")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
