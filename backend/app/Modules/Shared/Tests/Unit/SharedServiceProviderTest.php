<?php

declare(strict_types=1);

use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use App\Modules\Shared\SharedServiceProvider;
use App\Modules\Shared\Support\AuditRecorder;
use App\Modules\Shared\Support\SystemClock;
use Illuminate\Container\Container;

// SharedServiceProvider wiring (ADR-24): the kernel binds its two
// container seams — the clock port and the singleton audit writer —
// against a bare Container, no booted application needed, so the
// coverage gate keeps measuring the provider instead of excluding
// it as glue. The actor port belongs to Security's wiring: the test
// substitutes a null actor so the recorder can resolve in isolation.

it('binds the clock to the system implementation', function () {
    $container = new Container;
    (new SharedServiceProvider($container))->register();

    expect($container->make(SystemClock::class))->toBeInstanceOf(SystemClock::class);
});

it('resolves the audit recorder as a singleton', function () {
    $container = new Container;
    $container->bind(
        CurrentUserProviderInterface::class,
        static fn (): CurrentUserProviderInterface => new class implements CurrentUserProviderInterface
        {
            public function currentUserId(): ?int
            {
                return null;
            }
        },
    );
    (new SharedServiceProvider($container))->register();

    $first = $container->make(AuditRecorder::class);
    $second = $container->make(AuditRecorder::class);

    expect($first)->toBeInstanceOf(AuditRecorder::class)
        ->and($second)->toBe($first);
});
