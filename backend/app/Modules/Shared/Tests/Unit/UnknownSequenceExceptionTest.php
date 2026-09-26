<?php

declare(strict_types=1);

use App\Modules\Shared\Exceptions\UnknownSequenceException;

// ---------------------------------------------------------------------------
// Unknown sequence scope (RF-PAG-006, ADR-17)
// ---------------------------------------------------------------------------

it('names the offending scope in the message', function () {
    $exception = UnknownSequenceException::forScope('bank_control');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe(
            'The sequence [bank_control] is not declared. Declare it in the SettingsSeeder before emitting numbers.'
        );
});

it('keeps the message actionable for a second scope', function () {
    expect(UnknownSequenceException::forScope('pension_case')->getMessage())
        ->toStartWith('The sequence [pension_case] is not declared.');
});
