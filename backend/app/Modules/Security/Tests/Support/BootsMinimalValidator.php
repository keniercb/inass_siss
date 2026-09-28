<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Support;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;

/**
 * Unit-test seam for the framework facades the Security domain model
 * leans on without a booted application: the Validator facade behind
 * the 422-semantic ValidationException::withMessages (established
 * conversational-422 pattern, see ADR-22) and the Hash facade behind
 * the model's `hashed` password cast.
 *
 * These tests stay container-free EXCEPT for this minimal binding: an
 * empty Container with the validator factory (array-loader translator)
 * and a cheap bcrypt hasher. Feature tests boot the real application
 * and overwrite the facade root on their own, so this never leaks.
 */
trait BootsMinimalValidator
{
    /**
     * Binds the smallest container able to answer the Validator and
     * Hash facades. Idempotent per test run; called from setUp.
     */
    protected function bindMinimalValidatorFacade(): void
    {
        $container = new Container;
        $translator = new Translator(new ArrayLoader, 'en');

        $container->instance('translator', $translator);
        $container->instance('config', new ConfigRepository([
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
        ]));
        $container->bind(
            'validator',
            static fn (): ValidationFactory => new ValidationFactory($translator),
        );
        $container->bind(
            'hash',
            static fn (): HashManager => new HashManager($container),
        );

        // The facade root contract expects the full application; this
        // seam only provides service bindings, which is all the facades
        // resolved here actually consume.
        Facade::setFacadeApplication($container); // @phpstan-ignore argument.type (test seam: bindings-only container)
    }
}
