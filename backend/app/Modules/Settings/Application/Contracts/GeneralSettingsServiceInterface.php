<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application\Contracts;

use App\Modules\Settings\Application\Exceptions\VersionAlreadyEffectiveException;
use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use-case port for the versioned general settings (RF-CAT-005,
 * RN-007, ADR-12).
 *
 * effectiveAt() is the domain action every calculation will call to
 * resolve — and later freeze — the parameters in force at a given
 * date. Versions are immutable: create() adds new vigencias and
 * delete() only removes versions that have not taken effect yet.
 */
interface GeneralSettingsServiceInterface
{
    /**
     * Version in force at the given date, or at "now" when null
     * (RN-007: greatest effective_from <= date).
     */
    public function effectiveAt(?DateTimeImmutable $at = null): ?GeneralSetting;

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException when effective_from is already in use (RN-007/RN-008)
     */
    public function create(array $attributes): GeneralSetting;

    /**
     * Versions with the derived effective_to attached, newest first.
     *
     * @return LengthAwarePaginator<int, GeneralSetting>
     */
    public function list(int $page, int $perPage): LengthAwarePaginator;

    /** Version with the derived effective_to attached. */
    public function get(int $id): ?GeneralSetting;

    /**
     * @throws VersionAlreadyEffectiveException when the version is already in force
     */
    public function delete(int $id): bool;
}
