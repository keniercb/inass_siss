<?php

declare(strict_types=1);
use App\Modules\Security\Infrastructure\Persistence\Models\Role;
use Spatie\Permission\Models\Permission;

// Sobrescritura parcial de la configuración de
// spatie/laravel-permission (ADR-05, ADR-26): el módulo Security
// aporta su propio modelo Role para que los sujetos de bitácora y
// los pivotes resuelvan siempre una clase propiedad del módulo, con
// las columnas de gestión (description, is_system) documentadas en
// un solo lugar. Laravel fusiona este archivo con la configuración
// del paquete (mergeConfigFrom, fusión superficial por clave de
// primer nivel): solo se declara 'models', el resto de claves
// (tablas, caché, columnas) conserva los valores por defecto del
// paquete.

return [
    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
    ],
];
