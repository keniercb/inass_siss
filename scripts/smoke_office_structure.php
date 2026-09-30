<?php

declare(strict_types=1);

/**
 * Smoke HTTP de la estructura territorial de oficinas (ADR-31) contra la
 * BD principal (sgp) ya sembrada: la oficina nacional existe al arranque
 * (seeder, regla 7), no se admite una segunda (regla 1), la provincial
 * es única por provincia (regla 2) con parent forzado a la nacional
 * (regla 5), la municipal es única por provincia y municipio (regla 3)
 * con parent forzado a la provincial de su provincia (regla 4), los
 * prerrequisitos responden 422 (regla 6) y la re-derivación al mover
 * territorio funciona en PATCH.
 *
 * Idempotente: todo lo creado se desactiva al final (los borrados lógicos
 * no ocupan los ámbitos de unicidad).
 * Ejecutar: php scripts/smoke_office_structure.php
 */

require __DIR__.'/../backend/vendor/autoload.php';

$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (! $ok) {
        $failures++;
    }
    echo ($ok ? '  OK  ' : '  FAIL')." {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
}

/** Detail helper: status plus a trimmed response body. */
function detail(object $response): string
{
    $body = (string) $response->body();

    return 'status '.$response->status().': '.mb_substr($body, 0, 220);
}

// Arranca el servidor embebido solo para esta fumiga.
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:8474', __DIR__.'/../backend/public/index.php'],
    [1 => ['file', '/dev/null', 'w']],
    $pipes,
);

usleep(500000);

$created = [];

