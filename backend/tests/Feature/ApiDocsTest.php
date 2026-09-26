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
            ->and($spec['paths']['/api/v1/catalogs/{type}'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/catalogs/{type}/{id}'])->toHaveKeys(['get', 'patch', 'delete'])
            ->and($spec['paths']['/api/v1/municipalities']['get']['tags'])->toBe(['Catalogs'])
            ->and($spec['paths']['/api/v1/general-settings'])->toHaveKeys(['get', 'post'])
            ->and($spec['paths']['/api/v1/general-settings/{id}'])->toHaveKeys(['get', 'delete'])
            ->and($spec['paths']['/api/v1/general-settings/current']['get']['tags'])->toBe(['Settings'])
            ->and($spec['paths']['/api/v1/general-settings/current']['get']['security'])->toBe([['sanctumAuth' => []]]);
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
            ->and($spec['components']['schemas']['User']['properties']['email']['type'])->toBe('string');
    });

    it('documents the response envelope and error shapes', function () {
        $spec = $this->getJson('/api/docs')->json();

        $login = $spec['paths']['/api/v1/auth/login']['post'];

        expect($login['responses'])->toHaveKey('200')
            ->and($login['responses'])->toHaveKey('401')
            ->and($login['responses'])->toHaveKey('422')
            ->and($spec['components']['schemas']['User']['properties']['email']['type'])->toBe('string');
    });

    it('serves the Swagger UI', function () {
        $this->get('/api/documentation')->assertOk();
    });
});
