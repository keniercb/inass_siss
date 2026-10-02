<?php

declare(strict_types=1);

// SGP API documentation contract (RF-API-001/002): the OpenAPI spec must
// describe every published endpoint and the Bearer security scheme, and the
// Swagger UI must be reachable. This suite fails whenever a new endpoint
// ships without documentation or the docs pipeline breaks.
describe('API documentation (Swagger)', function () {
    it('serves the generated OpenAPI spec', function () {
        $response = $this->getJson('/api/docs');

        $response->assertOk();

        $spec = $response->json();

        expect($spec['info']['title'])->toBe('SGP API')
            ->and($spec['openapi'])->toStartWith('3.')
            ->and($spec['components']['securitySchemes']['sanctumAuth']['type'])->toBe('http')
            ->and($spec['paths'])->toHaveKey('/api/v1/auth/login')
            ->and($spec['paths'])->toHaveKey('/api/v1/auth/me')
            ->and($spec['paths'])->toHaveKey('/api/v1/auth/logout')
            ->and($spec['paths']['/api/v1/auth/me']['get']['security'])->toBe([['sanctumAuth' => []]])
            // Catálogos (Fase 1): generic resource + dedicated resources.
            ->and($spec['paths'])->toHaveKey('/api/v1/catalogs/{type}')
            ->and($spec['paths'])->toHaveKey('/api/v1/catalogs/{type}/{id}')
            ->and($spec['paths'])->toHaveKey('/api/v1/municipalities')
            ->and($spec['paths'])->toHaveKey('/api/v1/municipalities/{id}')
            ->and($spec['paths'])->toHaveKey('/api/v1/agencies')
            ->and($spec['paths'])->toHaveKey('/api/v1/agencies/{id}')
            // Configuración general versionada (Fase 1, RF-CAT-005/RN-007).
            ->and($spec['paths'])->toHaveKey('/api/v1/general-settings')
            ->and($spec['paths'])->toHaveKey('/api/v1/general-settings/current')
            ->and($spec['paths'])->toHaveKey('/api/v1/general-settings/{id}')
            ->and($spec['paths']['/api/v1/catalogs/{type}']['get']['security'])->toBe([['sanctumAuth' => []]])
            // Auditoría (Fase 1 Sprint 3): read-only trail surface.
            ->and($spec['paths'])->toHaveKey('/api/v1/audit-logs')
            ->and($spec['paths'])->toHaveKey('/api/v1/audit-logs/export')
            // Usuarios (Fase 1 Sprint 3, S3.5/RF-SEG-004): link/unlink.
            ->and($spec['paths'])->toHaveKey('/api/v1/users/{id}/person')
            ->and($spec['paths']['/api/v1/users/{id}/person'])->toHaveKeys(['post', 'delete'])
            ->and($spec['paths']['/api/v1/users/{id}/person']['post']['tags'])->toBe(['Usuarios'])
            // Gestión de usuarios (S3.6, RF-SEC-001/RF-AUD-004, ADR-24):
            // directory, lifecycle, unlock and password reset.
            ->and($spec['paths'])->toHaveKey('/api/v1/users')
            ->and($spec['paths'])->toHaveKey('/api/v1/users/{id}')
            ->and($spec['paths'])->toHaveKey('/api/v1/users/{id}/restore')
            ->and($spec['paths'])->toHaveKey('/api/v1/users/{id}/unlock')
            ->and($spec['paths'])->toHaveKey('/api/v1/users/{id}/password')
            ->and($spec['paths'])->toHaveKey('/api/v1/auth/password')
            ->and($spec['paths']['/api/v1/users'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/users/{id}'])->toHaveKeys(['get', 'patch', 'delete'])
            ->and($spec['paths']['/api/v1/users']['get']['tags'])->toBe(['Usuarios'])
            ->and($spec['paths']['/api/v1/users/{id}/restore']['post']['tags'])->toBe(['Usuarios'])
            ->and($spec['paths']['/api/v1/users/{id}/unlock']['post']['tags'])->toBe(['Usuarios'])
            ->and($spec['paths']['/api/v1/users/{id}/password']['patch']['tags'])->toBe(['Usuarios'])
            ->and($spec['paths']['/api/v1/auth/password']['post']['tags'])->toBe(['Auth'])
            ->and($spec['paths']['/api/v1/users']['get']['security'])->toBe([['sanctumAuth' => []]])
            // Personas (Fase 1 Sprint 3, RF-PER-*): CRUD + search + death.
            ->and($spec['paths'])->toHaveKey('/api/v1/people')
            ->and($spec['paths'])->toHaveKey('/api/v1/people/{id}')
            ->and($spec['paths'])->toHaveKey('/api/v1/people/{id}/death')
            ->and($spec['paths']['/api/v1/catalogs/{type}'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/catalogs/{type}/{id}'])->toHaveKeys(['get', 'patch', 'delete'])
            ->and($spec['paths']['/api/v1/municipalities']['get']['tags'])->toBe(['Catalogs'])
            ->and($spec['paths']['/api/v1/general-settings'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/general-settings/{id}'])->toHaveKeys(['get', 'delete'])
            ->and($spec['paths']['/api/v1/general-settings/current']['get']['tags'])->toBe(['Settings'])
            ->and($spec['paths']['/api/v1/general-settings/current']['get']['security'])->toBe([['sanctumAuth' => []]])
            ->and($spec['paths']['/api/v1/people'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/people/{id}'])->toHaveKeys(['get', 'patch', 'delete'])
            ->and($spec['paths']['/api/v1/people/{id}/death'])->toHaveKeys(['post'])
            ->and($spec['paths']['/api/v1/people']['get']['tags'])->toBe(['Personas'])
            ->and($spec['paths']['/api/v1/people/{id}/death']['post']['tags'])->toBe(['Personas'])
            ->and($spec['paths']['/api/v1/people']['get']['security'])->toBe([['sanctumAuth' => []]])
            // Estructura organizacional (Fase 2 Sprint 4, RF-ENT-001..005).
            ->and($spec['paths'])->toHaveKey('/api/v1/entities')
            ->and($spec['paths'])->toHaveKey('/api/v1/entities/{id}')
            ->and($spec['paths'])->toHaveKey('/api/v1/entities/tree')
            ->and($spec['paths'])->toHaveKey('/api/v1/offices')
            ->and($spec['paths'])->toHaveKey('/api/v1/offices/{id}')
            ->and($spec['paths'])->toHaveKey('/api/v1/offices/tree')
            ->and($spec['paths'])->toHaveKey('/api/v1/authorized-signatures')
            ->and($spec['paths'])->toHaveKey('/api/v1/authorized-signatures/{id}')
            ->and($spec['paths']['/api/v1/entities'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/entities/{id}'])->toHaveKeys(['get', 'patch', 'delete'])
            ->and($spec['paths']['/api/v1/entities/tree']['get']['tags'])->toBe(['Estructura'])
            ->and($spec['paths']['/api/v1/offices/tree']['get']['tags'])->toBe(['Estructura'])
            ->and($spec['paths']['/api/v1/authorized-signatures']['get']['tags'])->toBe(['Estructura'])
            ->and($spec['paths']['/api/v1/authorized-signatures/{id}'])->toHaveKeys(['get', 'patch', 'delete'])
            // Conteo de expedientes por oficina (RF-ENT-005 segunda
            // parte, ADR-28): the Office schema documents both counts.
            ->and($spec['components']['schemas']['Office']['properties'])->toHaveKey('cases_count')
            ->and($spec['components']['schemas']['Office']['properties'])->toHaveKey('scope_cases_count')
            ->and($spec['components']['schemas']['Office']['properties']['scope_cases_count']['type'])->toBe('integer')
            // Base legal (Fase 2 Sprint 4, RF-LEG-002..004).
            ->and($spec['paths'])->toHaveKey('/api/v1/legal-bases')
            ->and($spec['paths'])->toHaveKey('/api/v1/legal-bases/{id}')
            ->and($spec['paths']['/api/v1/legal-bases'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/legal-bases/{id}'])->toHaveKeys(['get', 'patch', 'delete'])
            ->and($spec['paths']['/api/v1/legal-bases']['get']['tags'])->toBe(['Base legal'])
            // Expedientes (Fase 3 Sprint 5, RF-EXP-001..004):
            // aggregate + subrecord subresources.
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}/salary-records')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}/salary-records/{record}')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}/service-records')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}/service-records/{record}')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}/work-cycles')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}/work-cycles/{record}')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}/income-concept-records')
            ->and($spec['paths'])->toHaveKey('/api/v1/pension-cases/{id}/income-concept-records/{record}')
            ->and($spec['paths']['/api/v1/pension-cases'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}'])->toHaveKeys(['get'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/salary-records'])->toHaveKeys(['post'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/salary-records/{record}'])->toHaveKeys(['delete'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/service-records/{record}'])->toHaveKeys(['delete'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/work-cycles'])->toHaveKeys(['post'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/work-cycles/{record}'])->toHaveKeys(['delete'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/income-concept-records'])->toHaveKeys(['post'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/income-concept-records/{record}'])->toHaveKeys(['delete'])
            ->and($spec['paths']['/api/v1/pension-cases']['get']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases']['post']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}']['get']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/salary-records']['post']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/work-cycles/{record}']['delete']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/income-concept-records']['post']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}/income-concept-records/{record}']['delete']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases']['get']['security'])->toBe([['sanctumAuth' => []]])
            // Roles (RF-SEG-002, ADR-26): custom role management with
            // the institutional matrix immutable.
            ->and($spec['paths'])->toHaveKey('/api/v1/roles')
            ->and($spec['paths'])->toHaveKey('/api/v1/roles/{id}')
            ->and($spec['paths']['/api/v1/roles'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/roles/{id}'])->toHaveKeys(['get', 'patch', 'delete'])
            ->and($spec['paths']['/api/v1/roles']['get']['tags'])->toBe(['Roles'])
            ->and($spec['paths']['/api/v1/roles/{id}']['patch']['tags'])->toBe(['Roles'])
            ->and($spec['paths']['/api/v1/roles']['get']['security'])->toBe([['sanctumAuth' => []]])
            ->and($spec['components']['schemas']['Role']['properties'])->toHaveKey('name')
            ->and($spec['components']['schemas']['Role']['properties'])->toHaveKey('is_system')
            ->and($spec['components']['schemas']['Role']['properties'])->toHaveKey('permissions')
            ->and($spec['components']['schemas']['Role']['properties'])->toHaveKey('users_count')
            // Catálogo de permisos (RF-SEG-002, ADR-27): the read-only
            // surface the role editor consumes.
            ->and($spec['paths'])->toHaveKey('/api/v1/permissions')
            ->and($spec['paths'])->toHaveKey('/api/v1/permissions/{permission}')
            ->and($spec['paths']['/api/v1/permissions'])->toHaveKeys(['get'])
            ->and($spec['paths']['/api/v1/permissions/{permission}'])->toHaveKeys(['get'])
            ->and($spec['paths']['/api/v1/permissions']['get']['tags'])->toBe(['Roles'])
            ->and($spec['paths']['/api/v1/permissions/{permission}']['get']['tags'])->toBe(['Roles'])
            ->and($spec['paths']['/api/v1/permissions']['get']['security'])->toBe([['sanctumAuth' => []]])
            ->and($spec['components']['schemas']['Permission']['properties'])->toHaveKey('name')
            ->and($spec['components']['schemas']['Permission']['properties'])->toHaveKey('module')
            ->and($spec['components']['schemas']['Permission']['properties'])->toHaveKey('action')
            ->and($spec['components']['schemas']['Permission']['properties'])->toHaveKey('institutional_roles')
            ->and($spec['components']['schemas']['Permission']['properties'])->toHaveKey('custom_roles')
            ->and($spec['components']['schemas']['Permission']['properties'])->toHaveKey('users_count')
            // El resumen de persona vinculada (S3.5) cuelga del schema User.
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('person')
            // Oficina de pertenencia del usuario (ADR-29): visible en
            // /auth/me y en la superficie de gestión de cuentas.
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('office')
            ->and($spec['components']['schemas']['User']['properties']['office']['nullable'])->toBe(true)
            // Estado de seguridad derivado de la sesión (S3.6, ADR-24).
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('status')
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('locked')
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('locked_until')
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('failed_login_attempts')
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('password_changed_at')
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('password_expired')
            ->and($spec['components']['schemas']['User']['properties']['locked']['type'])->toBe('boolean')
            ->and($spec['components']['schemas']['User']['properties']['password_expired']['type'])->toBe('boolean')
            ->and($spec['components']['schemas']['LinkedPerson']['properties']['full_name']['type'])->toBe('string')
            ->and($spec['components']['schemas']['LinkedPerson']['properties']['deceased']['type'])->toBe('boolean')
            // Estructura organizacional (Sprint 4): entity, office and
            // signature projections.
            ->and($spec['components']['schemas']['Entity']['properties']['code']['type'])->toBe('string')
            ->and($spec['components']['schemas']['Entity']['properties']['tax_id_number']['type'])->toBe('string')
            ->and($spec['components']['schemas']['Entity']['properties']['social_purpose']['type'])->toBe('string')
            ->and($spec['components']['schemas']['Office']['properties']['address']['type'])->toBe('string')
            ->and($spec['components']['schemas']['AuthorizedSignature']['properties']['status']['type'])->toBe('string')
            ->and($spec['components']['schemas']['AuthorizedSignature']['properties']['valid_from']['type'])->toBe('string')
            // Base legal (Sprint 4): legal basis projection.
            ->and($spec['components']['schemas']['LegalBasis']['properties']['number']['type'])->toBe('string')
            ->and($spec['components']['schemas']['LegalBasis']['properties']['year']['type'])->toBe('integer')
            ->and($spec['components']['schemas']['LegalBasis']['properties']['status']['type'])->toBe('string')
            ->and($spec['components']['schemas']['LegalBasis']['properties']['derogation_date']['type'])->toBe('string');
    });

    it('documents the catalog schemas and shared error components', function () {
        $spec = $this->getJson('/api/docs')->json();

        expect($spec['components']['schemas']['CatalogItem']['properties']['name']['type'])->toBe('string')
            ->and($spec['components']['schemas']['Municipality']['properties']['code']['type'])->toBe('string')
            ->and($spec['components']['schemas']['Agency']['properties']['municipality']['type'])->toBe('object')
            ->and($spec['components']['schemas']['GeneralSettingVersion']['properties']['min_work_years']['type'])->toBe('integer')
            ->and($spec['components']['schemas']['GeneralSettingVersion']['properties']['effective_from']['type'])->toBe('string')
            ->and($spec['components']['schemas']['GeneralSettingVersion']['properties']['effective_to']['type'])->toBe('string')
            ->and($spec['components']['responses']['Unauthorized']['description'])->toBeString()
            ->and($spec['components']['responses']['ValidationError']['description'])->toBeString()
            ->and($spec['components']['schemas']['User']['properties']['email']['type'])->toBe('string')
            ->and($spec['components']['schemas']['Person']['properties']['identity_number']['type'])->toBe('string')
            ->and($spec['components']['schemas']['Person']['properties']['birth_date']['type'])->toBe('string')
            ->and($spec['components']['schemas']['Person']['properties']['deceased']['type'])->toBe('boolean')
            ->and($spec['components']['schemas']['Person']['properties']['father_name']['type'])->toBe('string');
    });

    // Task 39 (user correction, SGP-33): the catalog INPUT schema must
    // recognize the fields incorporated by the Task 38 correction — the
    // régimen de jubilación sector (integer, optional) and the tipo de
    // pensión persona fallecida flag (deceased_person, boolean that
    // defaults to false when omitted). The served spec is regenerated on
    // every docs request (L5_SWAGGER_GENERATE_ALWAYS), so these anchors
    // keep the Schema de Entrada from drifting away from the wire the
    // FormRequests actually validate.
    it('documents the catalog input schema with the incorporated fields', function () {
        $spec = $this->getJson('/api/docs')->json();

        $store = $spec['paths']['/api/v1/catalogs/{type}']['post']['requestBody']['content']['application/json']['schema'];
        $update = $spec['paths']['/api/v1/catalogs/{type}/{id}']['patch']['requestBody']['content']['application/json']['schema'];

        // The spec version is the freshness signal of the served schema:
        // 1.2.0 is the revision that documents the case lifecycle
        // endpoints (PUT + DELETE, SGP-34) on top of the 1.1.0 catalog
        // input schema.
        expect($spec['info']['version'])->toBe('1.2.0')

            // Schema de Entrada del alta: the sector travels as an optional
            // integer and the persona fallecida flag as a boolean whose
            // omission the API resolves to the documented default false.
            ->and($store['required'])->toBe(['name'])
            ->and($store['properties'])->toHaveKey('sector')
            ->and($store['properties']['sector']['type'])->toBe('integer')
            ->and($store['properties']['sector']['nullable'])->toBe(true)
            ->and($store['properties'])->toHaveKey('deceased_person')
            ->and($store['properties']['deceased_person']['type'])->toBe('boolean')
            ->and($store['properties']['deceased_person']['default'])->toBe(false)

            // Schema de Entrada de la edición: both fields ride the PATCH
            // semantics (optional, sector null uproots the value).
            ->and($update['properties'])->toHaveKey('sector')
            ->and($update['properties']['sector']['type'])->toBe('integer')
            ->and($update['properties']['sector']['nullable'])->toBe(true)
            ->and($update['properties'])->toHaveKey('deceased_person')
            ->and($update['properties']['deceased_person']['type'])->toBe('boolean')

            // Output mirror: CatalogItem answers with both fields so the
            // listing, detail and write projections stay symmetric.
            ->and($spec['components']['schemas']['CatalogItem']['properties'])->toHaveKey('sector')
            ->and($spec['components']['schemas']['CatalogItem']['properties']['sector']['type'])->toBe('integer')
            ->and($spec['components']['schemas']['CatalogItem']['properties'])->toHaveKey('deceased_person')
            ->and($spec['components']['schemas']['CatalogItem']['properties']['deceased_person']['type'])->toBe('boolean');
    });

    // Task 40 (user correction, SGP-34): the case aggregate gains its
    // lifecycle writes — PUT /pension-cases/{id} (case edition with an
    // immutable promovente) and DELETE /pension-cases/{id} (soft delete
    // of a submitted case). The path anchors keep the surface from
    // shipping undocumented, mirroring every other endpoint group.
    it('documents the case lifecycle endpoints', function () {
        $spec = $this->getJson('/api/docs')->json();

        expect($spec['paths']['/api/v1/pension-cases/{id}'])->toHaveKeys(['get', 'put', 'delete'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}']['put']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}']['delete']['tags'])->toBe(['Expedientes'])
            ->and($spec['paths']['/api/v1/pension-cases/{id}']['put']['security'])->toBe([['sanctumAuth' => []]])
            ->and($spec['paths']['/api/v1/pension-cases/{id}']['delete']['security'])->toBe([['sanctumAuth' => []]]);

        // Schema de Entrada del PUT: the editable case fields travel
        // optional (PATCH semantics) while the PROMOVENTE fields are
        // documented as prohibited — the wire never lets them drift.
        $put = $spec['paths']['/api/v1/pension-cases/{id}']['put']['requestBody']['content']['application/json']['schema'];

        expect($put['properties'])->toHaveKey('last_salary')
            ->and($put['properties'])->toHaveKey('employer_entity_id')
            ->and($put['properties'])->toHaveKey('requested_at')
            ->and($put['properties'])->toHaveKey('applicant_person_id')
            ->and($put['properties']['applicant_person_id']['description'])->toContain('no modificable')
            ->and($put['properties'])->toHaveKey('phone')
            ->and($put['properties'])->toHaveKey('internationalist')
            ->and($put['properties'])->toHaveKey('termination_date');
    });

    it('documents the response envelope and error shapes', function () {
        $spec = $this->getJson('/api/docs')->json();

        $login = $spec['paths']['/api/v1/auth/login']['post'];

        expect($login['responses'])->toHaveKey('200')
            ->and($login['responses'])->toHaveKey('401')
            ->and($login['responses'])->toHaveKey('422')
            ->and($spec['components']['schemas']['User']['properties']['email']['type'])->toBe('string');
    });

    it('groups every endpoint under the tags declared in ApiDoc', function () {
        $spec = $this->getJson('/api/docs')->json();

        // RF-API-001: the catalog restore endpoint once declared the
        // unregistered tag "Catálogos", so Swagger UI rendered a second,
        // description-less group next to the real Catalogs group.
        expect($spec['paths']['/api/v1/catalogs/{type}/{id}/restore']['post']['tags'])->toBe(['Catalogs']);

        $declaredTags = collect($spec['tags'])->pluck('name')->all();
        $usedTags = [];
        foreach ($spec['paths'] as $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (is_string($method) && in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) && is_array($operation)) {
                    $operationTags = is_array($operation['tags'] ?? null) ? $operation['tags'] : [];
                    foreach ($operationTags as $tag) {
                        if (is_string($tag)) {
                            $usedTags[] = $tag;
                        }
                    }
                }
            }
        }
        $undeclaredTags = array_values(array_diff(array_unique($usedTags), $declaredTags));

        // Phantom tags spawn description-less groups in Swagger UI; every
        // operation must reference a tag registered in ApiDoc.
        expect($declaredTags)->toBe(['Auth', 'Catalogs', 'Settings', 'Auditoría', 'Personas', 'Usuarios', 'Roles', 'Estructura', 'Base legal', 'Expedientes'])
            ->and($undeclaredTags)->toBe([]);
    });

    it('serves the Swagger UI', function () {
        $this->get('/api/documentation')->assertOk();
    });
});
