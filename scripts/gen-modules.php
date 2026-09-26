<?php
// Generates the 12 SGP module skeletons under backend/app/Modules/.
// Usage: php scripts/gen-modules.php   (idempotent: skips existing files)
//
// The per-module structure materializes the layered architecture
// (doc section 5, ADR-11: Service + Repository):
//
//   Domain/                 pure entities and value objects
//   Application/Contracts/  repository ports (interfaces)
//   Application/Services/   business logic (use cases)
//   Application/DTO/        readonly input/output contracts
//   Infrastructure/Persistence/        Eloquent repositories (data access)
//   Presentation/Controllers|Requests|Resources/  HTTP surface
//   Tests/Unit|Feature/     module-owned tests
//
// Shared is the kernel module and follows its own documented layout
// (Contracts / Support / Exceptions, doc section 4).

$base = __DIR__ . '/../backend/app/Modules';

$modules = [
    'Shared',
    'Catalogs',
    'Settings',
    'People',
    'Organizations',
    'LegalBasis',
    'PensionCases',
    'PensionCalculation',
    'Pensioners',
    'Payments',
    'Security',
    'Reporting',
];

$layeredDirs = [
    'Domain',
    'Application/Contracts',
    'Application/Services',
    'Application/DTO',
    'Infrastructure/Persistence',
    'Presentation/Controllers',
    'Presentation/Requests',
    'Presentation/Resources',
    'Tests/Unit',
    'Tests/Feature',
];

$sharedDirs = [
    'Contracts',
    'Support',
    'Exceptions',
    'Tests/Unit',
];

$keep = static function (string $dir): void {
    $entries = array_diff(scandir($dir), ['.', '..', '.gitkeep']);
    if ($entries === [] && ! is_file("$dir/.gitkeep")) {
        file_put_contents("$dir/.gitkeep", '');
        echo "keep $dir\n";
    }
};

foreach ($modules as $module) {
    $dirs = $module === 'Shared' ? $sharedDirs : $layeredDirs;

    foreach ($dirs as $dir) {
        $path = "$base/$module/$dir";
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
            echo "dir  $module/$dir\n";
        }
        $keep($path);
    }

    $providerFile = "$base/$module/{$module}ServiceProvider.php";
    if (! file_exists($providerFile)) {
        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Modules\\$module;

use Illuminate\\Support\\ServiceProvider;

final class {$module}ServiceProvider extends ServiceProvider
{
    // Module bindings, migrations, routes and policies are registered
    // here as each delivery phase of the development plan progresses.
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}

PHP;
        file_put_contents($providerFile, $template);
        echo "prov $module\n";
    }
}

echo "Done. Modules: " . count($modules) . "\n";
