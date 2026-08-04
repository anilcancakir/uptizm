<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\ProbeRegionHealth;
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
 * The evidence curl hands us is thin, and the error CODE is not the
 * discriminator. Two codes name the proxy outright (5
 * `CURLE_COULDNT_RESOLVE_PROXY`, 97 `CURLE_PROXY`), and one HTTP status cannot
 * have come from the target at all (407, which only a proxy sends). Everything
 * else needs the MESSAGE, because libcurl overloads its codes and has moved this
 * particular failure between two of them: a CONNECT reply the proxy refused
 * arrives as errno 56 up to libcurl 8.19.0 and as errno 7 from 8.20.0, with the
 * same wording either way, while errno 56 also covers a target resetting a
 * connection and errno 7 also covers our own exit's port being shut. Worse, that
 * one message covers BOTH blames: `response 407` is the proxy speaking about
 * itself and `response 502` is the proxy reporting that the ORIGIN refused, which
 * is a real outage. So the code inside the reply decides, the errno only narrows.
 *
 * Where the message names nothing, the failure is genuinely ambiguous and the only
 * way to resolve it is to ask a SECOND exit in the same region: if that one
 * answers, the first exit was the problem; if it fails the same way, the target
 * genuinely is unreachable.
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

    public function test_a_connect_failure_names_the_proxy_because_the_proxy_is_the_only_host_we_dial(): void
    {
        // errno 7 is `CURLE_COULDNT_CONNECT`, and on THIS path it cannot be about
        // the target: every probe carries an explicit exit, so the proxy is the
        // only host curl opens a TCP connection to and the target is reached by
        // the proxy. Measured on curl 8.7.1 through a local CONNECT proxy: a
        // closed proxy port gives errno 7, while a healthy proxy whose origin
        // refused gives errno 56 `CONNECT tunnel failed, response 502`. So a dead
        // target cannot arrive here as a 7.
        //
        // This is the provider-wide outage case (a suspended account, a lapsed
        // bill, a dead network path): every exit fails to connect. Reading it as
        // ambiguous would publish a `down` on eight public catalog pages about
        // targets nothing ever reached.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(7, "Failed to connect to 203.0.113.1 port 8001: Couldn't connect to server"),
            $this->curlFailure(7, "Failed to connect to 203.0.113.2 port 8002: Couldn't connect to server"),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertTrue($reading->probeRefused);
        $this->assertStringContainsString('our own side', (string) $reading->errorMessage);

        // Both exits leave rotation, and the region is recorded as having
        // produced nothing, which is what the dark-region alarm counts.
        foreach (Proxy::all() as $exit) {
            $this->assertSame(1, $exit->failed_attempts);
        }

        $health = ProbeRegionHealth::query()->where('region', 'us-east')->sole();
        $this->assertNotNull($health->last_failure_at);
        $this->assertNull($health->last_success_at);
    }

    public function test_the_same_tunnel_refusal_is_the_targets_outage_on_the_errno_libcurl_moved_it_to(): void
    {
        // libcurl OVERLOADED errno 7. Up to 8.19.0 a non-2xx CONNECT reply returned
        // `CURLE_RECV_ERROR` (56); from 8.20.0 the identical failure with the
        // identical message returns `CURLE_COULDNT_CONNECT` (7). Verified in the
        // shipped source at the tags: `curl-8_19_0:lib/cf-h1-proxy.c` returns
        // `CURLE_RECV_ERROR` and `curl-8_20_0` / `curl-8_21_0` return
        // `CURLE_COULDNT_CONNECT` beneath the same `failf(... "CONNECT tunnel
        // failed, response %d" ...)`.
        //
        // So this is the case that CANNOT be classified by errno. This machine's PHP
        // links 8.18.0, so no local measurement can reach it, and a blanket
        // proxy-fault rule for errno 7 would convert every genuinely down HTTPS
        // catalog target into a refusal that publishes nothing the moment the box's
        // libcurl is upgraded, which the plan's own CVE remediation requires.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(7, 'CONNECT tunnel failed, response 502'),
            $this->curlFailure(7, 'CONNECT tunnel failed, response 502'),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertFalse(
            $reading->probeRefused,
            'A proxy reporting that the ORIGIN refused is a verdict about the target, on either errno.',
        );

        foreach (Proxy::all() as $exit) {
            $this->assertSame(0, $exit->failed_attempts, 'A real target outage must not drain the pool.');
        }
    }

    public function test_a_407_stays_our_own_failure_on_the_errno_libcurl_moved_it_to(): void
    {
        // The other side of the same overload: on libcurl >= 8.20.0 a 407 CONNECT
        // reply is ALSO errno 7. The message is what separates it from the 502
        // above, and a 407 can only be the proxy speaking about itself, so this one
        // is ours and must produce no verdict.
        $this->assertARefusedTunnelIsOurOwnFailure(
            errno: 7,
            error: 'CONNECT tunnel failed, response 407',
            expectedInMessage: 'CONNECT tunnel failed',
        );
    }

    public function test_a_tunnel_refused_over_the_origin_is_the_targets_outage_and_not_our_exits(): void
    {
        // The counterweight to the 407 tests below. curl spells EVERY non-2xx
        // CONNECT reply the same way, and 502 is how a forward proxy reports that
        // it could not reach the origin: measured on curl 8.7.1, a healthy proxy
        // whose origin refused the connection gives errno 56 `CONNECT tunnel
        // failed, response 502`, differing from the 407 case by one number.
        //
        // So this has to stay ambiguous. Classifying it as our own failure would
        // convert every genuinely down HTTPS target into a refusal that publishes
        // nothing, on the pages that exist to report exactly that, and would drain
        // the region's pool on every real outage.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(56, 'CONNECT tunnel failed, response 502'),
            $this->curlFailure(56, 'CONNECT tunnel failed, response 502'),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertFalse($reading->probeRefused);
        $this->assertNull($reading->statusCode);

        // Exonerated by the same reasoning that convicts the target.
        foreach (Proxy::all() as $exit) {
            $this->assertSame(0, $exit->failed_attempts);
        }
    }

    public function test_an_ambiguous_failure_is_blamed_on_the_exit_only_once_another_exit_answers(): void
    {
        // errno 28 is the whole problem: a hung proxy and a hung target produce
        // it identically, because the clock runs out without saying which peer
        // stopped answering. The second exit answering is what turns the
        // ambiguity into evidence, retroactively.
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $this->scriptTarget([
            $this->curlFailure(28, 'Operation timed out after 10001 milliseconds with 0 bytes received'),
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
            $this->curlFailure(28, 'Operation timed out after 10001 milliseconds with 0 bytes received'),
            $this->curlFailure(28, 'Operation timed out after 10002 milliseconds with 0 bytes received'),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        // A verdict, not a refusal: two exits of the region could not reach the
        // target, which is the closest thing to evidence this design can get. They
        // are not INDEPENDENT (one region, one source, so one vendor), which is why
        // the wording here is deliberate and why cross-vendor corroboration has to
        // come from the cross-region quorum instead.
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
        $this->assertStringContainsString('Operation timed out', (string) $reading->errorMessage);
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
            $this->curlFailure(28, 'Operation timed out after 10001 milliseconds with 0 bytes received'),
            $this->curlFailure(28, 'Operation timed out after 10002 milliseconds with 0 bytes received'),
            $this->curlFailure(28, 'Operation timed out after 10003 milliseconds with 0 bytes received'),
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
        // curl 8.7 and 8.18 say `CONNECT tunnel failed, response 407`; older 8.x
        // says `Received HTTP code 407 from proxy after CONNECT`. BOTH spellings
        // are matched, because this classifier decides whether a public page
        // publishes an outage and the deploy's curl is not pinned by us.
        //
        // The STATUS is 407 in both, and that is not incidental. An earlier
        // revision of this test used 502 here and stated that "407 and 502 are
        // equally the proxy's own answer", which is false: 502 is how a forward
        // proxy reports that it could not reach the ORIGIN. The test asserted the
        // same wrong rule the classifier had, so it certified the bug rather than
        // catching it. See
        // {@see self::test_a_tunnel_refused_over_the_origin_is_the_targets_outage_and_not_our_exits()}
        // for the case this one must not swallow.
        $this->assertARefusedTunnelIsOurOwnFailure(
            errno: 56,
            error: 'Received HTTP code 407 from proxy after CONNECT',
            expectedInMessage: 'from proxy after CONNECT',
        );
    }

    public function test_a_proxy_dying_inside_the_connect_reply_is_our_own_failure(): void
    {
        // The third spelling, and the one the earlier signature list missed. When the
        // proxy closes the socket part-way through its CONNECT reply, with no 407
        // ahead of it, curl says `Proxy CONNECT aborted` (`lib/cf-h1-proxy.c`) and
        // reports errno 56. A provider dying inside the handshake is exactly what a
        // rented pool does when it goes away, so leaving this ambiguous meant the
        // ladder exhausted and published a fabricated outage on eight public pages
        // about a target nothing had reached.
        $this->assertARefusedTunnelIsOurOwnFailure(
            errno: 56,
            error: 'Proxy CONNECT aborted',
            expectedInMessage: 'Proxy CONNECT aborted',
        );
    }

    public function test_a_connect_that_timed_out_stays_ambiguous_despite_sharing_the_prefix(): void
    {
        // The counterweight to the test above, and the reason the classifier gates on
        // the errno as well as the message. curl's `Proxy CONNECT aborted due to
        // timeout` shares the prefix but arrives as errno 28, and a timeout INSIDE
        // CONNECT may be the proxy waiting on the target rather than the proxy
        // failing. Matching the message alone would have converted a possible real
        // outage into a refusal that pages nobody, which is the opposite error and
        // just as dishonest.
        $monitor = $this->systemMonitor();
        $exit = $this->makeProxy('us-east');

        $this->scriptTarget([
            $this->curlFailure(28, 'Proxy CONNECT aborted due to timeout'),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertFalse(
            $reading->probeRefused,
            'A CONNECT timeout was blamed on the exit, so a target that stopped answering would page nobody.',
        );
        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertSame(
            0,
            $exit->fresh()->failed_attempts,
            'The exit was penalised for a timeout that may have been the target keeping it waiting.',
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

    public function test_the_direct_region_probes_without_a_proxy_and_records_no_exit(): void
    {
        // `us-west` has no source, so it has no pool. Named as the direct region, it
        // leaves from this server instead of refusing, which is what makes "we probe
        // from our own infrastructure" true on a deployment with no provider wired.
        config(['proxy.direct_region' => 'us-west']);

        $monitor = $this->systemMonitor();
        $this->scriptTarget([200]);

        $reading = $this->engine()->dispatch($monitor, 'us-west');

        $this->assertSame(MonitorStatus::Up, $reading->status);
        $this->assertFalse($reading->probeRefused);
        $this->assertNull($reading->exitVia, 'There was no exit, and null is how that column reads absence.');

        // Explicitly empty rather than absent: an ambient `http_proxy` must never
        // become this engine's egress by omission.
        $this->assertCount(1, $this->seenOptions);
        $this->assertSame('', $this->seenOptions[0]['proxy']);
    }

    public function test_a_direct_probe_that_cannot_connect_is_the_targets_outage_and_never_a_refusal(): void
    {
        // THE mirror of the proxied rule, and the reason the direct path is a
        // separate method rather than a flag. With a proxy in the path errno 7 names
        // the proxy, because the proxy is the only host curl dials. With NO proxy it
        // names the target. Running this through the proxy classifier would convert
        // every real outage on the direct region into a refusal that publishes
        // nothing, which is the failure mode this whole transport is judged on.
        config(['proxy.direct_region' => 'us-west']);

        $monitor = $this->systemMonitor();
        $this->scriptTarget([
            $this->curlFailure(7, "Failed to connect to example.com port 443: Couldn't connect to server"),
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-west');

        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertFalse($reading->probeRefused, 'With nothing between us and the target, there is nobody else to blame.');
        $this->assertStringContainsString('directly from this server', (string) $reading->errorMessage);

        // One attempt, not `attempts_per_check`: a second identical request from the
        // same server is more load, not more evidence.
        $this->assertCount(1, $this->seenOptions);

        // And it is a READING, so the region counts as having carried a request.
        $health = ProbeRegionHealth::query()->where('region', 'us-west')->sole();
        $this->assertNotNull($health->last_success_at);
        $this->assertNull($health->last_failure_at);
    }

    public function test_a_pool_is_always_preferred_over_probing_from_this_server(): void
    {
        // The direct path is a fallback, never the default: naming a region that HAS
        // exits must not stop using them, or an operator setting this key would
        // silently collapse the region's real geography onto one server.
        config(['proxy.direct_region' => 'us-east']);

        $monitor = $this->systemMonitor();
        $exit = $this->makeProxy('us-east');
        $this->scriptTarget([200]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame($exit->host.':'.$exit->port, $reading->exitVia);
        $this->assertSame('http://'.$exit->host.':'.$exit->port, $this->seenOptions[0]['proxy']);
    }

    public function test_an_unnamed_region_still_refuses_rather_than_leaving_from_this_server(): void
    {
        // Only the ONE named region may fall back. Any other unsourced region has
        // nowhere to egress from, and probing it from here would label this server's
        // location with a region name it does not have.
        config(['proxy.direct_region' => 'eu-central']);

        $monitor = $this->systemMonitor();

        $reading = $this->engine()->dispatch($monitor, 'us-west');

        $this->assertTrue($reading->probeRefused);
        $this->assertSame([], $this->seenOptions, 'A refusal makes no request at all.');
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
