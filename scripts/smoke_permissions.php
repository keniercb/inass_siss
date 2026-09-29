<?php

declare(strict_types=1);

/**
 * Smoke HTTP del catálogo de permisos contra la BD principal (sgp):
 * login admin -> GET /api/v1/permissions -> GET /api/v1/permissions/people.view
 * Ejecutar: php scripts/smoke_permissions.php
 */

require __DIR__.'/../backend/vendor/autoload.php';

$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$baseUrl = 'http://127.0.0.1:8000/api/v1';

// Arranca el servidor embebido solo para esta fumiga.
$server = proc_open(
    ['php', '-S', '127.0.0.1:8471', __DIR__.'/../backend/public/index.php'],
    [1 => ['pipe', 'w']],
    $pipes,
);

usleep(400000);

try {
    $login = Http::post('http://127.0.0.1:8471/api/v1/auth/login', [
        'email' => 'admin@sgp.local',
        'password' => 'password',
    ]);

    if (! $login->successful()) {
        echo "LOGIN no exitoso ({$login->status()}): {$login->body()}\n";
        exit(1);
    }

    $token = $login->json('data.token') ?? $login->json('token');

    if (! is_string($token) || $token === '') {
        echo "Token no hallado en la respuesta de login: ".substr($login->body(), 0, 300)."\n";
        exit(1);
    }

    $index = Http::withToken($token)->acceptJson()
        ->get('http://127.0.0.1:8471/api/v1/permissions');

    $data = $index->json('data');

    echo "INDEX /permissions -> {$index->status()} con ".(is_array($data) ? count($data) : 0)." entradas\n";
    echo "  primera: ".json_encode($data[0] ?? null, JSON_UNESCAPED_UNICODE)."\n";

    $peopleView = collect($data ?? [])->firstWhere('name', 'people.view');
    echo "  people.view: ".json_encode($peopleView, JSON_UNESCAPED_UNICODE)."\n";

    $show = Http::withToken($token)->acceptJson()
        ->get('http://127.0.0.1:8471/api/v1/permissions/roles.manage');
    echo "SHOW /permissions/roles.manage -> {$show->status()}: ".substr($show->body(), 0, 400)."\n";

    $notFound = Http::withToken($token)->acceptJson()
        ->get('http://127.0.0.1:8471/api/v1/permissions/cases.approve');
    echo "SHOW desconocido -> {$notFound->status()}\n";

    $forbidden = Http::acceptJson()
        ->get('http://127.0.0.1:8471/api/v1/permissions');
    echo "INDEX anónimo -> {$forbidden->status()} (esperado 401)\n";
} finally {
    proc_terminate($server);
    proc_close($server);
}
