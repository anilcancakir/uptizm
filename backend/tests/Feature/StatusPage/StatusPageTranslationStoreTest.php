<?php

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;
use App\Models\StatusPageTranslation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the `status_page_translations` table and {@see StatusPageTranslation}
 * model: the migration round-trips on the configured database engine, the
 * unique key is the morph pair plus `field` plus `locale` (never a content
 * hash), and the model exposes the pieces later steps read (the datetime cast
 * on `rejected_at`, the morph relation to the translated row).
 */
class StatusPageTranslationStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_creates_and_drops_the_table(): void
    {
        // RefreshDatabase already ran the migration for this test; assert the
        // shape it left behind, then prove `down()` is not a no-op by rolling
        // it back and re-running it.
        $this->assertTrue(Schema::hasTable('status_page_translations'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'team_id'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'translatable_type'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'translatable_id'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'field'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'locale'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'value'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'source_hash'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'rejected_at'));
        $this->assertTrue(Schema::hasColumn('status_page_translations', 'rejection_reason'));

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('status_page_translations'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('status_page_translations'));
    }

    public function test_a_second_row_for_the_same_translatable_field_and_locale_is_rejected(): void
    {
        $page = $this->makePage();

        StatusPageTranslation::create($this->rowFor($page, 'en'));

        // A unique-constraint violation raised mid-transaction needs a
        // SAVEPOINT on PostgreSQL (SQLite does not surface the distinction),
        // which is exactly what this step's QA calls out: run this test
        // against both engines, not just the default SQLite one.
        $this->assertThrows(
            function () use ($page): void {
                StatusPageTranslation::create($this->rowFor($page, 'en'));
            },
            fn (\Throwable $exception): bool => $exception instanceof UniqueConstraintViolationException
                || $exception instanceof QueryException,
        );
    }

    public function test_the_same_field_in_a_different_locale_is_a_distinct_row(): void
    {
        $page = $this->makePage();

        StatusPageTranslation::create($this->rowFor($page, 'en'));
        $turkish = StatusPageTranslation::create($this->rowFor($page, 'tr'));

        $this->assertTrue($turkish->exists);
        $this->assertSame(2, StatusPageTranslation::count());
    }

    public function test_rejected_at_casts_to_a_datetime_and_the_morph_relation_resolves(): void
    {
        $page = $this->makePage();

        $translation = StatusPageTranslation::create([
            ...$this->rowFor($page, 'tr'),
            'value' => null,
            'rejected_at' => now(),
            'rejection_reason' => 'length_ratio_exceeded',
        ]);

        $this->assertInstanceOf(Carbon::class, $translation->rejected_at);
        $this->assertTrue($translation->translatable->is($page));
    }

    /**
     * A persisted status page, standing in for any morphable row this table
     * addresses (Incident, IncidentUpdate, Maintenance, StatusPage).
     */
    protected function makePage(): StatusPage
    {
        $team = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        return StatusPage::create([
            'team_id' => $team->id,
            'name' => 'Acme Status',
            'slug' => 'acme',
            'is_public' => true,
        ]);
    }

    /**
     * The row attributes for one (field, locale) translation of the given page.
     *
     * @return array<string, mixed>
     */
    protected function rowFor(StatusPage $page, string $locale): array
    {
        return [
            'team_id' => $page->team_id,
            'translatable_type' => StatusPage::class,
            'translatable_id' => $page->id,
            'field' => 'description',
            'locale' => $locale,
            'value' => 'Translated description.',
            'source_hash' => hash('sha256', 'Original description.'),
        ];
    }
}
