<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Port for the TERRITORIAL OFFICE of the user acting behind the
 * current request or command (ADR-33).
 *
 * Registration flows need to know *which office* performs the
 * capture — a pension case, for instance, assumes the registering
 * user's office instead of receiving it over the wire — so business
 * code depends on this contract and the Security module's
 * Infrastructure provides the implementation that resolves the
 * authenticated user's assignment. Unit tests substitute an
 * in-memory fake, keeping the seam framework-agnostic (the sibling
 * of CurrentUserProviderInterface: same actor, richer context).
 *
 * The raw column answers here — activeness of the office is the
 * caller's semantic rule (it probes the Organizations directory),
 * never this port's concern.
 */
interface CurrentUserOfficeProviderInterface
{
    /**
     * Office identifier of the acting user, null when the actor has
     * no office assigned (or no actor at all: CLI, anonymous).
     */
    public function currentOfficeId(): ?int;
}
