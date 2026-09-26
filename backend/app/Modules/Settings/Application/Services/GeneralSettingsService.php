<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application\Services;

use App\Modules\Settings\Application\Contracts\GeneralSettingsRepositoryInterface;
use App\Modules\Settings\Application\Contracts\GeneralSettingsServiceInterface;
use App\Modules\Settings\Application\Exceptions\VersionAlreadyEffectiveException;
use App\Modules\Settings\Domain\EffectiveSettingsResolver;
use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use App\Modules\Shared\Contracts\ClockInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Use cases for the versioned general settings (RF-CAT-005, RN-007,
 * ADR-16).
 *
 * The service orchestrates the pure Domain resolver with the
 * repository timeline: effectiveAt() is the domain action that
 * resolves the version in force at a date ("now" comes from the
 * Clock port, never from the system directly). Versions are
 * immutable — create() probes effective_from before insert to answer
 * a semantic 422 instead of a driver error (RN-008 convention) — and
 * only future vigencias may be deleted. effective_to is derived on
 * read from the next vigencia and attached to the models, so the
 * stored timeline never denormalizes derivable state.
 */
final class GeneralSettingsService implements GeneralSettingsServiceInterface
{
    public function __construct(
        private readonly GeneralSettingsRepositoryInterface $settings,
        private readonly ClockInterface $clock,
        private readonly EffectiveSettingsResolver $resolver,
    ) {}

    public function effectiveAt(?DateTimeImmutable $at = null): ?GeneralSetting
    {
        $at ??= $this->clock->now();

        $candidate = $this->resolver->resolve($this->settings->candidates(), $at);

        if ($candidate === null) {
            return null;
        }

        $setting = $this->settings->find($candidate->id);

        if ($setting !== null) {
            $this->attachDerivedEffectiveTo([$setting]);
        }

        return $setting;
    }

    public function create(array $attributes): GeneralSetting
    {
        $effectiveFrom = (string) $attributes['effective_from'];

        if ($this->settings->effectiveFromExists($effectiveFrom)) {
            throw ValidationException::withMessages([
                'effective_from' => 'A settings version already takes effect on this date (RN-007: vigencias never overlap).',
            ]);
        }

        $setting = $this->settings->create($attributes);
        $this->attachDerivedEffectiveTo([$setting]);

        return $setting;
    }

    public function list(int $page, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->settings->paginate($page, $perPage);

        /** @var array<int, GeneralSetting> $items */
        $items = $paginator->items();

        $this->attachDerivedEffectiveTo($items);

        return $paginator;
    }

    public function get(int $id): ?GeneralSetting
    {
        $setting = $this->settings->find($id);

        if ($setting === null) {
            return null;
        }

        $this->attachDerivedEffectiveTo([$setting]);

        return $setting;
    }

    public function delete(int $id): bool
    {
        $setting = $this->settings->find($id);

        if ($setting === null) {
            return false;
        }

        $today = $this->clock->now()->format('Y-m-d');

        if ($setting->effective_from->format('Y-m-d') <= $today) {
            throw VersionAlreadyEffectiveException::forDate($setting->effective_from->format('Y-m-d'));
        }

        return $this->settings->delete($setting);
    }

    /**
     * Derives effective_to as "the day before the next vigencia" and
     * attaches it to each version (null on the newest one). Derived
     * on read: the column never exists in the table.
     *
     * @param  array<int, GeneralSetting>  $settings
     */
    private function attachDerivedEffectiveTo(array $settings): void
    {
        $nextMap = $this->settings->nextEffectiveFromMap();

        foreach ($settings as $setting) {
            $from = $setting->effective_from->format('Y-m-d');

            $effectiveTo = isset($nextMap[$from])
                ? (new DateTimeImmutable($nextMap[$from]))->modify('-1 day')->format('Y-m-d')
                : null;

            $setting->setAttribute('effective_to', $effectiveTo);
        }
    }
}
