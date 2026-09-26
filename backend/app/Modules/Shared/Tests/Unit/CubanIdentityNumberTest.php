<?php

declare(strict_types=1);

use App\Modules\Shared\Support\CubanIdentityNumber;

dataset('valid identity numbers', [
    // century(1) + YYMMDD(6) + sequence(3) + check(1) = 11 digits
    'male born 1958-04-13' => ['15804131234', '1958-04-13', 'M'],
    'female born 1962-11-30' => ['26211304567', '1962-11-30', 'F'],
    'male born 2005-06-23' => ['30506231235', '2005-06-23', 'M'],
    'female born 2010-01-01' => ['41001011235', '2010-01-01', 'F'],
    'male born 1899-12-31' => ['59912311235', '1899-12-31', 'M'],
    'leap day in 2000 (leap year)' => ['30002291235', '2000-02-29', 'M'],
]);

it('accepts structurally valid identity numbers and resolves birth data', function (
    string $number,
    string $expectedBirthDate,
    string $expectedGender,
) {
    $identity = CubanIdentityNumber::fromString($number);

    expect($identity->number())->toBe($number)
        ->and($identity->birthDate()->format('Y-m-d'))->toBe($expectedBirthDate)
        ->and($identity->gender())->toBe($expectedGender)
        ->and($identity->__toString())->toBe($number)
        ->and($identity->equals(CubanIdentityNumber::fromString($number)))->toBeTrue();
})->with('valid identity numbers');

dataset('invalid identity numbers', [
    'too short' => ['1580413123'],
    'too long' => ['158041312345'],
    'not only digits' => ['1580413123a'],
    'empty' => [''],
    'invalid prefix 0' => ['05804131234'],
    'invalid prefix 7' => ['75804131234'],
    'invalid prefix 9' => ['95804131234'],
    'invalid month 13' => ['15813131234'],
    'invalid month 00' => ['15800131234'],
    'invalid day 32' => ['15801321234'],
    'february 30' => ['15802301234'],
    'february 29 in non-leap 1900' => ['10002291234'],
]);

it('rejects structurally invalid identity numbers', function (string $number) {
    expect(fn () => CubanIdentityNumber::fromString($number))->toThrow(InvalidArgumentException::class);
})->with('invalid identity numbers');

it('serializes as its plain number', function () {
    $identity = CubanIdentityNumber::fromString('15804131234');

    expect(json_encode(['ci' => $identity]))->toBe('{"ci":"15804131234"}');
});

/*
 * P-08 (open question): the 11th digit is the registry check digit, but no
 * officially verifiable public algorithm exists for it. Once the Ministry
 * confirms the rule, a checksum policy will be added here and enforced in
 * the constructor. Until then, structural validation is authoritative.
 */
