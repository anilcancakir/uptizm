<?php

namespace Tests\Feature\StatusPage;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the rendered Blade output of the public status page: it shows the
 * component labels, the overall banner label, and a public incident title,
 * while never leaking the monitor's raw URL or the page's preview token
 * into the HTML.
 *
 * It also carries the two render-safety pins for the headless preview:
 *
 *   - the ready marker the renderer waits on, which doubles as the proof that
 *     the captured page was the real page and not a 404 or a 429 error page;
 *   - the no-remote-resource assertion, which is the load-bearing SSRF control
 *     for the whole preview feature. Browsershot exposes only denylist
 *     primitives, so an assertion over our own markup is the only thing that
 *     can actually prove the render fetches nothing external.
 */
class StatusPageRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The literal ready marker the headless renderer waits on. The renderer
     * waits for the `[data-times-localized]` attribute selector, so this name
     * is a contract: changing it here breaks the renderer silently.
     */
    protected const READY_MARKER = "document.documentElement.dataset.timesLocalized = '1'";

    public function test_it_renders_component_labels_the_banner_label_and_an_incident_title(): void
    {
        $page = $this->makePageWithMonitor('render-me', isPublic: true);
        $monitor = $page->monitors()->first();

        $this->seedPublicIncident($page->team, $monitor, 'Elevated latency on checkout API');

        $response = $this->get('/s/render-me');

        $response->assertOk();
        $response->assertSee($monitor->name, escape: false);
        $response->assertSee('All Systems Operational');
        $response->assertSee('Elevated latency on checkout API');
    }

    public function test_it_never_leaks_the_monitor_url_or_the_preview_token(): void
    {
        $page = $this->makePageWithMonitor('render-secret', isPublic: true, previewToken: 'SUPER-SECRET-TOKEN');
        $monitor = $page->monitors()->first();

        $response = $this->get('/s/render-secret');

        $response->assertOk();
        $response->assertDontSee($monitor->url);
        $response->assertDontSee('SUPER-SECRET-TOKEN');
    }

    public function test_it_renders_a_published_postmortem_for_a_resolved_incident(): void
    {
        $page = $this->makePageWithMonitor('render-postmortem', isPublic: true);
        $monitor = $page->monitors()->first();

        $incident = $this->seedPublicIncident($page->team, $monitor, 'Checkout outage');
        $incident->forceFill([
            'lifecycle' => IncidentStatus::Resolved,
            'resolved_at' => now(),
            'postmortem_body' => 'The origin pool ran out of workers under release traffic.',
            'postmortem_published_at' => now(),
        ])->save();

        $response = $this->get('/s/render-postmortem');

        $response->assertOk();
        $response->assertSee('The origin pool ran out of workers under release traffic.');
    }

    public function test_it_never_renders_an_unpublished_postmortem(): void
    {
        $page = $this->makePageWithMonitor('render-draft-only', isPublic: true);
        $monitor = $page->monitors()->first();

        $incident = $this->seedPublicIncident($page->team, $monitor, 'Checkout outage');
        $incident->forceFill([
            'lifecycle' => IncidentStatus::Resolved,
            'resolved_at' => now(),
            'postmortem_body' => 'INTERNAL DRAFT, not for customers yet.',
            'postmortem_published_at' => null,
        ])->save();

        $response = $this->get('/s/render-draft-only');

        $response->assertOk();
        $response->assertDontSee('INTERNAL DRAFT, not for customers yet.');
    }

    public function test_it_emits_the_render_ready_marker_on_a_page_with_incidents(): void
    {
        $page = $this->makePageWithMonitor('marker-with-incidents', isPublic: true);
        $this->seedPublicIncident($page->team, $page->monitors()->first(), 'Elevated latency on checkout API');

        $response = $this->get('/s/marker-with-incidents');

        $response->assertOk();
        // The rewrite loop has real `<time>` elements to walk on this page.
        $response->assertSee('<time datetime=', escape: false);
        $response->assertSee(self::READY_MARKER, escape: false);
    }

    public function test_it_emits_the_render_ready_marker_on_a_page_with_no_incidents(): void
    {
        $this->makePageWithMonitor('marker-no-incidents', isPublic: true);

        $response = $this->get('/s/marker-no-incidents');

        $response->assertOk();
        // The conditional-emission trap: nothing here produces an incident
        // `<time>` element, and the marker must still be emitted.
        $response->assertSee('No incidents reported.');
        $response->assertSee(self::READY_MARKER, escape: false);
    }

    public function test_the_ready_marker_is_set_after_the_timestamp_loop_and_never_from_inside_it(): void
    {
        $this->makePageWithMonitor('marker-placement', isPublic: true);

        $response = $this->get('/s/marker-placement');

        $response->assertOk();
        $script = $this->inlineScriptFrom($response->getContent());

        $loopAt = strpos($script, "querySelectorAll('time[datetime]')");
        $markerAt = strpos($script, self::READY_MARKER);

        $this->assertIsInt($loopAt, 'The timestamp rewrite loop must stay in the status layout.');
        $this->assertIsInt($markerAt, 'The status layout must emit the render ready marker.');
        $this->assertSame(
            1,
            substr_count($script, self::READY_MARKER),
            'The ready marker must be assigned in exactly one place.',
        );
        $this->assertGreaterThan(
            $loopAt,
            $markerAt,
            'The ready marker must be set after the timestamp rewrite loop, not before it.',
        );

        // Unconditional emission: a page with zero `<time>` elements never
        // enters the loop body, so the marker cannot live inside it.
        $this->assertStringNotContainsString(
            'timesLocalized',
            $this->timeLoopBodyFrom($script),
            'The ready marker must not be set inside the `<time>` rewrite loop.',
        );

        // The capture must not happen before webfonts settle, or the stored
        // artefact is visibly wrong.
        $this->assertStringContainsString(
            'document.fonts',
            $script,
            'The ready marker must be gated on `document.fonts.ready`.',
        );
    }

    public function test_an_unknown_slug_renders_an_error_page_without_the_ready_marker(): void
    {
        $response = $this->get('/s/no-such-page');

        $response->assertNotFound();
        // The marker is the render's success assertion, so an error page that
        // emitted it would be stored as a completed customer-facing artefact.
        $response->assertDontSee('timesLocalized');
    }

    public function test_the_public_page_references_no_resource_outside_the_app_origin(): void
    {
        // Pin Vite to the built manifest: with a dev-server hot file present,
        // `@vite` would emit `http://localhost:5173` tags, which is both a
        // foreign origin and an unstyled render.
        app(Vite::class)->useHotFile(base_path('tests/vite-hot-file-that-never-exists'));

        $page = $this->makePageWithMonitor('render-no-remote', isPublic: true);
        $incident = $this->seedPublicIncident($page->team, $page->monitors()->first(), 'Checkout outage');
        $incident->forceFill([
            'lifecycle' => IncidentStatus::Resolved,
            'resolved_at' => now(),
            'postmortem_body' => 'The origin pool ran out of workers under release traffic.',
            'postmortem_published_at' => now(),
        ])->save();

        $response = $this->get('/s/render-no-remote');
        $response->assertOk();

        $references = $this->collectResourceReferences($response->getContent());

        // Guard against a vacuous pass: the page really does pull its built
        // stylesheet, so an empty reference set means the collector broke.
        $this->assertNotEmpty($references, 'No resource references were collected from the rendered page.');
        $this->assertNotEmpty(
            array_filter($references, static fn (string $ref): bool => str_contains($ref, '/build/')),
            'Expected the built Vite stylesheet among the collected references.',
        );

        foreach ($references as $reference) {
            $this->assertTrue(
                $this->isOwnOriginReference($reference),
                "The public status page must reference no resource outside its own origin, found [{$reference}].",
            );
        }
    }

    /**
     * Returns the concatenated contents of every inline `<script>` block in the
     * rendered document.
     */
    protected function inlineScriptFrom(string $html): string
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $matches);

        return implode("\n", $matches[1]);
    }

    /**
     * Returns the body of the `<time>` rewrite loop's callback: everything from
     * the `forEach` callback's opening brace to the first `});` that closes it.
     */
    protected function timeLoopBodyFrom(string $script): string
    {
        $start = strpos($script, "querySelectorAll('time[datetime]')");

        if (! is_int($start)) {
            return '';
        }

        $end = strpos($script, '});', $start);

        return $end === false ? substr($script, $start) : substr($script, $start, $end - $start);
    }

    /**
     * Collects every resource reference the rendered document can make the
     * browser fetch: `src` / `href` attributes, CSS `url(...)` values (inline
     * `style` attributes included), and `@import` targets. Same-origin
     * stylesheets served from `public/` are followed one level, because a
     * remote webfont hides in the CSS rather than in the markup.
     *
     * @return list<string>
     */
    protected function collectResourceReferences(string $html): array
    {
        $references = $this->extractReferences($html);

        foreach ($references as $reference) {
            foreach ($this->stylesheetReferencesFor($reference) as $nested) {
                $references[] = $nested;
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * Extracts the reference values from one document or stylesheet body.
     *
     * @return list<string>
     */
    protected function extractReferences(string $source): array
    {
        $patterns = [
            // `src="..."` / `href="..."`, quoted either way.
            '#\b(?:src|href)\s*=\s*["\']([^"\']*)["\']#i',
            // CSS `url(...)`, quoted or bare.
            '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#i',
            // `@import "..."` and `@import url("...")`.
            '#@import\s+(?:url\(\s*)?["\']([^"\']+)["\']#i',
        ];

        $references = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $match) {
                $match = trim($match);

                if ($match !== '') {
                    $references[] = $match;
                }
            }
        }

        return $references;
    }

    /**
     * Reads the references out of a same-origin stylesheet that is served from
     * this app's `public/` directory. Anything else yields nothing: a remote
     * stylesheet is already a failure at the reference level.
     *
     * @return list<string>
     */
    protected function stylesheetReferencesFor(string $reference): array
    {
        if (! str_ends_with(strtolower(parse_url($reference, PHP_URL_PATH) ?: ''), '.css')) {
            return [];
        }

        if (! $this->isOwnOriginReference($reference)) {
            return [];
        }

        $path = public_path(ltrim((string) parse_url($reference, PHP_URL_PATH), '/'));

        return is_file($path) ? $this->extractReferences((string) file_get_contents($path)) : [];
    }

    /**
     * Whether a reference points at this app and nothing else. Relative and
     * root-relative references qualify by construction; an absolute one must
     * match one of the app's own origins.
     */
    protected function isOwnOriginReference(string $reference): bool
    {
        // Same-document fragment.
        if (str_starts_with($reference, '#')) {
            return true;
        }

        // Protocol-relative (`//host/path`) is remote, and parse_url reports no
        // scheme for it, so reject it before the relative check below.
        if (str_starts_with($reference, '//')) {
            return false;
        }

        $scheme = strtolower((string) parse_url($reference, PHP_URL_SCHEME));

        if ($scheme === '') {
            return true;
        }

        // Inline payloads and the two schemes that fetch nothing over the wire.
        if (in_array($scheme, ['data', 'mailto', 'tel'], true)) {
            return true;
        }

        return in_array($this->originOf($reference), $this->ownOrigins(), true);
    }

    /**
     * The origins that count as this app's own: the root the app generates its
     * links from, plus the configured `APP_URL`. Under `php artisan test` the
     * two differ (the test request root has no port), and both are ours.
     *
     * @return list<string>
     */
    protected function ownOrigins(): array
    {
        return array_values(array_unique([
            $this->originOf(url('/')),
            $this->originOf((string) config('app.url')),
        ]));
    }

    /**
     * Reduces an absolute URL to its `scheme://host:port` origin.
     */
    protected function originOf(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').$port);
    }

    /**
     * Creates a status page for a fresh team with one attached, shown monitor
     * plus a daily-uptime row, so the assembler has real data to render.
     */
    protected function makePageWithMonitor(string $slug, bool $isPublic, ?string $previewToken = null): StatusPage
    {
        $team = $this->makeTeam();

        $page = new StatusPage([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => $isPublic,
            'subscriptions_enabled' => true,
        ]);

        // `preview_token` is guarded (hidden, non-fillable), so set it directly.
        $page->preview_token = $previewToken;
        $page->save();

        $monitor = $this->makeMonitor($team);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);
        $this->seedUptime($monitor, now()->format('Y-m-d'), 'operational');

        return $page;
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Status Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Status Team',
        ]);
    }

    /**
     * Creates a monitor owned by the given team, shown on the status page.
     */
    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Component '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://secret-internal-host.example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Up,
        ]);
    }

    /**
     * Inserts one daily-uptime rollup row for the monitor.
     */
    protected function seedUptime(Monitor $monitor, string $date, string $worst, float $percent = 100.0): void
    {
        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'date' => $date,
            'uptime_percent' => $percent,
            'total_checks' => 100,
            'failed_checks' => 0,
            'worst_status' => $worst,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (MigrationHelper::usesUuids()) {
            $row['id'] = (string) Str::orderedUuid();
        }

        DB::table('monitor_daily_uptime')->insert($row);
    }

    /**
     * Creates an active incident with one public update on the given monitor,
     * so the public page's incidents section has an entry to render.
     */
    protected function seedPublicIncident(Team $team, Monitor $monitor, string $title): Incident
    {
        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => $title,
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::Manual,
            'lifecycle' => IncidentStatus::Investigating,
            'ai_owned' => false,
            'started_at' => now(),
        ]);

        $incident->monitors()->attach([
            $monitor->id => [
                'component_status_at_start' => 'degraded',
                'component_status_current' => 'degraded',
            ],
        ]);

        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'message' => 'We are investigating elevated latency.',
            'actor' => 'human',
            'status' => IncidentStatus::Investigating,
            'is_public' => true,
            'autonomous' => false,
            'display_at' => now(),
        ]);

        return $incident;
    }
}
