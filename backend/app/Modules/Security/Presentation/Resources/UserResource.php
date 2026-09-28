<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Resources;

use App\Modules\Security\Application\Authentication\SecurityPolicies;
use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Shared\Contracts\ClockInterface;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin User */
#[OA\Schema(
    schema: 'User',
    title: 'Usuario',
    description: 'Cuenta del sistema SGP con roles, permisos efectivos, persona vinculada y el estado de seguridad derivado de la sesión (bloqueo, política de contraseñas, RF-SEG-001).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'SGP Demo Admin'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@sgp.local'),
        new OA\Property(
            property: 'roles',
            type: 'array',
            description: 'Roles institucionales asignados (sección 2.2)',
            items: new OA\Items(type: 'string', example: 'operator'),
        ),
        new OA\Property(
            property: 'permissions',
            type: 'array',
            description: 'Permisos efectivos (de roles y directos), orden alfabético',
            items: new OA\Items(type: 'string', example: 'people.create'),
        ),
        new OA\Property(
            property: 'person',
            nullable: true,
            description: 'Persona del registro único vinculada a la cuenta (RF-SEG-004); null mientras no exista asociación',
            ref: '#/components/schemas/LinkedPerson',
        ),
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['active', 'inactive'],
            description: 'Ciclo de vida de la cuenta: inactive = desactivada (borrado lógico, RF-AUD-004)',
        ),
        new OA\Property(
            property: 'locked',
            type: 'boolean',
            description: 'Bloqueada por intentos fallidos y aún dentro del TTL (RF-SEG-001): se deriva al leer de locked_at + política',
        ),
        new OA\Property(
            property: 'locked_until',
            type: 'string',
            format: 'date-time',
            nullable: true,
            description: 'Instanto en que el bloqueo expira automáticamente (null si no está bloqueada)',
        ),
        new OA\Property(
            property: 'failed_login_attempts',
            type: 'integer',
            description: 'Intentos fallidos consecutivos contados desde el último acceso válido',
        ),
        new OA\Property(
            property: 'password_changed_at',
            type: 'string',
            format: 'date-time',
            nullable: true,
            description: 'Línea base de la caducidad opcional de la contraseña (ADR-24)',
        ),
        new OA\Property(
            property: 'password_expired',
            type: 'boolean',
            description: 'La contraseña superó la edad máxima configurada (false cuando la caducidad está apagada)',
        ),
    ],
)]
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Derived state resolves against the clock + policies through
        // the container — the same single documented exit used by the
        // AuthorizedSignature/LegalBasis projections (ADR-24).
        /** @var ClockInterface $clock */
        $clock = app(ClockInterface::class);
        $now = $clock->now();

        $lockout = SecurityPolicies::lockoutFromConfig();
        $password = SecurityPolicies::passwordFromConfig();

        $lockedAt = $this->locked_at instanceof DateTimeImmutable ? $this->locked_at : null;
        $passwordChangedAt = $this->password_changed_at instanceof DateTimeImmutable ? $this->password_changed_at : null;

        $locked = $lockout->isLocked($lockedAt, $clock);
        $lockedUntil = $locked && $lockedAt !== null
            ? $lockedAt->modify(sprintf('+%d seconds', $lockout->ttlSeconds))->format(DateTimeImmutable::ATOM)
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames()->sort()->values()->all(),
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'person' => $this->person !== null ? new LinkedPersonResource($this->person) : null,
            'status' => $this->deleted_at === null ? 'active' : 'inactive',
            'locked' => $locked,
            'locked_until' => $lockedUntil,
            'failed_login_attempts' => (int) $this->failed_login_attempts,
            'password_changed_at' => $passwordChangedAt?->format(DateTimeImmutable::ATOM),
            'password_expired' => $password->requiresRenewal($passwordChangedAt, $clock),
        ];
    }
}
