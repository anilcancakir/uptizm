<?php

namespace Tests\Feature\Http;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentTitle;
use App\Support\SearchText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An operator searches for the words on their screen.
 *
 * That sentence is the whole specification, and the roster used to fail it in
 * two separate ways. An automatically opened incident stores its title in
 * ENGLISH and renders it from `title_key` + `title_params` in the reader's
 * language, so a Turkish operator was searching a sentence the column had never
 * held. And `LOWER` is not the Turkish casing rule, so even an English title
 * carrying `İ` was unmatchable by the word somebody typed.
 *
 * Both are answered by one folded column. These tests are written against the
 * HTTP surface rather than the model because the folding is only correct when
 * BOTH sides use it, and a model test would prove one half.
 *
 * @see SearchText
 * @see Incident::composeSearchText()
 */
class IncidentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_turkish_title_is_found_by_its_unaccented_spelling(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Edge');
        $this->makeIncident($team, $monitor, 'İstanbul CDN kesintisi');
        $this->makeIncident($team, $monitor, 'Frankfurt CDN outage');

        // `mb_strtolower('İ')` is `i` plus a COMBINING DOT ABOVE, so a LOWER on
        // both sides leaves these two strings unequal and this search found
        // nothing at all.
        $this->getJson('/api/v1/incidents?q=istanbul')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'İstanbul CDN kesintisi');
    }

    public function test_a_dotted_capital_i_still_finds_the_lowercase_title(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Edge');
        $this->makeIncident($team, $monitor, 'istanbul pop is flapping');

        // The reverse direction, which a fold that only special-cased the
        // stored side would fail.
        $this->getJson('/api/v1/incidents?q=İSTANBUL')
            ->assertJsonCount(1, 'data');
    }

    public function test_an_automatic_incident_is_found_by_the_words_a_turkish_reader_sees(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Checkout');

        // Exactly the shape the monitoring core writes: English in the column,
        // structure in the key and params. The Turkish catalogue renders
        // `monitor_down` as ":monitor kesintide", a word that appears NOWHERE
        // in the stored row.
        $this->makeAutomaticIncident($team, $monitor, 'Checkout is down', IncidentTitle::MONITOR_DOWN, [
            'monitor' => 'Checkout',
        ]);
        $this->makeIncident($team, $monitor, 'Unrelated manual note');

        $this->getJson('/api/v1/incidents?q=kesintide')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title_key', IncidentTitle::MONITOR_DOWN);
    }

    public function test_the_same_incident_is_still_found_in_english(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->makeAutomaticIncident($team, $monitor, 'Checkout is down', IncidentTitle::MONITOR_DOWN, [
            'monitor' => 'Checkout',
        ]);

        // Carrying every locale at once means neither language wins: an English
        // reader and a Turkish one search the same row and both find it.
        $this->getJson('/api/v1/incidents?q=is down')
            ->assertJsonCount(1, 'data');
    }

    public function test_an_incident_is_found_by_its_monitor_name(): void
    {
        $team = $this->actingAsTeamMember();
        $checkout = $this->makeMonitor($team, 'Checkout API');
        $marketing = $this->makeMonitor($team, 'Marketing site');

        // Titles that name no monitor, which is what a hand-written incident
        // usually looks like. The client filtered on monitor name too, so
        // moving search to the server had to keep that.
        $this->makeIncident($team, $checkout, 'Elevated error rate');
        $this->makeIncident($team, $marketing, 'Elevated error rate');

        $this->getJson('/api/v1/incidents?q=checkout')
            ->assertJsonCount(1, 'data');
    }

    public function test_editing_a_title_moves_the_search_text_with_it(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Edge');
        $incident = $this->makeIncident($team, $monitor, 'Ödeme kesintisi');

        $incident->update(['title' => 'Kargo gecikmesi']);

        $this->getJson('/api/v1/incidents?q=odeme')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/incidents?q=kargo')->assertJsonCount(1, 'data');
    }

    public function test_a_lifecycle_move_leaves_the_incident_findable(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Edge');
        $incident = $this->makeIncident($team, $monitor, 'Ödeme kesintisi');

        // The composer is gated on the title fields being dirty, so a save that
        // touches neither must not blank the column it skips.
        $incident->update(['lifecycle' => 'resolved', 'resolved_at' => now()]);

        $this->getJson('/api/v1/incidents?q=odeme')->assertJsonCount(1, 'data');
    }

    public function test_renaming_a_monitor_leaves_a_past_incident_where_it_was(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Checkout API');
        $this->makeIncident($team, $monitor, 'Elevated error rate');

        $monitor->update(['name' => 'Payments API']);

        // Deliberate, and the same rule `title_params` already follows: the
        // sentence a past incident was announced under does not get rewritten,
        // so search agrees with the title it is searching rather than with the
        // monitor's present name.
        $this->getJson('/api/v1/incidents?q=checkout')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/incidents?q=payments')->assertJsonCount(0, 'data');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function actingAsTeamMember(): Team
    {
        $user = User::query()->create([
            'name' => 'Responder',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $team = Team::query()->create(['user_id' => $user->id, 'name' => 'Search Team']);
        $team->users()->attach($user->id, ['role' => 'admin']);
        $user->forceFill(['current_team_id' => $team->id])->save();
        Sanctum::actingAs($user);

        return $team;
    }

    protected function makeMonitor(Team $team, string $name): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => 'http',
            'url' => 'https://example.com/'.Str::uuid(),
            'check_interval_sec' => 60,
        ]);
    }

    protected function makeIncident(Team $team, Monitor $monitor, string $title): Incident
    {
        return Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => $title,
            'impact' => 'critical',
            'severity' => 'critical',
            'signal_source' => 'user_threshold',
            'lifecycle' => 'detected',
            'ai_owned' => false,
            'started_at' => '2026-08-01 12:00:00',
        ]);
    }

    /**
     * @param  array<string, string>  $params
     */
    protected function makeAutomaticIncident(
        Team $team,
        Monitor $monitor,
        string $englishTitle,
        string $key,
        array $params,
    ): Incident {
        $incident = $this->makeIncident($team, $monitor, $englishTitle);
        $incident->update(['title_key' => $key, 'title_params' => $params]);

        return $incident;
    }
}
