<?php

namespace Tests\Feature\StatusPage;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Http\ViewModels\StatusPageViewModel;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\StatusPageTranslation;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentTitle;
use App\Services\StatusPages\StatusPageAssembler;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The assembler resolves the six operator-authored free-text fields through the
 * translation store, and says where each rendered value came from.
 *
 * Three properties are asserted here and nowhere else:
 *
 *   - The existing key still holds a SCALAR. The layout puts
 *     `page['description']` straight into `og:description` and
 *     `<meta name="description">`, so an array arriving there is a fatal on every
 *     render. The original and the provenance travel as SIBLING keys beside it,
 *     which is what lets every existing reader stay untouched.
 *   - Provenance distinguishes the three ways a field can lack a translation.
 *     `authored` (this IS the source language), `pending` (no row yet) and
 *     `unavailable` (a row the output contract rejected, which nothing
 *     re-queues) are different promises to the reader, and a boolean would make
 *     the last two indistinguishable.
 *   - The lookup is ONE query for the whole page. The six call sites sit inside
 *     three nested loops, so a per-field resolve would put dozens of queries
 *     inside a 60-second cache fill that runs on a visitor's request. That is
 *     asserted by counting, not by reading the code.
 *
 * The source-locale rule the provenance rests on has to match the write side
 * exactly: no incident, update or maintenance row carries a language column, so
 * `TranslateStatusPageText` fans those out from `app.default_locale` and writes
 * no row for that language, while the page description fans out from the page's
 * own `locale`. Both halves of that rule are asserted below, because a read side
 * that disagreed with the write side would render `pending` against a row
 * nothing will ever queue.
 *
 * The fixtures are local rather than reused from `StatusPageLocaleTest`: those
 * helpers are protected methods on a test class, so reaching them means either
 * extending it (which re-runs its whole suite under this class name) or editing
 * it to extract a trait. Every other status-page test file carries its own
 * fixtures for the same reason.
 */
class StatusPageTranslationRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_page_renders_one_original_in_two_languages(): void
    {
        $page = $this->makeTranslatablePage('translation-two-languages');
        $incident = $this->humanAuthoredIncident($page);

        $this->storeTranslation($page, $incident, 'title', 'tr', 'Ödeme akışı yavaş.');

        $english = $this->firstIncidentEntry($this->assembleUnder('en', $page));
        $turkish = $this->firstIncidentEntry($this->assembleUnder('tr', $page));

        // The shown value differs per language; the original is the operator's
        // one sentence and is identical in both, which is what the page offers
        // behind the `<details>`.
        $this->assertSame('The checkout flow is slow.', $english['title']);
        $this->assertSame('Ödeme akışı yavaş.', $turkish['title']);
        $this->assertSame('The checkout flow is slow.', $english['title_original']);
        $this->assertSame('The checkout flow is slow.', $turkish['title_original']);

        $this->assertSame(StatusPageAssembler::PROVENANCE_AUTHORED, $english['title_provenance']);
        $this->assertSame(StatusPageAssembler::PROVENANCE_TRANSLATED, $turkish['title_provenance']);
    }

    public function test_the_four_provenance_states_resolve_across_one_turkish_render(): void
    {
        // One render carrying all four states at once, because they are decided
        // by four different inputs: a stored translation, a stored rejection, no
        // row at all, and a title that is composed from a catalogue key and is
        // therefore already in the language being rendered.
        $page = $this->makeTranslatablePage('translation-four-states');
        $incident = $this->firstIncident($page);
        $update = $incident->updates()->firstOrFail();

        $this->storeTranslation($page, $update, 'message', 'tr', 'Bir bölgede yavaşlama görüyoruz.');
        $this->storeRejection($page, $incident, 'postmortem_body', 'tr', 'foreign_token');

        $viewModel = $this->assembleUnder('tr', $page);
        $entry = $this->firstIncidentEntry($viewModel);

        $this->assertSame(
            StatusPageAssembler::PROVENANCE_TRANSLATED,
            $entry['updates'][0]['message_provenance'],
        );
        $this->assertSame('Bir bölgede yavaşlama görüyoruz.', $entry['updates'][0]['message']);

        // A rejection is a recorded verdict and nothing re-queues it, so the page
        // says "unavailable" rather than "in progress" and shows the original.
        $this->assertSame(
            StatusPageAssembler::PROVENANCE_UNAVAILABLE,
            $entry['postmortem']['body_provenance'],
        );
        $this->assertSame(self::POSTMORTEM_BODY, $entry['postmortem']['body']);
        $this->assertSame(self::POSTMORTEM_BODY, $entry['postmortem']['body_original']);

        // No row was ever written for the window, so the field is still queued.
        $this->assertSame(
            StatusPageAssembler::PROVENANCE_PENDING,
            $viewModel->maintenances[0]['title_provenance'],
        );
        $this->assertSame(self::WINDOW_TITLE, $viewModel->maintenances[0]['title']);
        $this->assertSame(self::WINDOW_TITLE, $viewModel->maintenances[0]['title_original']);

        // An auto-generated title is rendered from the catalogue into the ambient
        // locale, so it is authored in EVERY language and the write side queues
        // it nothing. Anything else would leave it `pending` forever.
        $this->assertNotNull($incident->title_key);
        $this->assertSame(StatusPageAssembler::PROVENANCE_AUTHORED, $entry['title_provenance']);
    }

    public function test_every_translated_key_still_holds_a_scalar(): void
    {
        // The contract that keeps this step from breaking readers it does not
        // own: `page['description']` reaches `og:description` and
        // `<meta name="description">` as an attribute value, and the render and
        // maintenance suites assert these six as strings.
        $page = $this->makeTranslatablePage('translation-scalar-keys', locale: 'en', description: 'Live service status.');
        $incident = $this->firstIncident($page);

        $this->storeTranslation($page, $incident->updates()->firstOrFail(), 'message', 'tr', 'Yavaşlama.');

        $viewModel = $this->assembleUnder('tr', $page);
        $entry = $this->firstIncidentEntry($viewModel);

        $this->assertIsString($viewModel->page['description']);
        $this->assertIsString($viewModel->maintenances[0]['title']);
        $this->assertNull($viewModel->maintenances[0]['description']);
        $this->assertIsString($entry['title']);
        $this->assertIsString($entry['postmortem']['body']);
        $this->assertIsString($entry['updates'][0]['message']);
    }

    public function test_a_page_description_is_authored_in_the_page_s_own_language(): void
    {
        // The one field with a language column of its own. Its source is the
        // page's `locale`, not the deployment default, on BOTH ends: the write
        // path fans out from the same expression, so a Turkish page's Turkish
        // description must not read `pending` on the language it was written in.
        $page = $this->makeTranslatablePage(
            'translation-page-locale',
            locale: 'tr',
            description: 'Hizmet durumu.',
        );

        $turkish = $this->assembleUnder('tr', $page);
        $english = $this->assembleUnder('en', $page);

        $this->assertSame(
            StatusPageAssembler::PROVENANCE_AUTHORED,
            $turkish->page['description_provenance'],
        );
        $this->assertSame('Hizmet durumu.', $turkish->page['description']);

        $this->assertSame(
            StatusPageAssembler::PROVENANCE_PENDING,
            $english->page['description_provenance'],
        );
        $this->assertSame('Hizmet durumu.', $english->page['description']);
    }

    public function test_an_empty_field_is_authored_rather_than_pending(): void
    {
        // A blank field is never queued (`TranslateStatusPageText::shouldTranslate()`
        // refuses it), so resolving it as `pending` would promise a translation
        // that is never coming and put a "translation in progress" note under an
        // empty window description on every non-default language.
        $page = $this->makeTranslatablePage('translation-empty-field');

        $viewModel = $this->assembleUnder('tr', $page);

        $this->assertNull($viewModel->page['description']);
        $this->assertSame(
            StatusPageAssembler::PROVENANCE_AUTHORED,
            $viewModel->page['description_provenance'],
        );
        $this->assertSame(
            StatusPageAssembler::PROVENANCE_AUTHORED,
            $viewModel->maintenances[0]['description_provenance'],
        );
    }

    public function test_the_translation_lookup_does_not_scale_with_the_incident_count(): void
    {
        // The measurement this step exists to get right. Resolving per field per
        // row is the naive shape and it is invisible in a fixture with one
        // incident: it only shows up as a page with three incidents costing
        // three times the queries, inside a cache fill on a visitor's request.
        // So the assertion is that the two counts are EQUAL, not that either is
        // small.
        $one = $this->makeTranslatablePage('translation-queries-one');

        $three = $this->makeTranslatablePage('translation-queries-three');
        $monitor = $three->monitors()->firstOrFail();
        $team = $three->team()->firstOrFail();
        // Each carries a public update of its own, both because that is what
        // makes an incident reach the page at all and because it is the second
        // row per incident the naive per-row resolve would query for.
        $this->seedPublicUpdate($this->seedIncident($team, $monitor));
        $this->seedPublicUpdate($this->seedIncident($team, $monitor));

        $oneQueries = $this->countQueries(fn () => $this->assembleUnder('tr', $one));
        $threeQueries = $this->countQueries(fn () => $this->assembleUnder('tr', $three));

        $this->assertCount(3, $this->assembleUnder('tr', $three)->incidents[0]['entries']);
        $this->assertSame(
            $oneQueries,
            $threeQueries,
            'Assembling three incidents cost more queries than assembling one: the translations are resolved per row.',
        );

        // And the store itself is read exactly once for the whole page.
        $translationQueries = $this->countQueries(
            fn () => $this->assembleUnder('tr', $three),
            'status_page_translations',
        );
        $this->assertSame(1, $translationQueries);
    }

    public function test_the_source_language_render_reads_the_translation_store_at_all(): void
    {
        // Every field on a default-language page is `authored`, so no stored row
        // could apply and the lookup is skipped outright. This is most of this
        // surface's traffic, and it costs nothing.
        $page = $this->makeTranslatablePage('translation-source-render');

        $queries = $this->countQueries(
            fn () => $this->assembleUnder('en', $page),
            'status_page_translations',
        );

        $this->assertSame(0, $queries);
    }

    /**
     * The maintenance window's title, asserted as the untranslated original.
     */
    protected const string WINDOW_TITLE = 'Upgrading the payments database';

    /**
     * The published postmortem body, asserted as the untranslated original.
     */
    protected const string POSTMORTEM_BODY = 'The connection pool was resized.';

    /**
     * Assemble the page under an explicit language.
     *
     * The assembler reads the AMBIENT locale, exactly as it does for the banner
     * label and an auto-generated incident title, so the language is applied the
     * way `ShowStatusPageController` applies it rather than passed as an
     * argument.
     */
    protected function assembleUnder(string $locale, StatusPage $page): StatusPageViewModel
    {
        App::setLocale($locale);

        return (new StatusPageAssembler)->build($page->fresh());
    }

    /**
     * Count the queries one call runs, optionally only those naming a table.
     *
     * @param  string|null  $table  Restricts the count to statements mentioning it.
     */
    protected function countQueries(callable $callback, ?string $table = null): int
    {
        $count = 0;

        DB::listen(function (QueryExecuted $query) use (&$count, $table): void {
            if ($table === null || str_contains($query->sql, $table)) {
                $count++;
            }
        });

        $callback();

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    protected function firstIncidentEntry(StatusPageViewModel $viewModel): array
    {
        return $viewModel->incidents[0]['entries'][0];
    }

    protected function firstIncident(StatusPage $page): Incident
    {
        return Incident::query()->where('team_id', $page->team_id)->orderBy('created_at')->firstOrFail();
    }

    /**
     * Rewrite the fixture's incident as one a HUMAN titled.
     *
     * `title_key IS NULL` is this codebase's test for "a human wrote this", and
     * it is the only shape whose title is translated at all.
     */
    protected function humanAuthoredIncident(StatusPage $page): Incident
    {
        $incident = $this->firstIncident($page);

        $incident->forceFill([
            'title' => 'The checkout flow is slow.',
            'title_key' => null,
            'title_params' => null,
        ])->save();

        return $incident;
    }

    /**
     * A public page carrying one published incident with a public update and a
     * published postmortem, plus one open maintenance window: four of the six
     * translated fields on one page, in the language `app.default_locale` names.
     */
    protected function makeTranslatablePage(
        string $slug,
        ?string $locale = null,
        ?string $description = null,
    ): StatusPage {
        $team = $this->makeTeam();

        $page = StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'description' => $description,
            'is_public' => true,
            'subscriptions_enabled' => true,
            'locale' => $locale,
        ]);

        $monitor = $this->makeMonitor($team);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $incident = $this->seedIncident($team, $monitor);
        $incident->forceFill([
            'postmortem_body' => self::POSTMORTEM_BODY,
            'postmortem_published_at' => CarbonImmutable::now(),
        ])->save();

        $this->seedPublicUpdate($incident);

        $window = ScheduledMaintenance::factory()->create([
            'team_id' => $team->id,
            'status_page_id' => $page->id,
            'title' => self::WINDOW_TITLE,
            'description' => null,
            'starts_at' => CarbonImmutable::now()->subMinutes(30),
            'ends_at' => CarbonImmutable::now()->addMinutes(30),
        ]);
        $window->monitors()->attach([$monitor->id]);

        return $page;
    }

    /**
     * An incident carrying the STRUCTURED title an evaluator writes: the English
     * render in `title`, the catalogue key in `title_key`, the display-ready
     * values in `title_params`.
     */
    protected function seedIncident(Team $team, Monitor $monitor): Incident
    {
        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            ...IncidentTitle::compose(IncidentTitle::MONITOR_DOWN, ['monitor' => $monitor->name]),
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Resolved,
            'ai_owned' => false,
            'started_at' => CarbonImmutable::now(),
            'resolved_at' => CarbonImmutable::now(),
        ]);

        $incident->monitors()->attach([
            $monitor->id => [
                'component_status_at_start' => 'degraded',
                'component_status_current' => 'degraded',
            ],
        ]);

        return $incident;
    }

    /**
     * The one public update that both carries a translated `message` and makes
     * its incident reach the page: a resolved incident with no public update and
     * no published postmortem is filtered out of the read model entirely.
     */
    protected function seedPublicUpdate(Incident $incident): IncidentUpdate
    {
        return IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'message' => 'We are seeing elevated latency in one region.',
            'actor' => 'human',
            'status' => IncidentStatus::Resolved,
            'is_public' => true,
            'autonomous' => false,
            'display_at' => CarbonImmutable::now(),
        ]);
    }

    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Translation Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Translation Team',
        ]);
    }

    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Checkout API',
            'type' => MonitorType::Http,
            'url' => 'https://secret-internal-host.example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Degraded,
        ]);
    }

    /**
     * An accepted translation, written the way the job writes one.
     */
    protected function storeTranslation(
        StatusPage $page,
        Incident|IncidentUpdate|ScheduledMaintenance|StatusPage $row,
        string $field,
        string $locale,
        string $value,
    ): StatusPageTranslation {
        return StatusPageTranslation::query()->create([
            'team_id' => $page->team_id,
            'translatable_type' => $row->getMorphClass(),
            'translatable_id' => $row->getKey(),
            'field' => $field,
            'locale' => $locale,
            'value' => $value,
            'source_hash' => hash('sha256', (string) $row->getAttribute($field)),
        ]);
    }

    /**
     * A REJECTED translation: the suspect text is never stored, only the fact of
     * the rejection and its machine reason.
     */
    protected function storeRejection(
        StatusPage $page,
        Incident|IncidentUpdate|ScheduledMaintenance|StatusPage $row,
        string $field,
        string $locale,
        string $reason,
    ): StatusPageTranslation {
        return StatusPageTranslation::query()->create([
            'team_id' => $page->team_id,
            'translatable_type' => $row->getMorphClass(),
            'translatable_id' => $row->getKey(),
            'field' => $field,
            'locale' => $locale,
            'value' => null,
            'source_hash' => hash('sha256', (string) $row->getAttribute($field)),
            'rejected_at' => CarbonImmutable::now(),
            'rejection_reason' => $reason,
        ]);
    }
}
