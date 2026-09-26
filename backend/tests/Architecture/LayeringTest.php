<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Layering conformance suite (ADR-11): keeps the Service + Repository
 * pattern from eroding as the codebase grows. CI fails the build when
 * any rule is broken, so the pattern is enforced mechanically instead
 * of by code-review goodwill.
 *
 * R0  No module code may reference the legacy root App\Models namespace.
 * R1  Presentation never queries the database: Eloquent static entry
 *     points and the DB facade are banned in Controllers, Requests and
 *     Resources (all data access goes through repository ports used by
 *     Application services).
 * R2  Application stays persistence- and framework-agnostic: no
 *     facades, no HTTP classes, no direct Eloquent queries.
 * R3  Domain stays pure: no Eloquent, no HTTP, no facades at all.
 * R4  Infrastructure never reaches back into Presentation.
 */
final class LayeringTest extends TestCase
{
    private const MODULES_ROOT = __DIR__.'/../../app/Modules';

    /** Static Eloquent/DB query entry points that must not appear outside Infrastructure. */
    private const QUERY_ENTRY_POINTS = '/(::(?:query|where|firstWhere|firstOrCreate|updateOrCreate|create|find|findOrFail|findOrNew|save|update|destroy|truncate)\()/';

    /**
     * @return list<array{0: string, 1: string, 2: SplFileInfo, 3: string}>
     *                                                                      [module, layer, file, contents]
     */
    private function moduleFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::MODULES_ROOT, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(self::MODULES_ROOT) + 1);
            $segments = explode('/', $relative);
            $module = $segments[0];
            $layer = $segments[1] ?? '';

            // Module-owned tests may legitimately import anything.
            if ($layer === 'Tests') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $files[] = [$module, $layer, $file, $contents];
        }

        return $files;
    }

    public function test_r0_no_module_references_legacy_root_models(): void
    {
        $offenders = [];

        foreach ($this->moduleFiles() as [, , $file, $contents]) {
            if (str_contains($contents, 'use App\\Models\\')) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, "R0 violated: root App\Models references must not exist; models live in each module's Infrastructure layer.");
    }

    public function test_r1_presentation_never_queries_the_database(): void
    {
        $offenders = [];

        foreach ($this->moduleFiles() as [, $layer, $file, $contents]) {
            if ($layer !== 'Presentation') {
                continue;
            }

            if (str_contains($contents, 'use Illuminate\\Support\\Facades\\DB;')
                || preg_match(self::QUERY_ENTRY_POINTS, $contents) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, 'R1 violated: Presentation must delegate to Application services; it never queries the database.');
    }

    public function test_r2_application_stays_persistence_and_http_agnostic(): void
    {
        $offenders = [];

        foreach ($this->moduleFiles() as [$module, $layer, $file, $contents]) {
            if ($layer !== 'Application') {
                continue;
            }

            $banned = [
                'use Illuminate\\Support\\Facades\\',
                'use Illuminate\\Http\\',
                'use Illuminate\\Routing\\',
                'use App\\Modules\\'.$module.'\\Presentation\\',
            ];

            foreach ($banned as $needle) {
                if (str_contains($contents, $needle) || preg_match(self::QUERY_ENTRY_POINTS, $contents) === 1) {
                    $offenders[] = $file->getPathname().' ('.$needle.')';

                    break;
                }
            }
        }

        self::assertSame([], $offenders, 'R2 violated: Application holds business rules only; persistence, facades and HTTP types are banned.');
    }

    public function test_r3_domain_stays_pure(): void
    {
        $offenders = [];

        foreach ($this->moduleFiles() as [$module, $layer, $file, $contents]) {
            if ($layer !== 'Domain') {
                continue;
            }

            $banned = [
                'use Illuminate\\Database\\',
                'use Illuminate\\Http\\',
                'use Illuminate\\Routing\\',
                'use Illuminate\\Support\\Facades\\',
                'use App\\Modules\\'.$module.'\\Infrastructure\\',
                'use App\\Modules\\'.$module.'\\Presentation\\',
            ];

            foreach ($banned as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $file->getPathname().' ('.$needle.')';

                    break;
                }
            }
        }

        self::assertSame([], $offenders, 'R3 violated: Domain entities must stay pure (no Eloquent, no HTTP, no facades).');
    }

    public function test_r4_infrastructure_never_reaches_presentation(): void
    {
        $offenders = [];

        foreach ($this->moduleFiles() as [$module, $layer, $file, $contents]) {
            if ($layer !== 'Infrastructure') {
                continue;
            }

            if (str_contains($contents, 'use App\\Modules\\'.$module.'\\Presentation\\')) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, 'R4 violated: Infrastructure implements ports; it never imports Presentation.');
    }
}
