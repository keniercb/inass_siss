<?php

declare(strict_types=1);

/**
 * Smoke HTTP de la validación corregida del carné de identidad contra la
 * BD principal (sgp): login admin -> alta con CI válida (mes/día/rango y
 * paridad del dígito 10), rechazos 422 (mes, día, formato viejo, sexo
 * contradictorio) y el guard del PATCH.
 * Ejecutar: php scripts/smoke_carnet.php
 */

require __DIR__.'/../backend/vendor/autoload.php';

$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$failures = 0;

/**
 * Cola aleatoria por corrida (también en los nombres, para no chocar
 * con la política de homónimos): la fumiga es idempotente contra la
 * BD de desarrollo.
 */
$seq = str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT);
$tail = (string) random_int(0, 9);

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (! $ok) {
        $failures++;
    }
    echo ($ok ? '  OK  ' : '  FAIL')." {$label}".($detail !== '' ? " — {$detail}" : '')."\n";
}

// Arranca el servidor embebido solo para esta fumiga (stdout a /dev/null
// para que el proceso no herede la tubería de salida del script).
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:8473', __DIR__.'/../backend/public/index.php'],
    [1 => ['file', '/dev/null', 'w']],
    $pipes,
);

usleep(500000);

try {
    $login = Http::timeout(15)->post('http://127.0.0.1:8473/api/v1/auth/login', [
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

    $base = fn (string $method, string $uri, array $payload = []) => Http::timeout(15)->withToken($token)->acceptJson()
        ->{strtolower($method)}("http://127.0.0.1:8473/api/v1{$uri}", $payload);

    echo "== Registro con CI del formato corregido (mes 06, día 15, dígito 10 par = M) ==\n";
    $maleCi = '850615'.$seq.'0'.$tail;
    $maleName = 'Fumiga'.$seq;
    $person = $base('POST', '/people', [
        'identity_number' => $maleCi,
        'first_name' => $maleName,
        'first_surname' => 'Carné',
        'sex' => 'M',
        'address' => 'Calle 0 #0, La Habana',
        'birth_date' => '1985-06-15',
    ]);
    check("POST /people CI válida ({$maleCi}) -> 201", $person->status() === 201, "status {$person->status()}");

    $personId = $person->json('data.id');

    echo "== Rechazos estructurales ==\n";
    $badMonth = $base('POST', '/people', [
        'identity_number' => '85133112345',
        'first_name' => 'MesRoto',
        'first_surname' => 'Carné',
        'sex' => 'M',
        'address' => 'Calle 0 #0',
        'birth_date' => '1985-06-15',
    ]);
    check('mes 13 en dígitos 3-4 -> 422', $badMonth->status() === 422, "status {$badMonth->status()}");

    $badDay = $base('POST', '/people', [
        'identity_number' => '85063212345',
        'first_name' => 'DiaRoto',
        'first_surname' => 'Carné',
        'sex' => 'M',
        'address' => 'Calle 0 #0',
        'birth_date' => '1985-06-15',
    ]);
    check('día 32 en dígitos 5-6 -> 422', $badDay->status() === 422, "status {$badDay->status()}");

    $oldFormat = $base('POST', '/people', [
        'identity_number' => '18506150012',
        'first_name' => 'Viejo',
        'first_surname' => 'Carné',
        'sex' => 'M',
        'address' => 'Calle 0 #0',
        'birth_date' => '1985-06-15',
    ]);
    check('CI del formato viejo (mes 50 bajo la lectura nueva) -> 422', $oldFormat->status() === 422, "status {$oldFormat->status()}");

    echo "== Paridad del dígito 10 vs sexo declarado ==\n";
    $femaleCi = '850615'.$seq.'1'.$tail;
    $femaleName = 'FumigaF'.$seq;
    $sexMismatch = $base('POST', '/people', [
        'identity_number' => $femaleCi,
        'first_name' => $femaleName,
        'first_surname' => 'Carné',
        'sex' => 'M',
        'address' => 'Calle 0 #0',
        'birth_date' => '1985-06-15',
    ]);
    check('dígito 10 impar (F) con sexo M -> 422', $sexMismatch->status() === 422, "status {$sexMismatch->status()}");

    $sexOk = $base('POST', '/people', [
        'identity_number' => $femaleCi,
        'first_name' => $femaleName,
        'first_surname' => 'Carné',
        'sex' => 'F',
        'address' => 'Calle 0 #0',
        'birth_date' => '1985-06-15',
    ]);
    check("dígito 10 impar (F) con sexo F -> 201 ({$femaleCi})", $sexOk->status() === 201, "status {$sexOk->status()}");

    echo "== Guards del PATCH ==\n";
    $patchBad = $base('PATCH', "/people/{$personId}", ['sex' => 'F']);
    check('PATCH sexo contradictorio -> 422', $patchBad->status() === 422, "status {$patchBad->status()}");

    $patchOk = $base('PATCH', "/people/{$personId}", ['sex' => 'M']);
    check('PATCH reafirma el sexo codificado -> 200', $patchOk->status() === 200, "status {$patchOk->status()}");

    echo "== Búsqueda por prefijo del año ==\n";
    $search = $base('GET', '/people?identity=850615');
    $found = collect($search->json('data') ?? [])->firstWhere('identity_number', $maleCi);
    check('GET /people?identity=850615 encuentra a la persona', $search->status() === 200 && $found !== null, "status {$search->status()}");

    echo $failures === 0 ? "\nSMOKE 10/10 EN VERDE\n" : "\nSMOKE CON {$failures} FALLOS\n";
    exit($failures === 0 ? 0 : 1);
} finally {
    proc_terminate($server);
    usleep(200000);
}
