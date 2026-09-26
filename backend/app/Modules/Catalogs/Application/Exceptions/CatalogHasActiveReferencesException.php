<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when a catalog entry cannot be logically deactivated because
 * other active records still reference it (RF-CAT-001: the
 * deactivation is blocked while references exist). The Presentation
 * layer translates it into a 409 Conflict response.
 */
final class CatalogHasActiveReferencesException extends RuntimeException
{
    /**
     * @param  list<string>  $references  Human-readable list of dependent records
     */
    public function __construct(public readonly array $references)
    {
        parent::__construct(
            'The catalog entry cannot be deactivated while active records reference it: '
            .implode(', ', $references).'.',
        );
    }
}
