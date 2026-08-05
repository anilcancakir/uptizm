<?php

namespace Tests\Feature\Http;

use App\Enums\MonitorType;
use App\Http\Controllers\Api\V1\MonitorContentController;
use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ArchivedBodyReader;
use App\Services\Monitoring\ContentArchive;
use App\Support\Monitoring\MetricCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the two guarantees {@see MonitorContentController} exists to make, both
 * of which are one line away from a serious defect.
 *
 * THE BYTES ARE FULLY ATTACKER CONTROLLED. An archived version holds whatever
 * the monitored target chose to serve, so the download must never render on this
 * authenticated API origin: `text/plain; charset=utf-8` plus
 * `Content-Disposition: attachment` are asserted on the response headers rather
 * than trusted to the controller's prose, because an HTML content type here is
 * stored XSS against every operator who opens the link.
 *
 * THE TENANT MASK IS A 404, NOT A 403. Each cross-team case below is built so it
 * would PASS a broken controller only if the request actually succeeded: the
 * foreign monitor owns a real version row AND its blob is written to the fake
 * disk, so dropping the team scope turns these into 200s carrying another
 * tenant's bytes rather than into a differently-shaped 404. The same holds for
 * the cross-team-version case, which addresses a FOREIGN team's hash under the
 * caller's OWN monitor: it can only 404 if the version lookup keys on
 * `(monitor_id, content_hash)` instead of on the hash alone.
 *
 * THE CANDIDATE BROWSER IS THE THIRD ACTION AND CARRIES A DIGEST, NOT A
 * DOCUMENT. Its cases assert the exact key set of a row (an ADDED key is how the
 * archived bytes would arrive), that a sample value is cut to
 * {@see MetricCandidate::DIGEST_VALUE_MAX_LENGTH}, that a path a metric write
 * would refuse is dropped rather than suggested, that a repeat request costs no
 * second archive read, and that the shared limiter bites. The read it bounds is
 * a cold FUSE fetch off a Drive remote, so an unbounded or uncached one holds an
 * Octane worker for about a second per request.
 */
class MonitorContentControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact JSON key set of one indexed version. Pinned as a whole rather
     * than probed key by key, because the defect worth catching is an ADDED key:
     * the archived body itself must never travel through a metadata response.
     */
    protected const VERSION_KEYS = [
        'content_hash',
        'byte_size',
        'content_type',
        'truncated',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * The exact JSON key set of one candidate row, pinned for the same reason as
     * the version keys above: this response is derived from an attacker-supplied
     * document, so a key nobody asked for is the shape of the defect.
     *
     * These names come from {@see MetricCandidate::toDigestRow()} and the metric
     * form fills itself from them, so renaming one on the way out breaks the
     * client.
     */
    protected const CANDIDATE_KEYS = [
        'ref',
        'src',
        'path',
        'value',
        'label',
        'types',
    ];

    public function test_index_lists_the_monitors_versions_and_never_the_content(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $version = $this->archiveVersion($monitor, 'operational, 240 services up');

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/content");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.content_hash', $version->content_hash);
        $response->assertJsonPath('data.0.byte_size', $version->byte_size);
        $response->assertJsonPath('data.0.content_type', 'text/html');
        $response->assertJsonPath('data.0.truncated', false);
        $this->assertSame(self::VERSION_KEYS, array_keys((array) $response->json('data.0')));
    }

    public function test_index_lists_only_the_addressed_monitors_versions(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $sibling = $this->makeMonitor($team->id);
        $mine = $this->archiveVersion($monitor, 'mine');
        $theirs = $this->archiveVersion($sibling, 'the sibling monitor in my own team');

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/content");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.content_hash', $mine->content_hash);
        $response->assertJsonMissing(['content_hash' => $theirs->content_hash]);
    }

    public function test_index_masks_a_cross_team_monitor_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreignMonitor = $this->makeMonitor($this->makeForeignTeam()->id);
        // A real archived version on the foreign monitor: without the team scope
        // this request answers 200 and lists it, so the assertion below fails
        // rather than passing on an empty database.
        $foreignVersion = $this->archiveVersion($foreignMonitor, 'another tenant');

        $response = $this->getJson("/api/v1/monitors/{$foreignMonitor->id}/content");

        $response->assertStatus(404);
        $response->assertDontSee($foreignVersion->content_hash);
    }

    public function test_index_requires_authentication(): void
    {
        $monitor = $this->makeMonitor($this->makeForeignTeam()->id);
        $this->archiveVersion($monitor, 'unauthenticated reader');

        $this->getJson("/api/v1/monitors/{$monitor->id}/content")->assertStatus(401);
    }

    public function test_download_serves_the_exact_original_bytes(): void
    {
        $body = $this->fixtureBody();
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $version = $this->archiveVersion($monitor, $body);

        $response = $this->get("/api/v1/monitors/{$monitor->id}/content/{$version->content_hash}");

        $response->assertStatus(200);
        $this->assertSame($body, $response->getContent());
    }

    public function test_download_forces_an_attachment_and_never_renders_inline(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $version = $this->archiveVersion($monitor, '<script>alert(document.cookie)</script>');

        $response = $this->get("/api/v1/monitors/{$monitor->id}/content/{$version->content_hash}");

        $response->assertStatus(200);
        // The three headers that keep attacker-controlled bytes from executing on
        // an authenticated API origin. `text/html` or a missing disposition here
        // is stored XSS, which is why these are asserted literally.
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename="monitor-'.$monitor->id.'-'.$version->content_hash.'.txt"',
        );
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_download_masks_a_cross_team_monitor_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreignMonitor = $this->makeMonitor($this->makeForeignTeam()->id);
        // Row AND blob both present, so the only thing that can produce a 404 is
        // the tenant check. Drop it and this test reads another team's bytes.
        $foreignVersion = $this->archiveVersion($foreignMonitor, 'another tenant private page');

        $response = $this->getJson(
            "/api/v1/monitors/{$foreignMonitor->id}/content/{$foreignVersion->content_hash}",
        );

        $response->assertStatus(404);
        $response->assertDontSee('another tenant private page');
    }

    public function test_download_masks_a_cross_team_version_under_an_owned_monitor_as_404(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->archiveVersion($monitor, 'my own page');

        $foreignMonitor = $this->makeMonitor($this->makeForeignTeam()->id);
        $foreignVersion = $this->archiveVersion($foreignMonitor, 'another tenant private page');

        // The caller's OWN monitor in the URL, a foreign team's hash as the
        // version. A lookup keyed on the hash alone resolves it, then derives the
        // blob path from the foreign row's `team_id` and serves the bytes.
        $response = $this->getJson(
            "/api/v1/monitors/{$monitor->id}/content/{$foreignVersion->content_hash}",
        );

        $response->assertStatus(404);
        $response->assertDontSee('another tenant private page');
    }

    public function test_download_requires_authentication(): void
    {
        $monitor = $this->makeMonitor($this->makeForeignTeam()->id);
        $version = $this->archiveVersion($monitor, 'unauthenticated reader');

        $this->getJson("/api/v1/monitors/{$monitor->id}/content/{$version->content_hash}")
            ->assertStatus(401);
    }

    public function test_download_404s_when_retention_already_pruned_the_blob(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $version = $this->archiveVersion($monitor, 'pruned page');

        // The reachable state: retention deleted the blob while check rows still
        // carry the hash. Reading a missing file must answer 404, never a 500.
        Storage::disk($this->diskName())->delete(
            $this->app->make(ContentArchive::class)->blobPath($version->team_id, $version->content_hash),
        );

        $this->getJson("/api/v1/monitors/{$monitor->id}/content/{$version->content_hash}")
            ->assertStatus(404);
    }

    public function test_download_404s_on_a_hash_shaped_address_that_was_never_archived(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $this->getJson("/api/v1/monitors/{$monitor->id}/content/".str_repeat('a', 64))
            ->assertStatus(404);
    }

    public function test_download_404s_on_a_malformed_address(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        foreach (['not-a-hash', '../../etc/passwd', str_repeat('A', 64), str_repeat('a', 63)] as $address) {
            $this->getJson("/api/v1/monitors/{$monitor->id}/content/".rawurlencode($address))
                ->assertStatus(404);
        }
    }

    public function test_download_404s_for_a_row_whose_own_hash_is_malformed(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        // The one address a malformed value can still MATCH, and therefore the
        // only case the row lookup cannot turn away on its own: a stored row
        // carrying a hash that is not 64 lowercase hex (the same corrupt row the
        // retention sweep skips). `ContentArchive::blobPath()` throws on it, so
        // without the route's hash constraint this request answers 500.
        MonitorContentVersion::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'content_hash' => 'not-a-hash',
            'content_hash_normalized' => 'not-a-hash',
            'byte_size' => 12,
            'content_type' => 'text/html',
            'truncated' => false,
            'normalizer_version' => (int) config('content-archive.normalizer_version'),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->getJson("/api/v1/monitors/{$monitor->id}/content/not-a-hash")->assertStatus(404);
    }

    public function test_candidates_returns_proved_digest_rows_from_the_newest_archived_body(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->archiveVersion($monitor, '{"status":"degraded","latency_ms":97}');

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates");

        $response->assertStatus(200);
        $response->assertJsonPath('has_sample', true);

        $rows = (array) $response->json('data');
        $this->assertCount(2, $rows);

        // The exact key set of a row, pinned as a whole for the same reason the
        // version index is: the defect worth catching is an ADDED key, and the
        // one that would matter here is the archived body itself.
        $this->assertSame(self::CANDIDATE_KEYS, array_keys((array) $rows[0]));

        // Ranked best first, so the numeric leaf leads. The client fills
        // `source`, `extraction_path` and `type` from these names, so they are a
        // wire contract and not an internal shape.
        $this->assertSame('c1', $rows[0]['ref']);
        $this->assertSame('json_path', $rows[0]['src']);
        $this->assertSame('latency_ms', $rows[0]['path']);
        $this->assertSame('97', $rows[0]['value']);
        $this->assertSame('latency_ms', $rows[0]['label']);
        $this->assertSame(['numeric', 'string'], $rows[0]['types']);

        $this->assertSame('status', $rows[1]['path']);
        $this->assertSame('degraded', $rows[1]['value']);
        // A non-numeric sample is string-only: offered as numeric it would
        // extract on every check and record nothing.
        $this->assertSame(['string'], $rows[1]['types']);
    }

    public function test_candidates_reads_only_the_newest_archived_version(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $older = $this->archiveVersion($monitor, '{"old_key":"alpha"}');
        $older->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->archiveVersion($monitor, '{"new_key":"beta"}');

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates");

        $response->assertStatus(200);
        // One read, never a history scan: the archive is a cold FUSE mount of a
        // remote capped near two file operations a second, so scanning versions
        // would turn one request into a stall.
        $this->assertSame(['new_key'], $this->candidatePaths($response->json('data')));
    }

    public function test_candidates_drops_a_path_a_metric_write_would_refuse(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        // `extraction_path` validates at `max:500`, so an attacker-chosen key name
        // longer than that produces a suggestion that 422s the moment the operator
        // taps it. The 500-character key is here to pin the boundary as inclusive:
        // it is exactly what the write path accepts and must still be offered.
        $atLimit = str_repeat('m', 500);
        $overLimit = str_repeat('k', 600);
        $this->archiveVersion(
            $monitor,
            '{"'.$atLimit.'":"maybe","'.$overLimit.'":"yes","latency_ms":97}',
        );

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates");

        $response->assertStatus(200);
        $paths = $this->candidatePaths($response->json('data'));

        $this->assertContains($atLimit, $paths);
        $this->assertContains('latency_ms', $paths);
        $this->assertNotContains($overLimit, $paths);
        $response->assertDontSee($overLimit);

        foreach ($paths as $path) {
            $this->assertLessThanOrEqual(500, mb_strlen($path));
        }
    }

    public function test_candidates_never_carries_the_archived_bytes_and_cuts_the_sample_value(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        // A wholly attacker-chosen document: script text where a value goes, and a
        // 600-character key where a path comes from. A digest row may carry a
        // bounded PREFIX of one value as inert JSON string data; it may never
        // carry the document, and it may never suggest a path the write path
        // refuses.
        $script = '<script>alert(1)</script>';
        $tail = str_repeat('x', 200);
        $overLimit = str_repeat('k', 600);
        $body = '{"status":"'.$script.$tail.'","'.$overLimit.'":"yes"}';
        $this->archiveVersion($monitor, $body);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');

        $rows = (array) $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(self::CANDIDATE_KEYS, array_keys((array) $rows[0]));
        $this->assertSame(
            mb_substr($script.$tail, 0, MetricCandidate::DIGEST_VALUE_MAX_LENGTH)
                .MetricCandidate::DIGEST_TRUNCATION_MARK,
            $rows[0]['value'],
        );

        // Neither the document, nor the part of the value past the ceiling, nor
        // the key too long to ever be saved.
        $response->assertDontSee($body, false);
        $response->assertDontSee($tail, false);
        $response->assertDontSee($overLimit, false);
    }

    public function test_candidates_still_answers_a_list_for_a_body_carrying_a_non_utf8_byte(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        // A monitored page serving a broken charset must produce a list, never a
        // failed encode. `response()->json()` does not set
        // `JSON_INVALID_UTF8_SUBSTITUTE` by default and Laravel's JsonResponse
        // THROWS on an encode error, so one stray byte would otherwise be a 500.
        $this->archiveVersion(
            $monitor,
            '<html><body><span id="latency">97'."\xB5".'s</span></body></html>',
        );

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates");

        $response->assertStatus(200);
        $response->assertJsonPath('has_sample', true);
        $this->assertSame(['//*[@id="latency"]'], $this->candidatePaths($response->json('data')));
    }

    public function test_candidates_answers_an_empty_list_when_nothing_is_archived(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates");

        // Mirrors how the metric preview answers with no sample: a flag, not an
        // error, so the form panel renders "nothing captured yet" rather than a
        // failure the operator cannot act on.
        $response->assertStatus(200);
        $response->assertJsonPath('has_sample', false);
        $response->assertJsonPath('data', []);
    }

    public function test_candidates_serves_a_repeat_request_from_the_cache_without_a_second_archive_read(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $version = $this->archiveVersion($monitor, '{"status":"degraded","latency_ms":97}');

        // Counting the READS rather than comparing two responses: identical bodies
        // prove nothing about the cache, since the extractor is deterministic and
        // would answer identically on a second cold read too.
        $reader = new class($this->app->make(ContentArchive::class)) extends ArchivedBodyReader
        {
            public int $calls = 0;

            public function newestArchivedBody(Monitor $monitor): ?string
            {
                $this->calls++;

                return parent::newestArchivedBody($monitor);
            }
        };
        $this->app->instance(ArchivedBodyReader::class, $reader);

        $first = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates");

        $first->assertStatus(200);
        $this->assertSame(1, $reader->calls);
        $this->assertTrue(Cache::has(
            MonitorContentController::CANDIDATES_CACHE_KEY_PREFIX.$version->content_hash,
        ));

        $second = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates");

        $second->assertStatus(200);
        $this->assertSame(1, $reader->calls);
        $this->assertSame($first->json(), $second->json());
    }

    public function test_candidates_masks_a_cross_team_monitor_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreignMonitor = $this->makeMonitor($this->makeForeignTeam()->id);
        // Row AND blob both present, so the only thing that can produce a 404 is
        // the tenant check. Drop it and this test reads another team's page.
        $this->archiveVersion($foreignMonitor, '{"tenant_secret":"another tenant"}');

        $response = $this->getJson("/api/v1/monitors/{$foreignMonitor->id}/content/candidates");

        $response->assertStatus(404);
        $response->assertDontSee('tenant_secret');
        $response->assertDontSee('another tenant');
    }

    public function test_candidates_requires_authentication(): void
    {
        $monitor = $this->makeMonitor($this->makeForeignTeam()->id);
        $this->archiveVersion($monitor, '{"status":"degraded"}');

        $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates")->assertStatus(401);
    }

    public function test_the_candidates_route_and_the_metric_preview_route_share_one_named_limiter(): void
    {
        // Both read the same cold archive blob, and `api/v1` never calls
        // throttleApi(), so this named limiter is the only bound either has.
        foreach ([
            'api.v1.monitors.content.candidates',
            'api.v1.monitors.metrics.preview',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name.' is not registered.');
            $this->assertContains(
                'throttle:'.MonitorContentController::SAMPLE_READ_LIMITER,
                $route->gatherMiddleware(),
                $name.' does not carry the shared sample-read limiter.',
            );
        }
    }

    public function test_candidates_answers_429_once_the_limiter_is_spent(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->getJson("/api/v1/monitors/{$monitor->id}/content/candidates")
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses);
    }

    /**
     * The `path` of every returned candidate row, in the order the endpoint
     * ranked them.
     *
     * @return list<string>
     */
    protected function candidatePaths(mixed $rows): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['path'],
            array_values((array) $rows),
        );
    }

    /**
     * An archived version of `$body` for the monitor: the metadata row the check
     * pipeline claims plus the gzipped blob {@see ContentArchive} publishes.
     *
     * The blob is written at the SAME helper-derived path the controller must
     * read from, so a controller that re-derives the path its own way fails here
     * instead of quietly reading nothing.
     */
    protected function archiveVersion(Monitor $monitor, string $body): MonitorContentVersion
    {
        $hash = hash('sha256', $body);

        $version = MonitorContentVersion::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'content_hash' => $hash,
            'content_hash_normalized' => hash('sha256', (string) preg_replace('/\s+/', ' ', $body)),
            'byte_size' => strlen($body),
            'content_type' => 'text/html',
            'truncated' => false,
            'normalizer_version' => (int) config('content-archive.normalizer_version'),
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        Storage::disk($this->diskName())->put(
            $this->app->make(ContentArchive::class)->blobPath($monitor->team_id, $hash),
            (string) gzencode($body),
        );

        return $version;
    }

    /**
     * A body carrying markup and a null-free byte range, so an exact-bytes
     * comparison is meaningful rather than a one-word match.
     */
    protected function fixtureBody(): string
    {
        return '<html><body><h1>Status</h1><p>All 240 services operational.</p>'
            ."<pre>latency: 97 ms\ncache: HIT\n</pre></body></html>";
    }

    /**
     * The archive disk, read through its own config key exactly as the archive
     * does; {@see TestCase::setUp()} already faked it.
     */
    protected function diskName(): string
    {
        return (string) config('content-archive.disk');
    }

    /**
     * Authenticate as a user whose current team is a freshly created team.
     */
    protected function actingAsTeamMember(): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    /**
     * A persisted team owned by a fresh user, unrelated to the acting user.
     */
    protected function makeForeignTeam(): Team
    {
        return Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
    }

    /**
     * A persisted HTTP monitor for the given team.
     */
    protected function makeMonitor(string $teamId): Monitor
    {
        return Monitor::create([
            'team_id' => $teamId,
            'name' => 'API Health '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
        ]);
    }
}
