<?php

declare(strict_types=1);

// Security module policy knobs (RF-SEG-001, ADR-24). Values live in
// configuration — not in the database — because they are operational
// hardening, not versioned business configuration (that role belongs
// to general_settings, RN-007). The Application layer reads them and
// builds the pure PasswordPolicy/LockoutPolicy domain values, so the
// domain never touches config and tests can override at runtime.

return [

    'password' => [
        // Longitud mínima (RF-SEG-001 "política de contraseñas").
        'min_length' => 10,

        // Complejidad: al menos una mayúscula, una minúscula y un dígito.
        'require_complexity' => true,

        // Caducidad OPCIONAL: null la mantiene apagada (renovación solo
        // voluntaria); en días contados desde password_changed_at.
        'max_age_days' => null,
    ],

    'lockout' => [
        // Bloqueo temporal tras N intentos fallidos consecutivos
        // (RF-SEG-001): al llegar al máximo se estampa locked_at.
        'max_attempts' => 5,

        // Duración del bloqueo en segundos (15 minutos por defecto).
        // El desbloqueo automático se deriva al leer; el Administrador
        // puede adelantarlo con POST /users/{id}/unlock.
        'ttl_seconds' => 900,
    ],

];
