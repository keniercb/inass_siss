<?php

declare(strict_types=1);

namespace App\Modules\PensionCases\Domain;

/**
 * Forma de declaración de un tiempo de servicio (RF-EXP-003,
 * corrección de usuario, Task 32): Documental — el vínculo se
 * declara con respaldo documental, valor por defecto — o Testifical
 * — declarado por testimonio. El enum solo fija los dos valores
 * legales: el default del wire se resuelve en la presentación, la
 * columna viaja NOT NULL con DEFAULT 'Documental' y el par queda
 * respaldado por CHECK en el esquema.
 */
enum ServiceDeclarationForm: string
{
    case Documental = 'Documental';
    case Testifical = 'Testifical';
}
