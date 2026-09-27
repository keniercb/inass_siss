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
            // El resumen de persona vinculada (S3.5) cuelga del schema User.
            ->and($spec['components']['schemas']['User']['properties'])->toHaveKey('person')
            ->and($spec['components']['schemas']['LinkedPerson']['properties']['full_name']['type'])->toBe('string')
            ->and($spec['components']['schemas']['LinkedPerson']['properties']['deceased']['type'])->toBe('boolean');
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
        expect($declaredTags)->toBe(['Auth', 'Catalogs', 'Settings', 'Auditoría', 'Personas', 'Usuarios'])
            ->and($undeclaredTags)->toBe([]);
    });

    it('serves the Swagger UI', function () {
        $this->get('/api/documentation')->assertOk();
    });
});
