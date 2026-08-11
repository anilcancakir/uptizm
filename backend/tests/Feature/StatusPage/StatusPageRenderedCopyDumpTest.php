<?php

namespace Tests\Feature\StatusPage;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Writes the evidence artifact a human reads: the copy a visitor actually receives
 * from a Turkish page and an English one, taken out of the FETCHED HTML.
 *
 * It exists because the first version of that artifact was a catalogue dump, and a
 * catalogue dump cannot tell a string that renders from a string that is dead. That
 * is precisely how it showed seventeen Turkish entries as if a visitor had read them
 * while the whole subscribe flow was still answering in English. So this drives the
 * four public routes and greps their responses.
 *
 * Skipped unless `UPTIZM_DUMP_STATUS_COPY=1`, because writing a file under `.ac/` is
 * not a suite's business; the assertions that GATE this behaviour live in
 * StatusPageLocaleTest.
 */
class StatusPageRenderedCopyDumpTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump_the_rendered_copy_for_both_locales(): void
    {
        if (env('UPTIZM_DUMP_STATUS_COPY') !== '1') {
            $this->markTestSkipped('Set UPTIZM_DUMP_STATUS_COPY=1 to regenerate the artifact.');
        }

        $lines = [
            '# Step 8 evidence: the status-page copy a VISITOR receives, per locale',
            '# Generated: '.now()->toIso8601String(),
            '# Source: the rendered HTML of four fetched routes, not the catalogue files.',
            '# A catalogue dump cannot distinguish a string that renders from one that is',
            '# dead, which is how an earlier version of this artifact missed that the whole',
            '# subscribe flow still answered in English.',
        ];

        foreach (['en' => null, 'tr' => 'tr'] as $label => $locale) {
            $page = $this->makePage($locale);
            $this->makeIncident($page);

            $lines[] = '';
            $lines[] = str_repeat('=', 78);
            $lines[] = '## locale: '.$label.'  (status_pages.locale = '.($locale ?? 'null').')';
            $lines[] = str_repeat('=', 78);

            $show = $this->get(route('status.show', ['slug' => $page->slug]))->getContent();
            $lines[] = '-- GET /s/{slug}';
            $lines = array_merge($lines, $this->visibleText((string) $show));

            $this->post(route('status.subscribe', ['slug' => $page->slug]), [
                'email' => 'reader@example.com',
            ]);
            $subscriber = $page->subscribers()->firstOrFail();

            $inbox = $this->post(route('status.subscribe', ['slug' => $page->slug]), [
                'email' => 'reader@example.com',
            ])->getContent();
            $lines[] = '';
            $lines[] = '-- POST /s/{slug}/subscribe';
            $lines = array_merge($lines, $this->visibleText((string) $inbox));

            $confirmed = $this->get(route('status.subscribe.confirm', [
                'slug' => $page->slug,
                'token' => $subscriber->confirmed_token,
            ]))->getContent();
            $lines[] = '';
            $lines[] = '-- GET /s/{slug}/subscribe/confirm/{token}';
            $lines = array_merge($lines, $this->visibleText((string) $confirmed));

            $unsubscribed = $this->get(route('status.unsubscribe', [
                'token' => $subscriber->refresh()->unsubscribe_token,
            ]))->getContent();
            $lines[] = '';
            $lines[] = '-- GET /unsubscribe/{token}';
            $lines = array_merge($lines, $this->visibleText((string) $unsubscribed));
        }

        $path = base_path('../.ac/plans/uptizm-pr4-structural-title/evidence/step8-rendered-strings.txt');
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);

        $this->assertFileExists($path);
    }

    /**
     * The human-readable text of a rendered page: tags stripped, blanks collapsed.
     *
     * @return list<string>
     */
    protected function visibleText(string $html): array
    {
        preg_match('/<html[^>]*lang="([a-z-]+)"/', $html, $lang);

        $text = strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $html) ?? '');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $out = ['   html lang="'.($lang[1] ?? '?').'"'];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

            if ($line !== '') {
                $out[] = '   '.$line;
            }
        }

        return $out;
    }

    protected function makePage(?string $locale): StatusPage
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => Str::random(8).'@example.com',
            'password' => bcrypt('password'),
        ]);
        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Acme',
            'personal_team' => true,
        ]);

        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Acme Status',
            'slug' => 'dump-'.($locale ?? 'default'),
            'is_public' => true,
            'subscriptions_enabled' => true,
            'locale' => $locale,
        ]);
    }

    protected function makeIncident(StatusPage $page): void
    {
        $monitor = Monitor::query()->create([
            'team_id' => $page->team_id,
            'name' => 'Checkout API',
            'url' => 'https://checkout.example.com/health',
            'type' => MonitorType::Http,
            'check_interval_sec' => 60,
            // Both flags are what put the component (and therefore its incident) on
            // the page at all; without them the artifact renders the empty states and
            // shows no incident title, which is the one string this PR is about.
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Down,
        ]);

        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $composed = IncidentTitle::compose(IncidentTitle::MONITOR_DOWN, [
            'monitor' => $monitor->name,
        ]);

        $incident = Incident::query()->create([
            'team_id' => $page->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => $composed['title'],
            'title_key' => $composed['title_key'],
            'title_params' => $composed['title_params'],
            'impact' => IncidentImpact::Major,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Investigating,
            'started_at' => now()->subMinutes(20),
        ]);

        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => MonitorStatus::Down->value,
            'component_status_current' => MonitorStatus::Down->value,
        ]);
    }
}
