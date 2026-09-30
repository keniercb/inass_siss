<?php

declare(strict_types=1);

// Smoke HTTP real (Task 25, SGP-20): login admin, jerarquía de
// oficinas + un expediente, asignación de oficina al usuario y las
// tres superficies nuevas — conteo por oficina en árbol y detalle
// (ADR-28), oficina del usuario en /auth/me y gestión de cuentas
// (ADR-29) con el guard de desactivación conversacional.

require __DIR__.'/../backend/vendor/autoload.php';

$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Catalogs\Infrastructure\Persistence\Models\EntityType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Municipality;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\OfficeType;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Organization;
use App\Modules\Catalogs\Infrastructure\Persistence\Models\Province;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Entity;
use App\Modules\Organizations\Infrastructure\Persistence\Models\Office;
use App\Modules\People\Infrastructure\Persistence\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Support\Facades\DB;

function call(string $method, string $uri, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init("http://127.0.0.1:8000/api/v1{$uri}");
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode((string) $raw, true) ?: []];
}

function check(string $label, bool $ok, string $extra = ''): void
{
    echo ($ok ? '  OK ' : '  FAIL ').$label.($extra !== '' ? " — {$extra}" : '').PHP_EOL;
    if (! $ok) {
        exit(1);
    }
}

echo "== Smoke SGP-20 (conteo por oficina + usuario↔oficina) ==\n";

// --- datos maestros mínimos sobre el seed existente (idempotentes) ---
DB::table('pension_cases')->where('number', '990001')->delete();
DB::table('users')->whereNotNull('office_id')->update(['office_id' => null]);
Office::withTrashed()->where('address', 'Oficina municipal Holguín')->forceDelete();
Office::withTrashed()->where('address', 'Oficina provincial Holguín')->forceDelete();
Entity::withTrashed()->where('code', 'ENT-SMOKE')->forceDelete();

$province = Province::query()->where('code', '12')->first() ?? Province::query()->create(['code' => '12', 'name' => 'Holguín']);
$municipality = Municipality::query()->where('province_id', $province->id)->first()
    ?? Municipality::query()->create(['province_id' => $province->id, 'code' => '01', 'name' => 'Holguín']);
$type = OfficeType::query()->first() ?? OfficeType::query()->create(['code' => 'MUN', 'name' => 'Municipal']);

$root = Office::query()->create([
    'office_type_id' => $type->id,
    'province_id' => $province->id,
    'municipality_id' => $municipality->id,
    'address' => 'Oficina provincial Holguín',
    'created_by' => 1,
]);
$child = Office::query()->create([
    'office_type_id' => $type->id,
    'province_id' => $province->id,
    'municipality_id' => $municipality->id,
    'address' => 'Oficina municipal Holguín',
    'parent_office_id' => $root->id,
    'created_by' => 1,
]);

// Expediente de humo sobre la oficina hija: persona y entidad mínimas.
$person = Person::query()->first() ?? Person::factory()->create();
$organization = Organization::query()->first() ?? Organization::query()->create(['code' => 'MTSS', 'name' => 'Ministerio de Trabajo']);
$entityType = EntityType::query()->first() ?? EntityType::query()->create(['code' => 'EMP', 'name' => 'Empresa']);
$entity = Entity::query()->where('code', 'ENT-SMOKE')->first() ?? Entity::query()->create([
    'code' => 'ENT-SMOKE',
    'name' => 'Empresa de Humo',
    'tax_id_number' => '11000099999',
    'organization_id' => $organization->id,
    'province_id' => $province->id,
    'municipality_id' => $municipality->id,
    'entity_type_id' => $entityType->id,
    'address' => 'Calle 100 #0',
    'social_purpose' => 'Humo',
    'created_by' => 1,
]);

