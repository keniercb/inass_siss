<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Support;

use App\Modules\Security\Infrastructure\Persistence\Models\User;
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
 * conversational-422 pattern, see ADR-22), the Hash facade behind
 * the model's `hashed` password cast and the auth/permission
 * configuration the spatie Role model resolves during construction
 * (default guard and table names, ADR-26).
 *
 * These tests stay container-free EXCEPT for this minimal binding: an
 * empty Container with the validator factory (array-loader translator),
 * a cheap bcrypt hasher and the guard map. The container also becomes
 * the global instance so the plain config() helper (used inside
 * spatie model boot) resolves. Feature tests boot the real application
 * — the Foundation Application constructor resets the global instance —
 * so this never leaks across suites; unit suites that finish with it
 * may clear it with forgetMinimalContainer().
 */
trait BootsMinimalValidator
{
    /**
     * Binds the smallest container able to answer the Validator and
     * Hash facades, and publishes it as the global container instance
     * for the config() helper. Idempotent per test run; called from
     * setUp.
     */
    protected function bindMinimalValidatorFacade(): void
    {
        $container = new Container;
        $translator = new Translator(new ArrayLoader, 'en');

        $container->instance('translator', $translator);
        $container->instance('config', new ConfigRepository([
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
            // spatie\Permission models resolve the default guard and
            // their table names from these keys while instantiating;
            // the values mirror the real application configuration.
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => [
                    'web' => ['driver' => 'session', 'provider' => 'users'],
                ],
                'providers' => [
                    'users' => [
                        'driver' => 'eloquent',
                        'model' => User::class,
                    ],
                ],
            ],
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
        Container::setInstance($container);
    }

    /**
     * Releases the global container instance published by the seam,
     * for unit suites that finish with it and want to leave no trace
     * behind for the rest of the process.
     */
    protected function forgetMinimalContainer(): void
    {
        Container::setInstance(null);
    }
}
