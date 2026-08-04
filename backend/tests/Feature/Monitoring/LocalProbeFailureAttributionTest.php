<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Proxy;
use App\Models\ProxySource;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\LocalProbeEngine;
use App\Services\Proxy\ProxyPool;
use App\Support\Services\SystemTeam;
use Closure;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Whether a failed probe is OUR fault or the TARGET's, and what each answer is
 * allowed to write.
 *
 * This is the property the local engine is judged on. A third-party exit that
 * dies is our own infrastructure failing, and recording that as a check opens an
 * incident against a service that is up: uptizm would publish an outage on
 * github.com because a proxy we rent stopped answering. Recording it as a
 * SUCCESS would be worse, because it would reset a streak built by a real
 * outage.
 *
 * The evidence curl hands us is thin. A dead proxy and a dead target BOTH
 * surface as errno 7 (`CURLE_COULDNT_CONNECT`), so the error code is not the
 * discriminator. Only two codes name the proxy outright (5
 * `CURLE_COULDNT_RESOLVE_PROXY`, 97 `CURLE_PROXY`), plus one HTTP status that
 * cannot have come from the target at all (407, which only a proxy sends), plus
 * one MESSAGE: a CONNECT tunnel the proxy refused arrives as errno 56
 * (`CURLE_RECV_ERROR`), which a target resetting a connection also produces, so
 * there the wording is the only evidence and the errno stays ambiguous on its
 * own. Everything else is ambiguous too, and the only way to resolve it is to ask
 * a SECOND exit in the same region: if that one answers, the first exit was the
 * problem; if it fails the same way, the target genuinely is unreachable.
 *
 * So there are three outcomes, and this file pins the boundary between them:
 *
 *  - a READING (up / degraded / down): the target answered, or every exit we
 *    tried failed in a way we could not attribute to ourselves.
 *  - a REFUSAL (`probeRefused`): an exit failed on OUR side and nothing
 *    answered, or the region had no exit to try. It writes no check row at all,
 *    through `CheckPersistenceService::persist()`'s existing early return, which
 *    {@see ProbeRefusalTest} already pins from both sides.
 *  - an EXCEPTION: reserved for a defect (a TCP monitor, a non-system team), not
 *    for a failed probe.
 *
 * ## How a transport failure is simulated, and the one thing a fake cannot reach
 *
 * Each failure is scripted by throwing the exact exception the wire produces: a
 * Guzzle `RequestException` carrying `handlerContext['errno']`, which Laravel's
 * `PendingRequest::marshalRequestExceptionWithoutResponse()` then marshals into
 * an `Illuminate\Http\Client\ConnectionException` holding the Guzzle one as its
 * `getPrevious()`. That nesting is the measured production shape, and it is why
 * the classifier reads the errno through `getPrevious()` instead of off the
 * outer exception, which has no `getHandlerContext()` method at all. The
 * scripted messages carry curl's real ` for <url>` suffix too, because the
 * engine must NOT paste that into an operator-facing string.
 *
 * One property this file cannot pin: that a 2xx whose body the engine aborted is
 * recognised BEFORE the ladder runs. Under `Http::fake` the `on_headers`
 * callback never fires, so the header capture is always empty and the mutation
 * "classify the errno before checking the capture" reddens nothing here. It
 * reddens the loopback test in {@see LocalProbeEngineTest}, which is the only
 * test in the suite that touches a socket. What this file pins is the other
 * half of that claim, which is Step 9's own: an answered probe is not retried,
 * penalises nothing, and never reaches a second exit.
 */
class LocalProbeFailureAttributionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Guzzle transfer options of every request the engine issued, in order.
     *
     * `Http::recorded()` keeps the request but not the options, and the exit a
     * given attempt went out through is only visible there.
     *
     * @var list<array<string, mixed>>
     */
    protected array $seenOptions = [];

    protected int $proxySequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The ladder's bound is config-driven, and every expectation below about
        // how many exits get tried depends on it. Pinned here so the suite does
        // not read the deploy's env value.
        config(['proxy.attempts_per_check' => 2]);
    }

    public function test_an_answered_probe_is_not_retried_and_penalises_nothing(): void
    {
        // Two exits are available and only one may be used: a success ends the
        // ladder. See the class docblock for the half of this claim that needs a
        // real socket.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([200]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Up, $reading->status);
        $this->assertFalse($reading->probeRefused);
        $this->assertCount(1, $this->seenOptions);

        foreach (Proxy::all() as $exit) {
            $this->assertSame(0, $exit->failed_attempts);
            $this->assertNull($exit->available_at);
        }
    }

    public function test_an_exit_that_answers_heals_one_step_of_its_failure_history(): void
    {
        // `ProxyPool::reward()` exists for exactly one observer: this engine is
        // the only thing that ever sees a transport SUCCEED. Without a caller,
        // `failed_attempts` would only ever grow and an exit's backoff window
        // would lengthen for the rest of its life in the pool.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east', ['failed_attempts' => 2]);
        $this->makeProxy('us-east', ['failed_attempts' => 2]);
        $this->scriptTarget([200]);

        $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(1, $this->exitOfRequest(0)->fresh()->failed_attempts);
        $this->assertSame(2, $this->otherExit($this->exitOfRequest(0))->failed_attempts);
    }

    public function test_an_unresolvable_proxy_is_penalised_and_the_probe_moves_to_another_exit(): void
    {
        // errno 5 is `CURLE_COULDNT_RESOLVE_PROXY`: curl never even got a name
        // for our own exit, so the target is not implicated in any way.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(5, 'Could not resolve proxy: gone.exit.example'),
            200,
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Up, $reading->status);
        $this->assertFalse($reading->probeRefused);
        $this->assertCount(2, $this->seenOptions);

        // The retry left through a DIFFERENT exit; retrying the same one would
        // be a slower way of reproducing the same failure.
        $this->assertNotSame($this->seenOptions[0]['proxy'], $this->seenOptions[1]['proxy']);

        $burnt = $this->exitOfRequest(0);
        $this->assertSame(1, $burnt->fresh()->failed_attempts);
        $this->assertNotNull($burnt->fresh()->available_at);
    }

    public function test_a_proxy_layer_error_is_penalised_the_same_way(): void
    {
        // errno 97 is `CURLE_PROXY`, the other code that names the proxy itself.
        // Guzzle's `connectionErrors` list excludes both 5 and 97, so neither
        // arrives as a Guzzle ConnectException; the errno is the only evidence.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(97, 'CONNECT tunnel failed, response 502'),
            200,
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Up, $reading->status);
        $this->assertSame(1, $this->exitOfRequest(0)->fresh()->failed_attempts);
        $this->assertCount(2, $this->seenOptions);
    }

    public function test_an_ambiguous_failure_is_blamed_on_the_exit_only_once_another_exit_answers(): void
    {
        // errno 7 is the whole problem: a dead proxy and a dead target produce
        // it identically. The second exit answering is what turns the ambiguity
        // into evidence, retroactively.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(7, "Failed to connect to 203.0.113.1 port 8001: Couldn't connect to server"),
            200,
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Up, $reading->status);
        $this->assertSame(200, $reading->statusCode);

        $suspect = $this->exitOfRequest(0);
        $this->assertSame(1, $suspect->fresh()->failed_attempts);
        $this->assertSame(0, $this->otherExit($suspect)->failed_attempts);
    }

    public function test_every_exit_failing_the_same_ambiguous_way_is_an_honest_down(): void
    {
        $monitor = $this->systemMonitor(['url' => 'https://example.com/health?token=s3cret']);
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(7, "Failed to connect to 203.0.113.1 port 8001: Couldn't connect to server"),
            $this->curlFailure(7, "Failed to connect to 203.0.113.2 port 8002: Couldn't connect to server"),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        // A verdict, not a refusal: two independent exits could not reach the
        // target, which is the closest thing to evidence this design can get.
        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertFalse($reading->probeRefused);
        $this->assertNull($reading->statusCode);
        $this->assertNull($reading->responseMs);
        $this->assertCount(2, $this->seenOptions);

        // The exits are EXONERATED by the same reasoning that convicts the
        // target. Penalising them here would drain a region's pool every time a
        // popular target had a real outage.
        foreach (Proxy::all() as $exit) {
            $this->assertSame(0, $exit->failed_attempts);
        }

        // Operator-readable, and free of the query string: curl's own message
        // appends ` for <url>`, and this string is stored on the check and
        // rendered in the UI, where a target's token must not appear. Same rule
        // as the worker's `probeTarget()`.
        $this->assertStringContainsString('example.com', (string) $reading->errorMessage);
        $this->assertStringContainsString("Couldn't connect to server", (string) $reading->errorMessage);
        $this->assertStringNotContainsString('s3cret', (string) $reading->errorMessage);
    }

    public function test_a_timeout_is_ambiguous_too_and_a_thin_region_still_produces_a_verdict(): void
    {
        // One exit, one ambiguous failure (errno 28, `CURLE_OPERATION_TIMEDOUT`)
        // and no alternate exit to corroborate it. This is deliberately a
        // verdict rather than a refusal: refusing whenever a region is thin
        // would mean a genuinely down target in a one-exit region NEVER opens an
        // incident, which is the mirror-image failure of a fabricated outage.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(28, 'Operation timed out after 10001 milliseconds with 0 bytes received'),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertFalse($reading->probeRefused);
        $this->assertStringContainsString('Operation timed out', (string) $reading->errorMessage);
        $this->assertCount(1, $this->seenOptions);
        $this->assertSame(0, Proxy::query()->sole()->failed_attempts);
    }

    public function test_our_own_failure_with_no_exit_left_to_try_is_a_refusal_not_a_down(): void
    {
        // The asymmetry that carries the whole design: one exit, and it failed in
        // a way that names ITSELF. There is no second exit to ask, so nothing
        // here is evidence about the target and no verdict may be published.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(5, 'Could not resolve proxy: gone.exit.example'),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertTrue($reading->probeRefused);
        $this->assertNull($reading->statusCode);
        $this->assertStringContainsString('us-east', (string) $reading->errorMessage);
        $this->assertStringContainsString('Could not resolve proxy', (string) $reading->errorMessage);
        $this->assertSame(1, Proxy::query()->sole()->failed_attempts);
    }

    public function test_the_ladder_never_exceeds_the_configured_attempt_ceiling(): void
    {
        // Three healthy exits, a ceiling of two. An unbounded ladder against a
        // genuinely down target multiplies load on the target AND burns the
        // whole regional pool on one check.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(7, "Failed to connect: Couldn't connect to server"),
            $this->curlFailure(7, "Failed to connect: Couldn't connect to server"),
            $this->curlFailure(7, "Failed to connect: Couldn't connect to server"),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertCount(2, $this->seenOptions);
        $this->assertSame(MonitorStatus::Down, $reading->status);
    }

    public function test_a_407_is_our_exit_failing_and_is_never_recorded_as_the_targets_answer(): void
    {
        // 407 `Proxy Authentication Required` is the one HTTP status on this path
        // that CANNOT be the target's answer: only a proxy sends it. So the
        // never-rotate-on-an-HTTP-answer rule cannot be what catches it, and
        // recording it would publish a fabricated `down` for every catalog
        // monitor the moment a provider rotated our credentials.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([407, 200]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Up, $reading->status);
        $this->assertSame(200, $reading->statusCode);
        $this->assertCount(2, $this->seenOptions);

        $rejected = $this->exitOfRequest(0);
        $this->assertSame(1, $rejected->fresh()->failed_attempts);
        $this->assertSame(0, $this->otherExit($rejected)->failed_attempts);
    }

    public function test_every_exit_answering_407_is_a_refusal_rather_than_a_verdict(): void
    {
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([407, 407]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        // Not a 407 reading, not a down: we learned nothing about the target.
        $this->assertTrue($reading->probeRefused);
        $this->assertNull($reading->statusCode);
        $this->assertStringContainsString('407', (string) $reading->errorMessage);

        foreach (Proxy::all() as $exit) {
            $this->assertSame(1, $exit->failed_attempts);
        }

        app(CheckPersistenceService::class)->persist($monitor, $reading);

        $this->assertSame(0, MonitorCheck::query()->count());
    }

    public function test_a_tunnel_our_exit_refused_is_a_refusal_and_never_a_down(): void
    {
        // A proxy answering 407 to CONNECT is the 407 case one layer lower down,
        // and it does NOT arrive as an HTTP status: the request never got past the
        // tunnel, so there is no response for the `PROXY_AUTH_REQUIRED` branch to
        // see. curl reports errno 56 (`CURLE_RECV_ERROR`) with its own message
        // naming the tunnel, measured on this machine and pinned live in
        // {@see LocalProbeEngineTest::test_a_tunnel_the_proxy_refused_is_our_own_failure_on_the_real_wire()}.
        //
        // One exit on purpose: with nothing left to ask, the pre-fix classifier
        // read errno 56 as ambiguous and the ladder published a `down` about a
        // target that was never contacted. That is the fabrication this test
        // reddens on.
        $this->assertARefusedTunnelIsOurOwnFailure(
            errno: 56,
            error: 'CONNECT tunnel failed, response 407',
            expectedInMessage: 'CONNECT tunnel failed',
        );
    }

    public function test_the_older_curl_wording_for_a_refused_tunnel_is_recognised_too(): void
    {
        // curl 8.18 says `CONNECT tunnel failed, response 407`; older 8.x says
        // `Received HTTP code 502 from proxy after CONNECT`. BOTH spellings are
        // matched, because this classifier decides whether a public page
        // publishes an outage and the deploy's curl is not pinned by us. The
        // status differs from the case above for the same reason the signatures
        // do not mention one: 407 and 502 are equally the proxy's own answer, and
        // the wording is what identifies it.
        $this->assertARefusedTunnelIsOurOwnFailure(
            errno: 56,
            error: 'Received HTTP code 502 from proxy after CONNECT',
            expectedInMessage: 'from proxy after CONNECT',
        );
    }

    public function test_a_bare_errno_56_stays_ambiguous_because_a_target_can_reset_a_connection_too(): void
    {
        // The counterweight to the two tests above, and the reason errno 56 is
        // NOT in `PROXY_FAULT_ERRNOS`: a target that drops the connection
        // mid-response reports the same code. Blaming the exit on the errno alone
        // would turn a real outage into a refusal and page nobody, so without the
        // tunnel wording this is an ambiguous failure and still yields a verdict.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(56, 'Recv failure: Connection reset by peer'),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertFalse($reading->probeRefused);
        $this->assertStringContainsString('Connection reset by peer', (string) $reading->errorMessage);
        $this->assertSame(0, Proxy::query()->sole()->failed_attempts);
    }

    public function test_a_region_with_no_healthy_exit_writes_no_check_no_streak_and_no_incident(): void
    {
        // THE CENTRAL SAFETY CLAIM, asserted on the consequence rather than on
        // the flag. `ap` has a configured source and a pool that is entirely out
        // of rotation, which is what a burnt region looks like from here.
        Notification::fake();
        Http::fake();

        $monitor = $this->systemMonitor([
            'consecutive_fails' => 1,
            'incident_threshold' => 2,
            'last_status' => MonitorStatus::Up,
        ]);
        $this->makeProxy('ap', ['enabled' => false]);

        $reading = $this->engine()->dispatch($monitor, 'ap');

        $this->assertTrue($reading->probeRefused);
        $this->assertStringContainsString('[ap]', (string) $reading->errorMessage);
        $this->assertStringContainsString('pool', (string) $reading->errorMessage);
        Http::assertNothingSent();

        app(CheckPersistenceService::class)->persist($monitor, $reading);

        // No row, so nothing on the public page moves. No streak change, so the
        // threshold is never crossed and no incident opens. The three
        // assertions that would each redden if the refusal became a `down`.
        $this->assertSame(0, MonitorCheck::query()->count());
        $this->assertSame(0, Incident::query()->count());

        $monitor->refresh();
        $this->assertSame(1, (int) $monitor->consecutive_fails);
        $this->assertSame(MonitorStatus::Up, $monitor->last_status);

        // And the operator still learns about it, which is the whole difference
        // between a refusal and a silently dropped probe.
        $this->assertStringContainsString('[ap]', (string) $monitor->last_probe_error);
        $this->assertNotNull($monitor->last_probe_error_at);

        Notification::assertNothingSent();
    }

    public function test_a_403_penalises_nothing_and_is_recorded_as_a_check_row(): void
    {
        // A 403 is the TARGET's answer and is recorded as one, exit untouched.
        // Rotating away from a block is what `resources/legal/bot.en.md:65-66`
        // publishes that this product does not do.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([403]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertSame(403, $reading->statusCode);
        $this->assertFalse($reading->probeRefused);
        $this->assertCount(1, $this->seenOptions);

        foreach (Proxy::all() as $exit) {
            $this->assertSame(0, $exit->failed_attempts);
            $this->assertNull($exit->available_at);
        }

        app(CheckPersistenceService::class)->persist($monitor, $reading);

        $this->assertSame(403, (int) MonitorCheck::query()->sole()->status_code);
    }

    /**
     * A refused CONNECT tunnel is OUR exit failing: no verdict, no check row, and
     * the exit out of rotation.
     *
     * Shared by the two spellings above rather than duplicated, because the claim
     * is one claim and only curl's wording differs. Deliberately runs with a
     * SINGLE exit: that is the configuration where a misclassification is not
     * merely a slower probe but a fabricated outage, since there is no second exit
     * whose failure could turn the ambiguity into evidence.
     */
    protected function assertARefusedTunnelIsOurOwnFailure(int $errno, string $error, string $expectedInMessage): void
    {
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure($errno, $error),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertTrue(
            $reading->probeRefused,
            "A tunnel the exit refused ({$error}) was published as a verdict about the target.",
        );
        $this->assertNull($reading->statusCode);
        $this->assertStringContainsString('us-east', (string) $reading->errorMessage);
        $this->assertStringContainsString($expectedInMessage, (string) $reading->errorMessage);

        // The exit named itself, so it leaves rotation immediately rather than
        // waiting for a later exit to corroborate anything.
        $this->assertSame(1, Proxy::query()->sole()->failed_attempts);
        $this->assertNotNull(Proxy::query()->sole()->available_at);

        // The consequence, which is what the flag is for: nothing reaches the
        // public page and no streak moves.
        app(CheckPersistenceService::class)->persist($monitor, $reading);

        $this->assertSame(0, MonitorCheck::query()->count());
    }

    /**
     * Answer the engine's requests in order, so an attempt's outcome can be
     * scripted independently of WHICH exit `ProxyPool::take()` randomly drew.
     *
     * An int is a status code; a closure is a transport failure to throw.
     *
     * @param  list<int|Closure>  $script
     */
    protected function scriptTarget(array $script): void
    {
        Http::fake(function (Request $request, array $options) use ($script) {
            $step = $script[count($this->seenOptions)] ?? null;
            $this->seenOptions[] = $options;

            if ($step === null) {
                throw new RuntimeException('The engine issued more requests than the script answers.');
            }

            if ($step instanceof Closure) {
                return $step($request);
            }

            return Http::response('<html>a body nothing here may read</html>', $step, [
                'Content-Type' => 'text/html',
            ]);
        });
    }

    /**
     * The exception a real curl transport failure arrives as.
     *
     * Thrown as GUZZLE's own exception with a handler context, never as the outer
     * `Illuminate\Http\Client\ConnectionException`: Laravel marshals this into
     * that pair itself, and hand-building the outer one would test a shape
     * production never produces.
     *
     * WHICH Guzzle class matters, and it is not a free choice. `CurlFactory`
     * picks it off a fixed `connectionErrors` list (`CurlFactory.php:1082-1088`):
     * errnos 6, 7, 28, 35 and 52 become a `ConnectException`, and EVERYTHING
     * else, 5 and 97 included, becomes a `RequestException`. The two classes
     * share no ancestor carrying `getHandlerContext()`, so an engine that knows
     * only one of them reads an empty context for half of all real failures. This
     * helper reproduces that split rather than picking one shape and testing the
     * engine against a fiction.
     */
    protected function curlFailure(int $errno, string $error): Closure
    {
        return function (Request $request) use ($errno, $error): never {
            $message = "cURL error {$errno}: {$error} "
                .'(see https://curl.se/libcurl/c/libcurl-errors.html) '
                ."for {$request->url()}";

            $context = [
                'errno' => $errno,
                'error' => $error,
            ];

            throw in_array($errno, [6, 7, 28, 35, 52], true)
                ? new GuzzleConnectException($message, $request->toPsrRequest(), null, $context)
                : new GuzzleRequestException($message, $request->toPsrRequest(), null, null, $context);
        };
    }

    protected function engine(): LocalProbeEngine
    {
        return new LocalProbeEngine(new ProxyPool);
    }

    /**
     * The exit the Nth request left through, resolved from what actually went on
     * the wire: `take()` picks randomly, so "the first exit" is not knowable
     * from the seeding order.
     */
    protected function exitOfRequest(int $index): Proxy
    {
        $proxy = (string) ($this->seenOptions[$index]['proxy'] ?? '');
        [$host, $port] = explode(':', str_replace('http://', '', $proxy));

        return Proxy::query()->where('host', $host)->where('port', (int) $port)->sole();
    }

    protected function otherExit(Proxy $exit): Proxy
    {
        return Proxy::query()->whereKeyNot($exit->getKey())->sole();
    }

    /**
     * A monitor owned by the one internal team this engine may probe for.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function systemMonitor(array $attributes = []): Monitor
    {
        return Monitor::query()->create([
            'team_id' => SystemTeam::resolve()->id,
            'name' => 'Catalog probe',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'regions' => ['us-east'],
            'check_interval_sec' => 60,
            'timeout_sec' => 10,
            'expected_status_code' => 200,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            ...$attributes,
        ]);
    }

    /**
     * A persisted exit in the given region, healthy unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeProxy(string $region, array $overrides = []): Proxy
    {
        $source = ProxySource::query()->firstOrCreate(
            ['region' => $region],
            ['kind' => 'url', 'location' => "https://example.com/{$region}.txt"],
        );

        $sequence = ++$this->proxySequence;

        return Proxy::query()->create([
            'proxy_source_id' => $source->id,
            'region' => $region,
            'host' => "203.0.113.{$sequence}",
            'port' => 8000 + $sequence,
            'credentials' => [
                'username' => 'exit-user',
                'password' => 'secret',
            ],
            'enabled' => true,
            'last_refreshed_at' => now(),
            ...$overrides,
        ]);
    }
}