DB::table('pension_cases')->insert([
    'number' => '990001',
    'requested_at' => now()->toDateString(),
    'status' => 'submitted',
    'applicant_person_id' => $person->id,
    'office_id' => $child->id,
    'employer_entity_id' => $entity->id,
    'position_id' => DB::table('positions')->value('id'),
    'occupational_category_id' => DB::table('occupational_categories')->value('id'),
    'educational_level_id' => DB::table('educational_levels')->value('id'),
    'scientific_category_id' => DB::table('scientific_categories')->value('id'),
    'last_salary' => '5000.00',
    'created_at' => now(),
    'updated_at' => now(),
]);

// --- login ---------------------------------------------------------
[$status, $json] = call('POST', '/auth/login', ['email' => 'admin@sgp.local', 'password' => 'password']);
$token = $json['data']['token'] ?? null;
check('login admin', $status === 200 && $token !== null, "status={$status}");

// --- /auth/me sin oficina -------------------------------------------
[$status, $me] = call('GET', '/auth/me', null, $token);
check('/auth/me sin oficina → office null', $status === 200
    && array_key_exists('office', $me['data'] ?? [])
    && $me['data']['office'] === null, "status={$status}");

// --- asignar la oficina al admin (PATCH /users/{id}) -----------------
[$status, $json] = call('PATCH', '/users/1', ['office_id' => $root->id], $token);
check('PATCH /users/1 con office_id', $status === 200 && ($json['data']['office']['id'] ?? null) === $root->id, "status={$status}");

[$status, $me] = call('GET', '/auth/me', null, $token);
check('/auth/me devuelve la oficina', $status === 200
    && ($me['data']['office']['id'] ?? null) === $root->id
    && ($me['data']['office']['type']['code'] ?? null) !== null, "status={$status}");

// --- árbol y detalle con conteos (ADR-28) ----------------------------
[$status, $tree] = call('GET', '/offices/tree', null, $token);
$node = null;
$walk = function (array $nodes) use (&$walk, &$node, $root): void {
    foreach ($nodes as $n) {
        if (($n['id'] ?? null) === $root->id) {
            $node = $n;
        }
        $walk($n['children'] ?? []);
    }
};
$walk($tree['data'] ?? []);
check('árbol: nodo raíz con cases_count/scope_cases_count', $status === 200
    && isset($node['cases_count'], $node['scope_cases_count'])
    && $node['scope_cases_count'] >= 1, "status={$status}, scope=".($node['scope_cases_count'] ?? 'n/a'));

[$status, $detail] = call('GET', "/offices/{$root->id}", null, $token);
check('detalle: cases_count + scope_cases_count', $status === 200
    && ($detail['data']['cases_count'] ?? -1) === 0
    && ($detail['data']['scope_cases_count'] ?? -1) === ($node['scope_cases_count'] ?? -2), "status={$status}");

// --- guard de desactivación (ADR-29) ---------------------------------
// La hija no tiene subordinadas: su único obstáculo son los usuarios.
[$status, $json] = call('PATCH', '/users/1', ['office_id' => $child->id], $token);
check('reasignar admin a la hija', $status === 200 && ($json['data']['office']['id'] ?? null) === $child->id, "status={$status}");

[$status, $json] = call('DELETE', "/offices/{$child->id}", null, $token);
check('DELETE hija con usuario activo → 422 office_id', $status === 422
    && isset($json['errors']['office_id']), "status={$status}");

// reasignar y desactivar (hija primero: la raíz no puede quedar con hijas activas)
call('PATCH', '/users/1', ['office_id' => null], $token);
[$status, $json] = call('DELETE', "/offices/{$child->id}", null, $token);
check('DELETE hija tras reasignar → 200', $status === 200, "status={$status}");
[$status, $json] = call('DELETE', "/offices/{$root->id}", null, $token);
check('DELETE raíz → 200', $status === 200, "status={$status}");

// --- listado de usuarios con oficina ---------------------------------
[$status, $json] = call('GET', '/users?per_page=5', null, $token);
$row = null;
foreach (($json['data'] ?? []) as $u) {
    if (($u['id'] ?? null) === 1) {
        $row = $u;
    }
}
check('GET /users fila con office null tras limpiar', $status === 200
    && $row !== null
    && array_key_exists('office', $row)
    && $row['office'] === null, "status={$status}");

echo "== Smoke completo: OK ==\n";
