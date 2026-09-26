<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Port for the user acting behind the current request or command.
 *
 * Audit stamping (ADR-14) needs to know *who* performs a write so the
 * created_by/updated_by columns can be filled automatically. Business
 * code never touches the framework session directly: it depends on this
 * contract and the Security module's Infrastructure provides the
 * implementation that resolves the authenticated user. Unit tests
 * substitute an in-memory fake, keeping the seam framework-agnostic.
 */
interface CurrentUserProviderInterface
{
    /**
     * Identifier of the acting user, null on anonymous or CLI context.
     */
    public function currentUserId(): ?int;
}