try {
    $login = Http::timeout(15)->post('http://127.0.0.1:8474/api/v1/auth/login', [
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

    // OJO: Http::get($url, []) con array vacío BORRA la query string
    // (Guzzle reemplaza la query por el segundo argumento), así que
    // los GET llevan la query en la URL y sin segundo argumento.
    $base = function (string $method, string $uri, array $payload = []) use ($token) {
        $verb = strtolower($method);
        $url = "http://127.0.0.1:8474/api/v1{$uri}";

        return $verb === 'get'
            ? Http::timeout(15)->withToken($token)->acceptJson()->get($url)
            : Http::timeout(15)->withToken($token)->acceptJson()->{$verb}($url, $payload);
    };

    // ---- Geografía y tipos desde los catálogos sembrados ----
    $provinces = $base('GET', '/catalogs/provinces?per_page=100')->json('data');
    $byCode = collect($provinces)->keyBy('code');
    $holguin = $byCode['12'] ?? null;
    $santiago = $byCode['14'] ?? null;

    if ($holguin === null || $santiago === null) {
        echo "Provincias no halladas en el catálogo.\n";
        exit(1);
    }

    $municipalities = $base('GET', "/municipalities?province_id={$holguin['id']}&per_page=100")->json('data');
    $holguinMun = $municipalities[0] ?? null;
    $banesMun = $municipalities[1] ?? null;
    $santiagoMun = $base('GET', "/municipalities?province_id={$santiago['id']}&per_page=100")->json('data')[0] ?? null;

    $officeTypes = collect($base('GET', '/catalogs/office-types?per_page=100')->json('data'))->keyBy('code');
    $nac = $officeTypes['NAC'] ?? null;
    $pro = $officeTypes['PRO'] ?? null;
    $mun = $officeTypes['MUN'] ?? null;

    if ($holguinMun === null || $banesMun === null || $santiagoMun === null || $nac === null || $pro === null || $mun === null) {
        echo "Referencias de geografía o tipos incompletas.\n";
        exit(1);
    }

    echo "== Oficina nacional al arranque (regla 7) ==\n";
    $tree = $base('GET', '/offices/tree');
    $roots = $tree->json('data') ?? [];
    check('GET /offices/tree -> 200', $tree->status() === 200, "status {$tree->status()}");
    check('un único nodo raíz (la nacional)', count($roots) === 1, 'roots '.count($roots));
    $nationalId = $roots[0]['id'] ?? null;
    check('el raíz es de tipo NAC y sin parent', ($roots[0]['type']['code'] ?? null) === 'NAC' && ($roots[0]['children'] ?? []) === [], "type ".($roots[0]['type']['code'] ?? '?'));

    echo "== Unicidad de la nacional (regla 1) ==\n";
    $secondNational = $base('POST', '/offices', [
        'office_type_id' => $nac['id'],
        'province_id' => $santiago['id'],
        'municipality_id' => $santiagoMun['id'],
        'address' => 'Segunda sede nacional (fumiga)',
    ]);
    check('POST segunda nacional -> 422', $secondNational->status() === 422, detail($secondNational));

    echo "== Provincial: forzada a la nacional (regla 5) y única por provincia (regla 2) ==\n";
    $provincial = $base('POST', '/offices', [
        'office_type_id' => $pro['id'],
        'province_id' => $holguin['id'],
        'municipality_id' => $holguinMun['id'],
        'address' => 'Provincial Holguín (fumiga)',
    ]);
    check('POST provincial Holguín sin parent -> 201', $provincial->status() === 201, detail($provincial));
    check('parent forzado a la nacional', ($provincial->json('data.parent_office_id') === $nationalId), 'parent '.json_encode($provincial->json('data.parent_office_id')));
    $provincialId = $provincial->json('data.id');
    $created[] = $provincialId;

    $dupProvincial = $base('POST', '/offices', [
        'office_type_id' => $pro['id'],
        'province_id' => $holguin['id'],
        'municipality_id' => $banesMun['id'],
        'address' => 'Otra provincial Holguín (fumiga)',
    ]);
    check('POST segunda provincial de Holguín -> 422', $dupProvincial->status() === 422, detail($dupProvincial));

    $wrongParent = $base('POST', '/offices', [
        'office_type_id' => $pro['id'],
        'province_id' => $santiago['id'],
        'municipality_id' => $santiagoMun['id'],
        'parent_office_id' => $provincialId,
        'address' => 'Provincial Santiago (fumiga)',
    ]);
    check('POST provincial con parent que no es la nacional -> 422', $wrongParent->status() === 422, detail($wrongParent));

    echo "== Municipal: forzada a la provincial de la provincia (regla 4) y única por municipio (regla 3) ==\n";
    $municipalOffice = $base('POST', '/offices', [
        'office_type_id' => $mun['id'],
        'province_id' => $holguin['id'],
        'municipality_id' => $holguinMun['id'],
        'address' => 'Municipal Holguín (fumiga)',
    ]);
    check('POST municipal sin parent -> 201', $municipalOffice->status() === 201, detail($municipalOffice));
    check('parent forzado a la provincial de la provincia', ($municipalOffice->json('data.parent_office_id') === $provincialId), 'parent '.json_encode($municipalOffice->json('data.parent_office_id')));
    $municipalId = $municipalOffice->json('data.id');
    $created[] = $municipalId;

    $dupMunicipal = $base('POST', '/offices', [
        'office_type_id' => $mun['id'],
        'province_id' => $holguin['id'],
        'municipality_id' => $holguinMun['id'],
        'address' => 'Otra municipal Holguín (fumiga)',
    ]);
    check('POST segunda municipal del mismo municipio -> 422', $dupMunicipal->status() === 422, "status {$dupMunicipal->status()}");

    $otherMunicipal = $base('POST', '/offices', [
        'office_type_id' => $mun['id'],
        'province_id' => $holguin['id'],
        'municipality_id' => $banesMun['id'],
        'address' => 'Municipal Banes (fumiga)',
    ]);
    check('POST municipal de otro municipio de la provincia -> 201', $otherMunicipal->status() === 201, "status {$otherMunicipal->status()}");
    $created[] = $otherMunicipal->json('data.id');

    $munWrongParent = $base('POST', '/offices', [
        'office_type_id' => $mun['id'],
        'province_id' => $santiago['id'],
        'municipality_id' => $santiagoMun['id'],
        'parent_office_id' => $nationalId,
        'address' => 'Municipal Santiago (fumiga)',
    ]);
    check('POST municipal con parent que no es la provincial -> 422', $munWrongParent->status() === 422, "status {$munWrongParent->status()}");

    echo "== Prerrequisitos (regla 6): municipal sin provincial de su provincia ==\n";
    $noProvincial = $base('POST', '/offices', [
        'office_type_id' => $mun['id'],
        'province_id' => $santiago['id'],
        'municipality_id' => $santiagoMun['id'],
        'address' => 'Municipal Santiago sin provincial (fumiga)',
    ]);
    check('POST municipal de Santiago sin provincial previa -> 422', $noProvincial->status() === 422, detail($noProvincial));

    echo "== PATCH: contradicciones y re-derivación ==\n";
    $unroot = $base('PATCH', "/offices/{$provincialId}", ['parent_office_id' => null]);
    check('PATCH provincial con parent null -> 422', $unroot->status() === 422, detail($unroot));

    $rootParent = $base('PATCH', "/offices/{$nationalId}", ['parent_office_id' => $provincialId]);
    check('PATCH nacional con parent -> 422', $rootParent->status() === 422, "status {$rootParent->status()}");

    $santiagoProvincial = $base('POST', '/offices', [
        'office_type_id' => $pro['id'],
        'province_id' => $santiago['id'],
        'municipality_id' => $santiagoMun['id'],
        'address' => 'Provincial Santiago (fumiga)',
    ]);
    check('POST provincial Santiago -> 201', $santiagoProvincial->status() === 201, "status {$santiagoProvincial->status()}");
    $santiagoProvincialId = $santiagoProvincial->json('data.id');
    $created[] = $santiagoProvincialId;

    $moved = $base('PATCH', "/offices/{$municipalId}", [
        'province_id' => $santiago['id'],
        'municipality_id' => $santiagoMun['id'],
        'address' => 'Municipal Santiago (fumiga, movida)',
    ]);
    check('PATCH municipal movida a Santiago -> 200', $moved->status() === 200, "status {$moved->status()}");
    check('parent re-derivado a la provincial de Santiago', ($moved->json('data.parent_office_id') === $santiagoProvincialId), 'parent '.json_encode($moved->json('data.parent_office_id')));

    $relocateConflict = $base('PATCH', "/offices/{$provincialId}", [
        'province_id' => $santiago['id'],
        'municipality_id' => $santiagoMun['id'],
    ]);
    check('PATCH provincial Holguín hacia Santiago ocupado -> 422', $relocateConflict->status() === 422, "status {$relocateConflict->status()}");

    $childrenGuard = $base('PATCH', "/offices/{$santiagoProvincialId}", [
        'province_id' => $holguin['id'],
        'municipality_id' => $holguinMun['id'],
    ]);
    check('PATCH provincial con hijas activas cambiando territorio -> 422', $childrenGuard->status() === 422, "status {$childrenGuard->status()}");

    echo "== Desactivación libera el ámbito de unicidad ==\n";
    $gone = $base('DELETE', "/offices/{$municipalId}");
    check('DELETE municipal movida -> 200', $gone->status() === 200, "status {$gone->status()}");
    $created = array_values(array_diff($created, [$municipalId]));

    $remun = $base('POST', '/offices', [
        'office_type_id' => $mun['id'],
        'province_id' => $santiago['id'],
        'municipality_id' => $santiagoMun['id'],
        'address' => 'Municipal Santiago (fumiga, recreada)',
    ]);
    check('POST municipal del municipio liberado -> 201', $remun->status() === 201, "status {$remun->status()}");
    $created[] = $remun->json('data.id');
} finally {
    // Limpieza: desactiva en orden inverso (hijas primero).
    foreach (array_reverse($created) as $id) {
        if (is_int($id)) {
            Http::timeout(15)->withToken($token ?? '')->acceptJson()
                ->delete("http://127.0.0.1:8474/api/v1/offices/{$id}");
        }
    }

    // El servidor embebido no muere solo: terminarlo antes del
    // proc_close, o este bloquea para siempre.
    if (is_resource($server)) {
        proc_terminate($server);
        usleep(200000);
        proc_close($server);
    }
}

echo $failures === 0 ? "\nFUMIGA COMPLETA: todo en verde.\n" : "\nFUMIGA CON {$failures} FALLOS.\n";
exit($failures === 0 ? 0 : 1);
