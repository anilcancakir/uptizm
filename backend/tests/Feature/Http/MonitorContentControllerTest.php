<?php

namespace Tests\Feature\Http;

use App\Enums\MonitorType;
use App\Http\Controllers\Api\V1\MonitorContentController;
use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ContentArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
