<?php
// Generates the 12 SGP module skeletons under backend/app/Modules/.
// Usage: php scripts/gen-modules.php   (idempotent: skips existing files)
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

$dirs = ['Domain', 'Application', 'Infrastructure', 'Presentation', 'Tests'];

foreach ($modules as $module) {
    foreach ($dirs as $dir) {
        $path = "$base/$module/$dir";
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            echo "dir  $module/$dir\n";
        }
    }

    $providerFile = "$base/$module/{$module}ServiceProvider.php";
    if (!file_exists($providerFile)) {
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
