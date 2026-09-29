<?php

declare(strict_types=1);

use App\Modules\Shared\Support\CubanIdentityNumber;

dataset('valid identity numbers', [
    // year(2) + month(2) + day(2) + sequence(3) + sex(1) + filler(1) = 11 digits
    'male born 1985-06-15' => ['85061510002', 'M'],
    'female born 1962-11-30' => ['62113045671', 'F'],
    'female born 2010-01-01' => ['10010178931', 'F'],
    'leap day is a plain day now (year not validated)' => ['00022912341', 'M'],
    'june 31st passes under the flat day range' => ['99063156782', 'M'],
    'the old forbidden prefix 9 is just a year digit' => ['98061510001', 'M'],
]);

it('accepts structurally valid identity numbers and derives the sex', function (
    string $number,
    string $expectedSex,
) {
    $identity = CubanIdentityNumber::fromString($number);

    expect($identity->number())->toBe($number)
        ->and($identity->gender())->toBe($expectedSex)
        ->and($identity->__toString())->toBe($number)
        ->and($identity->equals(CubanIdentityNumber::fromString($number)))->toBeTrue();
})->with('valid identity numbers');

it('derives the sex from the parity of digit 10', function () {
    foreach (range(0, 9) as $digit) {
        $number = '850615123'.$digit.'7';

        expect(CubanIdentityNumber::fromString($number)->gender())
            ->toBe($digit % 2 === 0 ? 'M' : 'F');
    }
});

dataset('invalid identity numbers', [
    'too short' => ['8506151002'],
    'too long' => ['850615100023'],
    'not only digits' => ['8506151000a'],
    'empty' => [''],
    'month 13' => ['85133112345'],
    'month 00' => ['85003112345'],
    'day 32' => ['85063212345'],
    'day 00' => ['85060012345'],
]);

it('rejects structurally invalid identity numbers', function (string $number) {
    expect(fn () => CubanIdentityNumber::fromString($number))->toThrow(InvalidArgumentException::class);
})->with('invalid identity numbers');

it('serializes as its plain number', function () {
    $identity = CubanIdentityNumber::fromString('85061510002');

    expect(json_encode(['ci' => $identity]))->toBe('{"ci":"85061510002"}');
});

/*
 * P-08 (open question): the registry check digit is still not
 * verified — no officially public algorithm exists for it. The
 * structural validation above (digits, month, day) plus the
 * digit-10 sex parity is the whole policy until the Ministry
 * confirms the checksum rule.
 */
