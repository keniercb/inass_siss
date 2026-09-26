<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application\Contracts;

use App\Modules\Settings\Domain\EffectiveSettingCandidate;
use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence port for the versioned general settings (ADR-11).
 *
 * The repository materializes the Domain timeline projection
 * (candidates) and the full versions, while every vigencia rule —
 * resolution, immutability, no-overlap — stays in the Domain and
 * Application layers. Controllers and services depend on this
 * abstraction only (DIP, ADR-12).
 */
interface GeneralSettingsRepositoryInterface
{
    /**
     * Timeline projection consumed by the Domain resolver (RN-007).
     *
     * @return list<EffectiveSettingCandidate>
     */
    public function candidates(): array;

    public function find(int $id): ?GeneralSetting;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): GeneralSetting;

    public function delete(GeneralSetting $setting): bool;

    /** Probes the natural key effective_from (RN-008, semantic 422). */
    public function effectiveFromExists(string $effectiveFrom): bool;

    /**
     * Map from each effective_from (Y-m-d) to the next vigencia's
     * effective_from (Y-m-d): the input of the derived effective_to.
     *
     * @return array<string, string>
     */
    public function nextEffectiveFromMap(): array;

    /**
     * @return LengthAwarePaginator<int, GeneralSetting>
     */
    public function paginate(int $page, int $perPage): LengthAwarePaginator;
}
