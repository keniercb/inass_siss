<?php

declare(strict_types=1);

namespace App\Modules\Catalogs\Application\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a route or service receives a catalog key that is not
 * part of the CatalogRegistry. The Presentation layer translates it
 * into a 404 response: an unknown catalog is not a validation error
 * of the payload but a resource that does not exist.
 */
final class UnknownCatalogException extends InvalidArgumentException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("Unknown catalog [{$key}].");
    }
}
