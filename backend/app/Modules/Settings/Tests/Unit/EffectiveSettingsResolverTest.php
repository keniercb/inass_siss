<?php

declare(strict_types=1);

use App\Modules\Settings\Domain\EffectiveSettingCandidate;
use App\Modules\Settings\Domain\EffectiveSettingsResolver;
use DateTimeImmutable;

// ---------------------------------------------------------------------------
// RN-007: the effective configuration at a given date is the row with the
// greatest effective_from less than or equal to that date. These datasets
// were written BEFORE the resolver exists (TDD): they fix the semantics of
// "vigencia" and "no solapamiento" for the whole module.
// ---------------------------------------------------------------------------

function candidate(int $id, string $effectiveFrom): EffectiveSettingCandidate
{
    return new EffectiveSettingCandidate($id, new DateTimeImmutable($effectiveFrom));
}

it('returns the only candidate not later than the date', function () {
    $resolver = new EffectiveSettingsResolver;

    $result = $resolver->resolve(
        [candidate(1, '2024-01-01')],
        new DateTimeImmutable('2024-05-15'),
    );

    $this->assertNotNull($result);
    expect($result->id)->toBe(1);
});

it('picks the greatest effective_from that is still effective', function () {
    $resolver = new EffectiveSettingsResolver;

    $result = $resolver->resolve(
        [candidate(1, '2020-01-01'), candidate(2, '2023-01-01'), candidate(3, '2026-06-01')],
        new DateTimeImmutable('2024-08-01'),
    );

    $this->assertNotNull($result);
    expect($result->id)->toBe(2);
});

it('treats the boundary date as already in force', function (string $effectiveFrom, string $at, int $expectedId) {
    $resolver = new EffectiveSettingsResolver;

    $result = $resolver->resolve(
        [candidate(1, '2020-01-01'), candidate(2, $effectiveFrom)],
        new DateTimeImmutable($at),
    );

    $this->assertNotNull($result);
    expect($result->id)->toBe($expectedId);
})->with([
    'exact effective date' => ['2023-01-01', '2023-01-01', 2],
    'day before the switch' => ['2023-01-01', '2022-12-31', 1],
]);

it('returns null when nothing is in force yet', function () {
    $resolver = new EffectiveSettingsResolver;

    $result = $resolver->resolve(
        [candidate(1, '2023-01-01'), candidate(2, '2026-06-01')],
        new DateTimeImmutable('2022-06-01'),
    );

    expect($result)->toBeNull();
});

it('returns null for an empty timeline', function () {
    expect((new EffectiveSettingsResolver)->resolve([], new DateTimeImmutable('2024-01-01')))->toBeNull();
});

it('resolves regardless of the insertion order of the timeline', function () {
    $resolver = new EffectiveSettingsResolver;

    $result = $resolver->resolve(
        [candidate(3, '2026-06-01'), candidate(1, '2020-01-01'), candidate(2, '2023-01-01')],
        new DateTimeImmutable('2030-01-01'),
    );

    $this->assertNotNull($result);
    expect($result->id)->toBe(3);
});

it('ignores the time of day when comparing against effective_from', function () {
    $resolver = new EffectiveSettingsResolver;

    $morning = new DateTimeImmutable('2023-01-01 00:00:00');
    $evening = new DateTimeImmutable('2023-01-01 23:59:59');

    $inTheMorning = $resolver->resolve([candidate(2, '2023-01-01')], $morning);
    $inTheEvening = $resolver->resolve([candidate(2, '2023-01-01')], $evening);

    $this->assertNotNull($inTheMorning);
    $this->assertNotNull($inTheEvening);
    expect($inTheMorning->id)->toBe(2)
        ->and($inTheEvening->id)->toBe(2);
});

// ---------------------------------------------------------------------------
// Immutability of the value object: two identical candidates are distinct
// identity-carrying records, but the id stays readable for the repository.
// ---------------------------------------------------------------------------

it('exposes the candidate identity and effective date', function () {
    $candidate = candidate(7, '2025-03-01');

    expect($candidate->id)->toBe(7)
        ->and($candidate->effectiveFrom)->toEqual(new DateTimeImmutable('2025-03-01'));
});
