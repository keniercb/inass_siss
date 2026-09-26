<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Money;

// ---------------------------------------------------------------------------
// Creation
// ---------------------------------------------------------------------------

dataset('valid money strings', [
    'integer part only' => ['5000', '5000.00'],
    'one decimal' => ['1234.5', '1234.50'],
    'two decimals' => ['1234.56', '1234.56'],
    'zero' => ['0', '0.00'],
    'zero with decimals' => ['0.10', '0.10'],
    'maximum amount' => ['9999999999.99', '9999999999.99'],
]);

it('creates money from valid decimal strings', function (string $input, string $expected) {
    expect(Money::fromString($input)->__toString())->toBe($expected);
})->with('valid money strings');

dataset('invalid money strings', [
    'empty string' => [''],
    'sign' => ['-500.00'],
    'plus sign' => ['+500.00'],
    'three decimals' => ['1234.567'],
    'thousands separator' => ['1,234.56'],
    'scientific notation' => ['1e3'],
    'letters' => ['abc'],
    'trailing dot' => ['1234.'],
    'space inside' => ['12 34.56'],
    'double dot' => ['12.34.56'],
    'exceeds DECIMAL(12,2)' => ['99999999999.00'],
]);

it('rejects invalid money strings', function (string $input) {
    expect(fn () => Money::fromString($input))->toThrow(InvalidArgumentException::class);
})->with('invalid money strings');

it('serializes money as strings, never as floats', function () {
    $money = Money::fromString('5000.10');

    expect(json_encode(['amount' => $money]))->toBe('{"amount":"5000.10"}')
        ->and($money->toMinorUnits())->toBe(500010);
});

// ---------------------------------------------------------------------------
// Arithmetic
// ---------------------------------------------------------------------------

it('adds and subtracts amounts', function () {
    $a = Money::fromString('100.00');
    $b = Money::fromString('32.50');

    expect($a->add($b)->__toString())->toBe('132.50')
        ->and($a->subtract($b)->__toString())->toBe('67.50');
});

it('refuses subtraction below zero', function () {
    $small = Money::fromString('10.00');
    $big = Money::fromString('20.00');

    expect(fn () => $small->subtract($big))->toThrow(DomainException::class);
});

dataset('bankers rounding multiplications', [
    'exact product' => ['5000.00', '0.65', '3250.00'],
    'tie rounds to even down' => ['10.05', '0.5', '5.02'],
    'tie rounds to even up' => ['10.15', '0.5', '5.08'],
    'non tie rounds down' => ['10.01', '0.33', '3.30'],
    'non tie rounds up' => ['10.11', '0.25', '2.53'],
    'zero factor' => ['5000.00', '0', '0.00'],
    'factor greater than one' => ['2000.00', '1.5', '3000.00'],
    'four decimal factor' => ['1000000.00', '0.0625', '62500.00'],
]);

it('multiplies with banker\'s rounding', function (string $amount, string $factor, string $expected) {
    expect(Money::fromString($amount)->multiply($factor)->__toString())->toBe($expected);
})->with('bankers rounding multiplications');

dataset('percentage applications', [
    '60 percent' => ['5000.00', '60', '3000.00'],
    '90 percent cap' => ['4500.00', '90', '4050.00'],
    '2 percent increment' => ['5000.00', '2', '100.00'],
]);

it('applies percentages without floats', function (string $amount, string $percent, string $expected) {
    expect(Money::fromString($amount)->percentage($percent)->__toString())->toBe($expected);
})->with('percentage applications');

it('rejects invalid factors', function () {
    $money = Money::fromString('100.00');

    expect(fn () => $money->multiply('-1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $money->multiply('abc'))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Comparison
// ---------------------------------------------------------------------------

it('compares money amounts', function () {
    $low = Money::fromString('10.00');
    $same = Money::fromString('10.00');
    $high = Money::fromString('10.01');

    expect($low->equals($same))->toBeTrue()
        ->and($low->equals($high))->toBeFalse()
        ->and($high->isGreaterThan($low))->toBeTrue()
        ->and($low->isLessThan($high))->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue();
});

it('overflows beyond DECIMAL(12,2)', function () {
    $max = Money::fromString('9999999999.99');

    expect(fn () => $max->add(Money::fromString('0.01')))->toThrow(InvalidArgumentException::class);
});
