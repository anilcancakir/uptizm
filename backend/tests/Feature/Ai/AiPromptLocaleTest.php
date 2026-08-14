<?php

namespace Tests\Feature\Ai;

use App\Enums\IncidentDraftKind;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\IncidentAnalysisService;
use App\Services\Ai\IncidentDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The AI surface writes in the operator's language.
 *
 * It did not, and the two halves failed for different reasons, which is why both
 * are pinned here. The ANALYSIS had no locale parameter anywhere in its path, so
 * it was English by construction. The DRAFT had the parameter threaded all the
 * way through and interpolated the raw locale CODE into the prompt, which
 * `PromptLanguage`'s own docblock calls the weak instruction: "write in tr" is a
 * token a model may or may not resolve, "write in Turkish" is not.
 *
 * Measured against the live provider before the fix: a Turkish operator's
 * incident analysis came back English, and so did a draft requested with
 * `Accept-Language: tr`.
 */
class AiPromptLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_analysis_prompt_asks_for_the_teams_language(): void
    {
        $incident = $this->makeIncident($this->makeTeam('tr'));

        $message = $this->analysisMessage($incident);

        $this->assertStringContainsString('Turkish', $message);
        $this->assertStringNotContainsString('in tr.', $message);
    }

    public function test_the_analysis_prompt_says_english_for_an_english_team(): void
    {
        // The other side of the branch, so a hardcoded 'Turkish' cannot pass.
        $incident = $this->makeIncident($this->makeTeam('en'));

        $message = $this->analysisMessage($incident);

        $this->assertStringContainsString('English', $message);
        $this->assertStringNotContainsString('Turkish', $message);
    }

    public function test_the_draft_prompt_names_the_language_rather_than_its_code(): void
    {
        $incident = $this->makeIncident($this->makeTeam('tr'));

        $message = $this->draftMessage($incident, 'tr');

        $this->assertStringContainsString('Turkish', $message);
        $this->assertStringNotContainsString(' tr.', $message);
    }

    public function test_an_unsupported_locale_asks_for_english_rather_than_the_tag(): void
    {
        // A language the product does not ship has no catalogue and nobody to
        // review what comes back, so the prompt must not pass the tag through
        // and hope. `PromptLanguage` closes to the shipped list for this reason.
        $incident = $this->makeIncident($this->makeTeam('en'));

        $message = $this->draftMessage($incident, 'de');

        $this->assertStringContainsString('English', $message);
        $this->assertStringNotContainsString(' de.', $message);
    }

    public function test_a_team_reads_in_its_owners_language(): void
    {
        // There is no `teams.locale` column and this deliberately does not add
        // one: a team's language is its owner's until somebody asks for a
        // per-team override, and the owner already chose one in settings.
        $this->assertSame('tr', $this->makeTeam('tr')->preferredLocale());
    }

    public function test_a_team_whose_owner_stored_nothing_falls_back(): void
    {
        // Empty, not null: `users.locale` is NOT NULL with an `'en'` default, so
        // empty is the value that actually arrives, and `??` would hand a model
        // an empty language.
        $team = $this->makeTeam('');

        $this->assertSame(config('app.locale'), $team->preferredLocale());
    }

    /**
     * The analysis prompt, read through a closure bound to the service.
     *
     * `composePayload()` is protected on both services and stays that way: this
     * assertion is about the prompt a private method builds, and widening
     * production visibility to reach it would be the test changing the code it
     * tests. Same binding trick as `MonitorSchemaDefaultsTest`.
     */
    protected function analysisMessage(Incident $incident): string
    {
        return (fn (): string => $this->composePayload($incident)->buildUserMessage())
            ->call(app(IncidentAnalysisService::class));
    }

    /**
     * The update-draft prompt for a given locale, read the same way.
     */
    protected function draftMessage(Incident $incident, string $locale): string
    {
        return (fn (): string => $this->composePayload(
            $incident,
            IncidentDraftKind::Update,
            $locale,
            'investigating',
        )->buildUserMessage())->call(app(IncidentDraftService::class));
    }

    protected function makeTeam(string $locale): Team
    {
        $user = User::query()->create([
            'name' => 'Prompt Locale Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
            'locale' => $locale,
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Prompt Locale Team',
            'plan' => 'pro',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $team;
    }

    protected function makeIncident(Team $team): Incident
    {
        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        return Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now()->subMinutes(5),
        ]);
    }
}
