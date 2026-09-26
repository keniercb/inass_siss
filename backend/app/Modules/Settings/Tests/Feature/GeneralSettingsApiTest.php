<?php

declare(strict_types=1);

namespace App\Modules\Settings\Tests\Feature;

use App\Modules\Security\Infrastructure\Persistence\Models\User;
use App\Modules\Settings\Infrastructure\Persistence\Models\GeneralSetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Versioned general settings /api/v1/general-settings (RF-CAT-005,
 * RN-007): immutable versions with UNIQUE effective_from (no overlap,
 * guaranteed in the database like every natural key of RN-008), the
 * domain resolution of the version in force at a given date and the
 * deletion guard that protects history.
 */
final class GeneralSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'min_work_years' => 25,
            'min_age_men' => 60,
            'min_age_women' => 55,
            'base_calc_percent' => 50,
            'max_calc_percent' => 90,
            'annual_increase_percent' => 1,
            'effective_from' => '2023-01-01',
        ], $overrides);
    }

    private function seedTimeline(): void
    {
        foreach ([
            ['effective_from' => '2020-01-01', 'min_work_years' => 25],
            ['effective_from' => '2023-01-01', 'min_work_years' => 27],
            ['effective_from' => '2026-06-01', 'min_work_years' => 30],
        ] as $overrides) {
            GeneralSetting::query()->create($this->payload($overrides));
        }
    }

    public function test_requires_authentication(): void
    {
        auth()->logout();

        $this->postJson('/api/v1/general-settings', $this->payload())
            ->assertUnauthorized();
    }

    public function test_stores_a_new_version_with_authorship(): void
    {
        $this->postJson('/api/v1/general-settings', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.min_work_years', 25)
            ->assertJsonPath('data.min_age_men', 60)
            ->assertJsonPath('data.min_age_women', 55)
            ->assertJsonPath('data.base_calc_percent', 50)
            ->assertJsonPath('data.max_calc_percent', 90)
            ->assertJsonPath('data.annual_increase_percent', 1)
            ->assertJsonPath('data.effective_from', '2023-01-01')
            ->assertJsonPath('data.effective_to', null)
            ->assertJsonPath('data.created_by', $this->user->id);

        $this->assertDatabaseHas('general_settings', [
            'effective_from' => '2023-01-01',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_validates_the_parameter_ranges(): void
    {
        $this->postJson('/api/v1/general-settings', $this->payload([
            'min_work_years' => -1,
            'min_age_men' => 200,
            'base_calc_percent' => 150,
            'max_calc_percent' => -5,
            'annual_increase_percent' => 101,
            'effective_from' => 'not-a-date',
        ]))->assertUnprocessable()->assertInvalid([
            'min_work_years',
            'min_age_men',
            'base_calc_percent',
            'max_calc_percent',
            'annual_increase_percent',
            'effective_from',
        ]);
    }

    public function test_rejects_a_max_percent_below_the_base_percent(): void
    {
        $this->postJson('/api/v1/general-settings', $this->payload([
            'base_calc_percent' => 80,
            'max_calc_percent' => 70,
        ]))->assertUnprocessable()->assertInvalid(['max_calc_percent']);
    }

    public function test_rejects_a_duplicate_effective_from_with_a_semantic_422(): void
    {
        GeneralSetting::query()->create($this->payload());

        $this->postJson('/api/v1/general-settings', $this->payload())
            ->assertUnprocessable()
            ->assertInvalid(['effective_from']);
    }

    public function test_the_database_backstops_overlap_after_the_unique_constraint(): void
    {
        GeneralSetting::query()->create($this->payload());

        $insert = fn () => DB::table('general_settings')->insert($this->payload([
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        expect($insert)->toThrow(QueryException::class);
    }

    public function test_the_database_backstops_the_percent_check_constraint(): void
    {
        $insert = fn () => DB::table('general_settings')->insert($this->payload([
            'base_calc_percent' => 85,
            'max_calc_percent' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        expect($insert)->toThrow(QueryException::class);
    }

    public function test_lists_versions_from_newest_to_oldest_with_the_derived_effective_to(): void
    {
        $this->seedTimeline();

        $this->getJson('/api/v1/general-settings')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.effective_from', '2026-06-01')
            ->assertJsonPath('data.0.effective_to', null)
            ->assertJsonPath('data.1.effective_from', '2023-01-01')
            ->assertJsonPath('data.1.effective_to', '2026-05-31')
            ->assertJsonPath('data.2.effective_from', '2020-01-01')
            ->assertJsonPath('data.2.effective_to', '2022-12-31');
    }

    public function test_shows_one_version_with_its_derived_effective_to(): void
    {
        $this->seedTimeline();

        $version = GeneralSetting::query()
            ->where('effective_from', '2023-01-01')
            ->firstOrFail();

        $this->getJson("/api/v1/general-settings/{$version->id}")
            ->assertOk()
            ->assertJsonPath('data.effective_from', '2023-01-01')
            ->assertJsonPath('data.effective_to', '2026-05-31')
            ->assertJsonPath('data.min_work_years', 27);

        $this->getJson('/api/v1/general-settings/999999')
            ->assertNotFound();
    }

    public function test_resolves_the_version_in_force_today(): void
    {
        $this->seedTimeline();

        $this->getJson('/api/v1/general-settings/current')
            ->assertOk()
            ->assertJsonPath('data.effective_from', '2026-06-01')
            ->assertJsonPath('data.min_work_years', 30);
    }

    /**
     * @dataProvider datesResolveTo
     *
     * @param  array<string, mixed>  $timeline
     */
    public function test_resolves_the_version_in_force_at_a_given_date(array $timeline, string $at, ?string $expected): void
    {
        foreach ($timeline as $effectiveFrom) {
            GeneralSetting::query()->create($this->payload(['effective_from' => $effectiveFrom]));
        }

        $response = $this->getJson('/api/v1/general-settings/current?at='.$at);

        if ($expected === null) {
            $response->assertNotFound();

            return;
        }

        $response->assertOk()->assertJsonPath('data.effective_from', $expected);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: string|null}>
     */
    public static function datesResolveTo(): array
    {
        $timeline = ['2020-01-01', '2023-01-01', '2026-06-01'];

        return [
            'before the first version' => [$timeline, '2019-12-31', null],
            'first version day' => [$timeline, '2020-01-01', '2020-01-01'],
            'between versions' => [$timeline, '2024-08-15', '2023-01-01'],
            'last day of a version' => [$timeline, '2026-05-31', '2023-01-01'],
            'first day of the newest version' => [$timeline, '2026-06-01', '2026-06-01'],
            'after the newest version' => [$timeline, '2031-01-01', '2026-06-01'],
        ];
    }

    public function test_rejects_a_malformed_at_parameter(): void
    {
        $this->getJson('/api/v1/general-settings/current?at=yesterday')
            ->assertUnprocessable();
    }

    public function test_versions_are_immutable_at_http_level(): void
    {
        GeneralSetting::query()->create($this->payload());

        $version = GeneralSetting::query()->firstOrFail();

        $this->patchJson("/api/v1/general-settings/{$version->id}", $this->payload([
            'min_work_years' => 99,
        ]))->assertStatus(405);
    }

    public function test_deletes_only_versions_not_yet_in_force(): void
    {
        $this->seedTimeline();
        GeneralSetting::query()->create($this->payload([
            'effective_from' => '2027-01-01',
            'min_work_years' => 35,
        ]));

        $future = GeneralSetting::query()->where('effective_from', '2027-01-01')->firstOrFail();
        $current = GeneralSetting::query()->where('effective_from', '2026-06-01')->firstOrFail();
        $past = GeneralSetting::query()->where('effective_from', '2020-01-01')->firstOrFail();

        $this->deleteJson("/api/v1/general-settings/{$future->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Future settings version deleted.');

        $this->assertDatabaseMissing('general_settings', ['id' => $future->id]);

        $this->deleteJson("/api/v1/general-settings/{$current->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'The version effective from 2026-06-01 is already in effect and cannot be deleted.',
            );

        $this->deleteJson("/api/v1/general-settings/{$past->id}")
            ->assertConflict();

        $this->assertDatabaseHas('general_settings', ['id' => $current->id]);
        $this->assertDatabaseHas('general_settings', ['id' => $past->id]);

        $this->deleteJson('/api/v1/general-settings/999999')
            ->assertNotFound();
    }
}
