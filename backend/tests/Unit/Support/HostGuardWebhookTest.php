<?php

namespace Tests\Unit\Support;

use App\Support\Monitoring\HostGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Locks the outbound-webhook SSRF contract added on top of the monitor guard:
 * `resolveAndAssertAllowed()` enforces https-only, no credentials/port, and a
 * host that resolves entirely outside the denylist, then returns the exact
 * resolved IP set the caller pins the connection to.
 *
 * The pinned-set assertion is the DNS-rebinding coverage the `Http::fake`
 * feature path cannot exercise: the value returned here is the same IP set the
 * connection is bound to, so validate-time and connect-time cannot diverge.
 */
class HostGuardWebhookTest extends TestCase
{
    /** A host whose resolution lands in a blocked range is rejected. */
    public function test_it_rejects_a_host_resolving_to_a_blocked_ip(): void
    {
        $guard = $this->guardResolvingTo(['10.0.0.5']);

        $this->expectException(ValidationException::class);

        $guard->resolveAndAssertAllowed('https://rebind.test/hook');
    }

    /** The returned pinned-IP set equals the exact validated resolution. */
    public function test_it_returns_the_exact_validated_resolution_as_the_pinned_ip_set(): void
    {
        $resolved = [
            '203.0.113.5',
            '203.0.113.6',
        ];
        $guard = $this->guardResolvingTo($resolved);

        $this->assertSame($resolved, $guard->resolveAndAssertAllowed('https://hooks.test/hook'));
    }

    /** A non-https scheme is rejected: a webhook secret never travels cleartext. */
    public function test_it_rejects_a_non_https_scheme(): void
    {
        $this->expectException(ValidationException::class);

        (new HostGuard)->resolveAndAssertAllowed('http://example.com/hook');
    }

    /** A URL carrying embedded credentials is rejected. */
    public function test_it_rejects_a_url_carrying_credentials(): void
    {
        $this->expectException(ValidationException::class);

        (new HostGuard)->resolveAndAssertAllowed('https://user:pass@example.com/hook');
    }

    /** A URL carrying an explicit port is rejected. */
    public function test_it_rejects_a_url_carrying_an_explicit_port(): void
    {
        $this->expectException(ValidationException::class);

        (new HostGuard)->resolveAndAssertAllowed('https://example.com:8443/hook');
    }

    /** An unresolvable host cannot be pinned, so it is rejected. */
    public function test_it_rejects_an_unresolvable_host(): void
    {
        $guard = $this->guardResolvingTo([]);

        $this->expectException(ValidationException::class);

        $guard->resolveAndAssertAllowed('https://void.test/hook');
    }

    /**
     * A HostGuard whose DNS resolution is stubbed to a fixed IP set, so the
     * rebinding contract (validate-and-pin the exact resolved IPs) is testable
     * without depending on live DNS.
     *
     * @param  list<string>  $ips  The IP set the stubbed resolver returns.
     */
    private function guardResolvingTo(array $ips): HostGuard
    {
        return new class($ips) extends HostGuard
        {
            /**
             * @param  list<string>  $ips
             */
            public function __construct(
                private array $ips,
            ) {}

            protected function resolveHostIps(string $host): array
            {
                return $this->ips;
            }
        };
    }
}
