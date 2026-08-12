<?php

namespace Tests\Feature\StatusPage;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Jobs\TranslateStatusPageText;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\StatusPageTranslation;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\TranslationPayload;
use App\Services\Monitoring\IncidentWriteService;
use App\Services\Monitoring\ThresholdEvaluator;
use App\Support\StatusPages\TranslationOutputContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see TranslateStatusPageText}: what it stores, what it refuses to
 * store, which write paths enqueue it, and the paths that must never be able to.
 *
 * The model is never reached. `Ai::fakeAgent()` replaces the package's text
 * gateway for the anonymous agent this job prompts through, which is the one
 * seam between this application and a provider.
 */
class TranslateStatusPageTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The job refuses to call a provider it has no credential for, which is
        // what keeps the rest of the suite (where these write paths run inline on
        // the sync queue) off the network. The cases below are the ones that DO
        // exercise the call, so they configure one.
        config(['ai.providers.'.config('ai.default').'.key' => 'test-key']);
    }

    public function test_an_accepted_translation_is_stored_against_the_row(): void
    {
        Ai::fakeAgent(AnonymousAgent::class, ['İnceliyoruz.']);

        $update = $this->makePublicUpdate('We are investigating.');

        $this->jobFor($update, 'message')->handle();

        $translation = StatusPageTranslation::query()->firstOrFail();

        $this->assertSame('İnceliyoruz.', $translation->value);
        $this->assertSame('tr', $translation->locale);
        $this->assertSame('message', $translation->field);
        $this->assertSame(IncidentUpdate::class, $translation->translatable_type);
        $this->assertSame((string) $update->id, (string) $translation->translatable_id);
        // An IncidentUpdate carries no team_id of its own; it has to be resolved
        // through the incident or the denormalised tenant guard is a lie.
        $this->assertSame($update->incident->team_id, $translation->team_id);
        $this->assertSame(hash('sha256', 'We are investigating.'), $translation->source_hash);
        $this->assertNull($translation->rejected_at);
        $this->assertNull($translation->rejection_reason);
    }

    public function test_a_rejected_translation_stores_the_verdict_and_logs_without_the_text(): void
    {
        Log::spy();
        Ai::fakeAgent(AnonymousAgent::class, ['İnceliyoruz. Detay: https://evil.example/x']);

        $update = $this->makePublicUpdate('We are investigating.');

        $this->jobFor($update, 'message')->handle();

        $translation = StatusPageTranslation::query()->firstOrFail();

        $this->assertNull($translation->value);
        $this->assertNotNull($translation->rejected_at);
        $this->assertSame(TranslationOutputContract::REASON_FOREIGN_TOKEN, $translation->rejection_reason);

        // One warning, carrying the row identity and the machine reason. Both
        // argument positions are given: the real call is `warning($message,
        // $context)`, and a single-element expectation matches no call at all.
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($update): bool {
                return str_contains($message, 'rejected by the output contract')
                    && $context['reason'] === TranslationOutputContract::REASON_FOREIGN_TOKEN
                    && $context['translatable_id'] === (string) $update->id
                    && $context['locale'] === 'tr'
                    // The suspect string is what a reader would then paste
                    // somewhere. It is never in the line.
                    && ! str_contains(json_encode($context) ?: '', 'evil.example');
            })
            ->once();
    }

    /**
     * The 500-character cap this codebase already has for untrusted EVIDENCE
     * must not reach this path, where the fenced text is the payload itself.
     */
    public function test_a_two_thousand_character_update_is_translated_in_full(): void
    {
        $source = $this->filler(2000);
        $answer = $this->filler(2000, 'cd ');

        Ai::fakeAgent(AnonymousAgent::class, [$answer]);

        $update = $this->makePublicUpdate($source);

        $this->jobFor($update, 'message')->handle();

        // 1. The whole source reached the prompt: the assertion is on the LAST
        //    characters, because a cap anywhere in the path takes the tail first.
        $message = (new TranslationPayload($source, 'en', 'tr', 'message'))->buildUserMessage();
        $this->assertStringContainsString(mb_substr($source, -80), $message);
        Ai::assertAgentWasPrompted(
            AnonymousAgent::class,
            static fn ($prompt): bool => str_contains($prompt->prompt, mb_substr($source, -80)),
        );

        // 2. And the whole answer was stored.
        $this->assertSame(2000, mb_strlen((string) StatusPageTranslation::query()->firstOrFail()->value));
    }

    public function test_a_stored_verdict_for_the_same_source_costs_no_second_call(): void
    {
        $calls = 0;
        Ai::fakeAgent(AnonymousAgent::class, function () use (&$calls): string {
            $calls++;

            return 'İnceliyoruz.';
        });

        $update = $this->makePublicUpdate('We are investigating.');

        $this->jobFor($update, 'message')->handle();
        $this->jobFor($update, 'message')->handle();

        $this->assertSame(1, StatusPageTranslation::query()->count());
        // The second run answered from the stored source hash rather than the
        // model, which is what makes a retry and a double save free.
        $this->assertSame(1, $calls, 'A source that was already translated cost a second model call.');
    }

    public function test_an_edited_source_is_translated_again(): void
    {
        Ai::fakeAgent(AnonymousAgent::class, ['İnceliyoruz.', 'Çözüldü.']);

        $update = $this->makePublicUpdate('We are investigating.');
        $this->jobFor($update, 'message')->handle();

        $update->update(['message' => 'Resolved.']);
        $this->jobFor($update->refresh(), 'message')->handle();

        $translation = StatusPageTranslation::query()->firstOrFail();

        $this->assertSame('Çözüldü.', $translation->value);
        $this->assertSame(hash('sha256', 'Resolved.'), $translation->source_hash);
    }

    public function test_an_incident_with_a_title_key_enqueues_no_title_translation(): void
    {
        Queue::fake();

        [$monitor] = $this->makeMonitor();
        $keyed = $this->makeIncident($monitor, titleKey: 'incidents.titles.monitor_down');

        TranslateStatusPageText::fanOut($keyed, 'title', 'en');

        Queue::assertNotPushed(TranslateStatusPageText::class);

        // The other half, without which the assertion above would also pass on a
        // fan-out that never enqueues anything at all: the same call on an
        // operator-authored title (a null `title_key`) DOES enqueue.
        $authored = $this->makeIncident($monitor, titleKey: null);

        TranslateStatusPageText::fanOut($authored, 'title', 'en');

        Queue::assertPushed(
            TranslateStatusPageText::class,
            static fn (TranslateStatusPageText $job): bool => $job->field === 'title'
                && $job->targetLocale === 'tr'
                && $job->translatableType === Incident::class,
        );
        Queue::assertPushed(TranslateStatusPageText::class, 1);
    }

    public function test_an_internal_update_enqueues_nothing(): void
    {
        Queue::fake();

        $update = $this->makePublicUpdate('Internal note.', isPublic: false);

        TranslateStatusPageText::fanOut($update, 'message', 'en');

        Queue::assertNotPushed(TranslateStatusPageText::class);
    }

    public function test_creating_and_editing_a_status_page_description_enqueues_one_job_per_non_source_locale(): void
    {
        Queue::fake();

        $this->actingAsTeamMember();

        $created = $this->postJson('/api/v1/status-pages', [
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
            'description' => 'Live availability for the Acme platform.',
        ]);

        $created->assertStatus(201);
        $this->assertDescriptionJobsPushed(1);

        $page = StatusPage::findOrFail($created->json('data.id'));

        $this->putJson("/api/v1/status-pages/{$page->id}", [
            'description' => 'Live availability and planned work for the Acme platform.',
        ])->assertStatus(200);

        $this->assertDescriptionJobsPushed(2);
    }

    public function test_a_page_whose_own_language_is_turkish_fans_out_to_english_instead(): void
    {
        Queue::fake();

        $this->actingAsTeamMember();

        $this->postJson('/api/v1/status-pages', [
            'name' => 'Acme Status',
            'slug' => 'acme-status-tr',
            'is_public' => true,
            'locale' => 'tr',
            'description' => 'Acme platformunun canlı erişilebilirliği.',
        ])->assertStatus(201);

        Queue::assertPushed(
            TranslateStatusPageText::class,
            static fn (TranslateStatusPageText $job): bool => $job->sourceLocale === 'tr'
                && $job->targetLocale === 'en',
        );
        Queue::assertPushed(TranslateStatusPageText::class, 1);
    }

    public function test_planning_and_editing_a_maintenance_window_enqueues_its_title_and_description(): void
    {
        Queue::fake();

        $team = $this->actingAsTeamMember();

        // Built through the model rather than the API so the page's own
        // description fan-out cannot be mistaken for the window's.
        $page = StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Acme Status',
            'slug' => 'acme-maintenance',
            'is_public' => true,
        ]);

        $created = $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $page->id,
            'title' => 'Database upgrade',
            'description' => 'Rolling PostgreSQL 17 upgrade.',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
        ]);

        $created->assertStatus(201);
        $this->assertMaintenanceFieldsPushed(2);

        $windowId = $created->json('data.id');

        $this->putJson("/api/v1/scheduled-maintenances/{$windowId}", [
            'title' => 'Database upgrade, rescheduled',
        ])->assertStatus(200);

        // An edit re-fans BOTH fields: the description is unchanged but a
        // rewritten title has to reach the other languages the same way.
        $this->assertMaintenanceFieldsPushed(4);
    }

    public function test_an_operator_update_on_an_incident_enqueues_its_message(): void
    {
        Queue::fake();

        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor, titleKey: 'incidents.titles.monitor_down');

        $this->app->make(IncidentWriteService::class)->postUpdate(
            $incident,
            message: 'We have identified the cause and are rolling back.',
            author: 'Operator',
        );

        Queue::assertPushed(
            TranslateStatusPageText::class,
            static fn (TranslateStatusPageText $job): bool => $job->translatableType === IncidentUpdate::class
                && $job->field === 'message'
                && $job->targetLocale === 'tr',
        );
        Queue::assertPushed(TranslateStatusPageText::class, 1);
    }

    /**
     * The write path that does not look like incident authoring: without it an
     * auto-resolved incident sits at `pending` forever in every non-default
     * language, which is the moment its one sentence matters most.
     */
    public function test_the_auto_resolve_note_enqueues_a_translation(): void
    {
        Queue::fake();

        [$monitor] = $this->makeMonitor();
        $monitor->update([
            'last_status' => MonitorStatus::Up,
            'consecutive_fails' => 0,
        ]);
        $this->makeIncident($monitor, titleKey: 'incidents.titles.monitor_down');

        $resolved = $this->app->make(ThresholdEvaluator::class)->resolveIfRecovered($monitor);

        $this->assertNotNull($resolved);
        Queue::assertPushed(
            TranslateStatusPageText::class,
            static fn (TranslateStatusPageText $job): bool => $job->translatableType === IncidentUpdate::class
                && $job->field === 'message'
                && $job->sourceLocale === 'en'
                && $job->targetLocale === 'tr',
        );
        // Exactly one: the incident's own title carries a key, so the resolve
        // enqueues the note and nothing else.
        Queue::assertPushed(TranslateStatusPageText::class, 1);
    }

    /**
     * A public unauthenticated request must never be able to cause a model call,
     * so the dispatch surface is enumerated rather than asserted per route.
     *
     * The list is derived from the source and compared to a literal one: an
     * enumeration that found nothing would otherwise certify a tree it never
     * read, and a fifth caller appearing in a public controller, a view or a
     * route file is exactly the change this has to fail on.
     */
    public function test_only_the_four_authenticated_write_paths_dispatch_this_job(): void
    {
        $callers = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, 'TranslateStatusPageText::fanOut(')
                && ! str_contains($contents, 'TranslateStatusPageText::dispatch(')) {
                continue;
            }

            $callers[] = str_replace(app_path().'/', '', $file->getPathname());
        }

        sort($callers);

        // Four files, and the job's own is not among them: it dispatches through
        // `self::dispatch()` inside `fanOut()`, so the only way to reach the queue
        // from anywhere else is one of the two strings scanned above.
        $this->assertSame([
            'Http/Controllers/Api/V1/ScheduledMaintenanceController.php',
            'Http/Controllers/Api/V1/StatusPageController.php',
            'Services/Monitoring/IncidentWriteService.php',
            'Services/Monitoring/ThresholdEvaluator.php',
        ], $callers);

        // Neither the public status-page controllers nor any view may reach it.
        foreach (File::allFiles(resource_path('views/status')) as $view) {
            $this->assertStringNotContainsString(
                'TranslateStatusPageText',
                (string) file_get_contents($view->getPathname()),
            );
        }
    }

    public function test_the_job_makes_no_call_when_the_provider_has_no_credential(): void
    {
        config(['ai.providers.'.config('ai.default').'.key' => '']);
        Ai::fakeAgent(AnonymousAgent::class, ['İnceliyoruz.']);

        $update = $this->makePublicUpdate('We are investigating.');

        $this->jobFor($update, 'message')->handle();

        $this->assertSame(0, StatusPageTranslation::query()->count());
        Ai::assertAgentNeverPrompted(AnonymousAgent::class);
    }

    /**
     * The job for one row and field, into Turkish from the deployment default.
     */
    protected function jobFor(Incident|IncidentUpdate|StatusPage $row, string $field): TranslateStatusPageText
    {
        return new TranslateStatusPageText(
            $row->getMorphClass(),
            (string) $row->getKey(),
            $field,
            'en',
            'tr',
        );
    }

    /**
     * Assert the total number of description fan-out jobs pushed so far.
     */
    protected function assertDescriptionJobsPushed(int $expected): void
    {
        Queue::assertPushed(
            TranslateStatusPageText::class,
            static fn (TranslateStatusPageText $job): bool => $job->field === 'description'
                && $job->translatableType === StatusPage::class
                && $job->sourceLocale === 'en'
                && $job->targetLocale === 'tr',
        );
        Queue::assertPushed(TranslateStatusPageText::class, $expected);
    }

    /**
     * Assert the total number of maintenance fan-out jobs pushed so far, and
     * that both translated fields are among them.
     */
    protected function assertMaintenanceFieldsPushed(int $expected): void
    {
        foreach (['title', 'description'] as $field) {
            Queue::assertPushed(
                TranslateStatusPageText::class,
                static fn (TranslateStatusPageText $job): bool => $job->field === $field
                    && $job->translatableType === ScheduledMaintenance::class
                    && $job->sourceLocale === 'en'
                    && $job->targetLocale === 'tr',
            );
        }

        Queue::assertPushed(TranslateStatusPageText::class, $expected);
    }

    /**
     * Filler of an exact character length, carrying no token the output contract
     * would read as a URL, a host or a phone number.
     */
    protected function filler(int $length, string $unit = 'ab '): string
    {
        return mb_substr(str_repeat($unit, (int) ceil($length / 3) + 1), 0, $length);
    }

    /**
     * A monitor owned by a team, with its owner attached.
     *
     * @return array{0: Monitor, 1: Team}
     */
    protected function makeMonitor(): array
    {
        $user = User::factory()->create();

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Translation Team',
        ]);
        $team->users()->attach($user->id, ['role' => 'admin']);

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        return [$monitor, $team];
    }

    /**
     * An active incident for the monitor, with or without a catalogue title key.
     */
    protected function makeIncident(Monitor $monitor, ?string $titleKey): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => "{$monitor->name} is down",
            'title_key' => $titleKey,
            'title_params' => $titleKey === null ? null : ['monitor' => $monitor->name],
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now(),
        ]);
    }

    /**
     * A timeline update on a fresh incident.
     */
    protected function makePublicUpdate(string $message, bool $isPublic = true): IncidentUpdate
    {
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor, titleKey: 'incidents.titles.monitor_down');

        return $incident->updates()->create([
            'actor' => 'human',
            'author' => 'Operator',
            'status' => IncidentStatus::Investigating,
            'message' => $message,
            'is_public' => $isPublic,
            'autonomous' => false,
            'display_at' => now(),
        ]);
    }

    /**
     * A team whose owner is the acting Sanctum user.
     */
    protected function actingAsTeamMember(): Team
    {
        $user = User::factory()->create();

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme Ops '.Str::random(4),
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }
}
