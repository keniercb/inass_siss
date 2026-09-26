<?php

declare(strict_types=1);

use App\Modules\Shared\Contracts\ClockInterface;
use App\Modules\Shared\Support\SystemClock;

it('returns the current instant in UTC', function () {
    $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $now = (new SystemClock)->now();
    $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    expect($now)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($now->getTimezone()->getName())->toBe('UTC')
        ->and($now >= $before)->toBeTrue()
        ->and($now <= $after)->toBeTrue();
});

it('can substitute the clock behind its contract (test seam)', function () {
    $frozen = new class implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-01-15 12:00:00');
        }
    };

    expect($frozen->now()->format('Y-m-d H:i:s'))->toBe('2026-01-15 12:00:00');
});
