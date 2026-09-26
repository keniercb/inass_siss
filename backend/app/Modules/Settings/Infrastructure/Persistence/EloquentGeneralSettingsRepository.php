<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Persistence;

use App\Modules\Settings\Application\Contracts\GeneralSettingsRepositoryInterface;
use App\Modules\Settings\Domain\EffectiveSettingCandidate;
use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent persistence for the versioned general settings (ADR-11).
 *
 * The single data-access point of the Settings module: queries live
 * here, vigencia semantics live in Domain/Application. The timeline
 * projection (id + effective_from) hydrates the Domain resolver, and
 * the next-date map feeds the derived effective_to.
 */
final class EloquentGeneralSettingsRepository implements GeneralSettingsRepositoryInterface
{
    public function candidates(): array
    {
        return GeneralSetting::query()
            ->orderBy('effective_from')
            ->get(['id', 'effective_from'])
            ->map(fn (GeneralSetting $setting): EffectiveSettingCandidate => new EffectiveSettingCandidate(
                $setting->id,
                $setting->effective_from,
            ))
            ->all();
    }

    public function find(int $id): ?GeneralSetting
    {
        return GeneralSetting::query()->find($id);
    }

    public function create(array $attributes): GeneralSetting
    {
        $setting = new GeneralSetting;
        $setting->fill($attributes);
        $setting->save();

        return $setting->refresh();
    }

    public function delete(GeneralSetting $setting): bool
    {
        return (bool) $setting->delete();
    }

    public function effectiveFromExists(string $effectiveFrom): bool
    {
        return GeneralSetting::query()
            ->where('effective_from', $effectiveFrom)
            ->exists();
    }

    public function nextEffectiveFromMap(): array
    {
        $dates = GeneralSetting::query()
            ->orderBy('effective_from')
            ->pluck('effective_from')
            ->map(fn ($date): string => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date)
            ->all();

        $map = [];
        $count = count($dates);

        for ($index = 0; $index < $count - 1; $index++) {
            $map[$dates[$index]] = $dates[$index + 1];
        }

        return $map;
    }

    public function paginate(int $page, int $perPage): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, GeneralSetting> */
        return GeneralSetting::query()
            ->orderByDesc('effective_from')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
