<?php

declare(strict_types=1);

use App\Modules\People\Domain\DuplicateCandidate;
use App\Modules\People\Domain\DuplicatePolicy;
use App\Modules\People\Domain\DuplicateVerdict;

// ---------------------------------------------------------------------------
// RF-PER-005: duplicate control at person registration. These tests were
// written BEFORE the policy exists (TDD) and fix the semantics of the
// three possible verdicts:
//   - the identity number is taken -> hard block, the registered person
//     is returned (RN-001: unique even against deactivated persons and
//     even when the request carries confirm=true)
//   - homonym candidates (same first name, first surname and birth date)
//     -> the operator must confirm before the registry accepts the row
//   - anything else -> allowed
// The policy is a pure domain value: no Eloquent, no clock, no I/O.
// ---------------------------------------------------------------------------

function duplicateCandidate(int $id, string $identity, string $firstName, string $firstSurname, string $birthDate): DuplicateCandidate
{
    return new DuplicateCandidate($id, $identity, $firstName, $firstSurname, $birthDate);
}

it('allows registration when nothing collides', function () {
    $policy = new DuplicatePolicy;

    $verdict = $policy->evaluate(null, [], false);

    expect($verdict)->toBe(DuplicateVerdict::Allow);
});

it('blocks an already registered identity even when confirmed', function () {
    $policy = new DuplicatePolicy;

    $registered = duplicateCandidate(7, '85061510002', 'Juan', 'Pérez', '1985-06-15');

    expect($policy->evaluate($registered, [], true))->toBe(DuplicateVerdict::IdentityRegistered)
        ->and($policy->evaluate($registered, [], false))->toBe(DuplicateVerdict::IdentityRegistered);
});

it('warns about homonym candidates unless the request is confirmed', function () {
    $policy = new DuplicatePolicy;

    $homonyms = [
        duplicateCandidate(8, '18506150098', 'Juan', 'Pérez', '1985-06-15'),
        duplicateCandidate(9, '48506150055', 'Juan', 'Pérez', '1985-06-15'),
    ];

    expect($policy->evaluate(null, $homonyms, false))->toBe(DuplicateVerdict::HomonymWarning);
});

it('allows confirmed homonyms', function () {
    $policy = new DuplicatePolicy;

    $homonyms = [duplicateCandidate(8, '18506150098', 'Juan', 'Pérez', '1985-06-15')];

    expect($policy->evaluate(null, $homonyms, true))->toBe(DuplicateVerdict::Allow);
});

it('ignores confirmation when there are no homonyms', function () {
    $policy = new DuplicatePolicy;

    expect($policy->evaluate(null, [], true))->toBe(DuplicateVerdict::Allow);
});

it('prefers the identity block over the homonym warning', function () {
    $policy = new DuplicatePolicy;

    $registered = duplicateCandidate(7, '85061510002', 'Juan', 'Pérez', '1985-06-15');
    $homonyms = [duplicateCandidate(9, '18506150098', 'Juan', 'Pérez', '1985-06-15')];

    // When both apply, the identity duplicate is the answer the caller
    // needs: the person already exists with THAT identity (RF-PER-005
    // returns the registered person, it never suggests confirming).
    expect($policy->evaluate($registered, $homonyms, false))->toBe(DuplicateVerdict::IdentityRegistered);
});
