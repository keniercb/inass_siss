<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when the restore of an entry that is not logically deleted
 * is attempted (RF-AUD-004: restoring is an explicit, audited
 * operation reserved for deactivated rows). The Presentation layer
 * translates it into a 409 Conflict response.
 */
final class CatalogEntryNotDeletedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The catalog entry is already active: only deactivated entries can be restored.');
    }
}
