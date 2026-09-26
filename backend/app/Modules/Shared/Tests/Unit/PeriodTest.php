<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Period;

function makePeriod(string $start, ?string $end = null): Period
{
    return $end === null
        ? Period::starting(new DateTimeImmutable($start))
        : Period::between(new DateTimeImmutable($start), new DateTimeImmutable($end));
}

it('rejects an end earlier than its start', function () {
    expect(fn () => makePeriod('2020-01-10', '2020-01-01'))->toThrow(InvalidArgumentException::class);
});

it('accepts equal start and end (single-day period, RN-006)', function () {
    $period = makePeriod('2020-01-05', '2020-01-05');

    expect($period->lengthInDays())->toBe(0)
        ->and($period->hasEnd())->toBeTrue();
});

it('measures length in days with exclusive end', function () {
    expect(makePeriod('2020-01-01', '2020-01-31')->lengthInDays())->toBe(30)
        ->and(makePeriod('2020-01-01', '2021-01-01')->lengthInDays())->toBe(366);
});

it('cannot measure the length of an open-ended period', function () {
    expect(fn () => makePeriod('2020-01-01')->lengthInDays())->toThrow(LogicException::class);
});

it('contains dates within the range', function () {
    $period = makePeriod('2020-01-01', '2020-12-31');

    expect($period->contains(new DateTimeImmutable('2020-06-15')))->toBeTrue()
        ->and($period->contains(new DateTimeImmutable('2020-12-31')))->toBeTrue()
        ->and($period->contains(new DateTimeImmutable('2021-01-01')))->toBeFalse()
        ->and($period->contains(new DateTimeImmutable('2019-12-31')))->toBeFalse();
});

it('treats open-ended periods as containing everything after the start', function () {
    $open = makePeriod('2020-01-01');

    expect($open->contains(new DateTimeImmutable('2030-06-15')))->toBeTrue()
        ->and($open->contains(new DateTimeImmutable('2019-12-31')))->toBeFalse();
});

dataset('period overlap cases', [
    'partial overlap' => ['2020-01-01', '2020-06-30', '2020-06-01', '2020-12-31', true],
    'no overlap' => ['2020-01-01', '2020-03-31', '2020-04-01', '2020-12-31', false],
    'adjacent (end == start) overlaps' => ['2020-01-01', '2020-03-31', '2020-03-31', '2020-12-31', true],
    'contained period' => ['2020-01-01', '2020-12-31', '2020-05-01', '2020-05-31', true],
    'identical periods' => ['2020-01-01', '2020-12-31', '2020-01-01', '2020-12-31', true],
]);

it('detects overlaps between closed periods', function (
    string $aStart, string $aEnd, string $bStart, string $bEnd, bool $expected,
) {
    $a = makePeriod($aStart, $aEnd);
    $b = makePeriod($bStart, $bEnd);

    expect($a->overlaps($b))->toBe($expected);
})->with('period overlap cases');

it('open-ended periods overlap any later period', function () {
    $open = makePeriod('2020-01-01');

    expect($open->overlaps(makePeriod('2030-01-01', '2030-12-31')))->toBeTrue()
        ->and($open->overlaps(makePeriod('2019-01-01', '2019-12-31')))->toBeFalse();
});

it('compares periods for equality', function () {
    expect(makePeriod('2020-01-01', '2020-12-31')->equals(makePeriod('2020-01-01', '2020-12-31')))->toBeTrue()
        ->and(makePeriod('2020-01-01', '2020-12-31')->equals(makePeriod('2020-01-01')))->toBeFalse();
});
