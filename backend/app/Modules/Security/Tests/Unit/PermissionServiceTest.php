<?php

declare(strict_types=1);

namespace App\Modules\Security\Tests\Unit;

use App\Modules\Security\Application\DTO\PermissionEntry;
use App\Modules\Security\Application\Services\PermissionService;
use App\Modules\Security\Domain\Authorization\PermissionMatrix;
use App\Modules\Security\Tests\Fakes\InMemoryPermissionUsageQuery;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the permission catalog use cases (RF-SEG-002,
 * ADR-27): the catalog answers to the PermissionMatrix (single
 * source of truth, code-owned), every entry decomposes into
 * module.action, the institutional holders come from the matrix
 * itself and the live projections (custom roles holding each
 * permission, effective accounts) come from the usage port.
 *
 * Runs without a database through the in-memory fake.
 */
final class PermissionServiceTest extends TestCase
{
    private InMemoryPermissionUsageQuery $usage;

    private PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usage = new InMemoryPermissionUsageQuery;
        $this->service = new PermissionService($this->usage);
    }

    public function test_lists_one_entry_per_catalog_permission_in_catalog_order(): void
    {
        $entries = $this->service->list();

        self::assertCount(count(PermissionMatrix::permissions()), $entries);
        self::assertSame(
            PermissionMatrix::permissions(),
            $entries
                ->map(fn (PermissionEntry $entry): string => $entry->name)
                ->all(),
        );
    }

    public function test_entries_decompose_the_module_action_convention(): void
    {
        $entry = $this->service->find('legalbases.manage');

        self::assertNotNull($entry);
        self::assertSame('legalbases', $entry->module);
        self::assertSame('manage', $entry->action);
    }

    public function test_institutional_holders_come_from_the_matrix(): void
    {
        $entry = $this->service->find('cases.view');

        self::assertNotNull($entry);
        self::assertSame(
            ['admin', 'director', 'specialist', 'operator', 'auditor'],
            $entry->institutionalRoles,
        );

        $adminOnly = $this->service->find('roles.manage');

        self::assertNotNull($adminOnly);
        self::assertSame(['admin'], $adminOnly->institutionalRoles);
    }

    public function test_custom_holders_and_effective_accounts_come_from_the_usage_port(): void
    {
        $this->usage->customRoles = [
            'people.view' => ['visor_provincial', 'supervisor_territorial'],
        ];
        $this->usage->usersCounts = [
            'people.view' => 7,
        ];

        $entry = $this->service->find('people.view');

        self::assertNotNull($entry);

        // The service orders the custom holders alphabetically: the
        // directory itself is name-ordered, so the projections agree.
        self::assertSame(
            ['supervisor_territorial', 'visor_provincial'],
            $entry->customRoles,
        );
        self::assertSame(7, $entry->usersCount);
    }

    public function test_entries_default_to_no_custom_holders_and_zero_accounts(): void
    {
        $entry = $this->service->find('settings.manage');

        self::assertNotNull($entry);
        self::assertSame([], $entry->customRoles);
        self::assertSame(0, $entry->usersCount);
    }

    public function test_unknown_usage_projections_never_leak_into_the_catalog(): void
    {
        // A stray pivot row for a permission that no longer exists in
        // the matrix must be ignored: the catalog is closed.
        $this->usage->customRoles = [
            'cases.approve' => ['phantom_role'],
        ];
        $this->usage->usersCounts = [
            'cases.approve' => 99,
        ];

        $entries = $this->service->list();

        self::assertSame(
            PermissionMatrix::permissions(),
            $entries
                ->map(fn (PermissionEntry $entry): string => $entry->name)
                ->values()
                ->all(),
        );
    }

    public function test_find_answers_null_for_an_unknown_permission(): void
    {
        self::assertNull($this->service->find('cases.approve'));
        self::assertNull($this->service->find('people'));
    }
}
