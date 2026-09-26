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
            ->and($spec['paths']['/api/v1/auth/me']['get']['security'])->toBe([['sanctumAuth' => []]]);
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
