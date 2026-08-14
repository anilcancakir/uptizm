<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\RelayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The signed spec is the only thing the relay knows about a monitor, and the
 * worker cannot read Laravel config, so anything the edge needs travels on it.
 * A field that stops being sent does not fail a build: the worker falls back to
 * its own default and the two halves quietly disagree.
 *
 * This pins the fields that exist for that reason and would otherwise have no
 * test at all: the archive ceiling, the content-type allowlist and the bot
 * identity.
 */
class RelaySpecTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The identity `resources/legal/bot.en.md` publishes reaches the edge.
     *
     * That page tells every operator both clients identify themselves with the
     * same string and that blocking it stops us, and it renders this exact
     * config value. Sending it from the origin rather than holding a copy in
     * `wrangler.toml` is what keeps the page's claim and the request the target
     * actually sees the same fact.
     */
    public function test_the_spec_carries_the_published_bot_user_agent(): void
    {
        $monitor = $this->makeMonitor();
        Http::fake([
            '*' => Http::response($this->relayPayload($monitor), 200),
        ]);

        $this->app->make(RelayClient::class)->dispatch($monitor, 'eu-west');

        Http::assertSent(function (Request $request): bool {
            return ($this->spec($request)['user_agent'] ?? null)
                === (string) config('uptizm.bot_user_agent');
        });
    }

    /**
     * The two config-carried archive fields, pinned beside it for the same
     * reason: raising `CONTENT_ARCHIVE_MAX_BYTES` or widening the allowlist has
     * to reach the edge, and neither is observable from a green suite otherwise.
     */
    public function test_the_spec_carries_the_archive_ceiling_and_allowlist(): void
    {
        $monitor = $this->makeMonitor();
        Http::fake([
            '*' => Http::response($this->relayPayload($monitor), 200),
        ]);

        $this->app->make(RelayClient::class)->dispatch($monitor, 'eu-west');

        Http::assertSent(function (Request $request): bool {
            $spec = $this->spec($request);

            return ($spec['max_bytes'] ?? null) === (int) config('content-archive.max_bytes')
                && ($spec['allowed_content_types'] ?? null) === config('content-archive.allowed_content_types');
        });
    }

    /**
     * The spec as the worker receives it.
     *
     * Decoded from the raw body rather than read through `$request->data()`,
     * because the client sends the spec with `withBody()`: the same byte string
     * is signed and sent, so there is no form payload for `data()` to expose,
     * and the whole body IS the spec rather than a field inside one.
     *
     * @return array<string, mixed>
     */
    protected function spec(Request $request): array
    {
        return (array) json_decode($request->body(), true);
    }

    /**
     * A relay response shaped the way `CheckResult::fromWorkerPayload()` reads
     * it, so `dispatch()` returns rather than throwing on the parse.
     *
     * @return array<string, mixed>
     */
    protected function relayPayload(Monitor $monitor): array
    {
        return [
            'monitor_id' => (string) $monitor->getKey(),
            'probe_run_id' => (string) Str::uuid(),
            'region' => 'eu-west',
            'checked_at' => now()->toIso8601String(),
            'status' => 'up',
            'status_code' => 200,
            'response_ms' => 42,
            'error_message' => null,
            'timing' => [],
            'response_headers' => [],
            'response_body_preview' => null,
            'probe_refused' => false,
            'content' => null,
            'content_type' => null,
            'content_truncated' => false,
        ];
    }

    protected function makeMonitor(): Monitor
    {
        $user = User::query()->create([
            'name' => 'Relay Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Relay Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'regions' => ['eu-west'],
        ]);
    }
}
