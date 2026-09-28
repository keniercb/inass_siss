<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Exceptions;

use RuntimeException;

/**
 * Login attempt with valid credentials whose password has aged past
 * the optional max (RF-SEG-001 "caducidad opcional", ADR-24).
 *
 * Distinct from invalid credentials on purpose: the caller holds
 * valid secrets, so the 401 response tells them to renew instead of
 * implying the password is wrong. Renewal self-service (current +
 * new password) applies while the password is still valid; once
 * expired, an administrator resets it.
 */
final class PasswordExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The password has expired and must be renewed.');
    }
}
