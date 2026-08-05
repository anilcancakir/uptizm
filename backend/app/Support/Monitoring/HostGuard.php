<?php

namespace App\Support\Monitoring;

use Illuminate\Validation\ValidationException;

/**
 * Stateless SSRF guard for user-supplied monitor targets.
 *
 * A monitor probe is executed by the platform's own relay workers, so a
 * tenant-controlled URL that resolves to an internal address would let a
 * tenant reach the platform's private network (cloud metadata endpoints,
 * RFC1918 hosts, loopback). This guard rejects any host that resolves to a
 * loopback, RFC1918, link-local, IPv6 ULA, or reserved `.internal` address.
 *
 * The logic lives here (not inline on the form requests) so the Store and
 * Update monitor requests share one implementation and the relay/service
 * layer can enforce the same denylist through {@see self::assertUrlAllowed()}.
 */
class HostGuard
{
    /**
     * IPv4 CIDR ranges a monitor host is never allowed to resolve into.
     *
     * Covers the "this host" block (0.0.0.0/8, where 0.0.0.0 reaches localhost
     * on Linux), loopback, the three RFC1918 private blocks, and the link-local
     * range that fronts cloud metadata endpoints (169.254.169.254).
     *
     * @var list<string>
     */
    protected const array BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
    ];

    /**
     * Determine whether a host is disallowed as a monitor target.
     *
     * Normalizes the host, rejects the reserved `localhost` / `*.internal`
     * names outright, then resolves the host to its candidate IPs and
     * reports blocked when any of them falls inside a denied range. Hosts
     * that cannot be resolved are allowed: an unresolvable host cannot be
     * probed and carries no SSRF risk.
     *
     * @param  string  $host  A bare host (not a full URL).
     * @return bool True when the host must not be probed.
     */
    public function isBlockedHost(string $host): bool
    {
        // 1. Normalize: lowercase and strip IPv6 literal brackets.
        $host = strtolower(trim($host, '[]'));

        if ($host === '') {
            return false;
        }

        // 2. Reject reserved names that never resolve to a public host.
        if ($host === 'localhost' || str_ends_with($host, '.internal')) {
            return true;
        }

        // 3. Reject when any resolved address falls in a blocked range.
        foreach ($this->resolveHostIps($host) as $ip) {
            if ($this->isBlockedIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The public IPs a host resolves to, or an empty list when it resolves to
     * none or to any address this guard denies.
     *
     * Exists because a caller that wants to know something ABOUT a target's
     * address, rather than whether it may be probed, had no entry point:
     * {@see self::isBlockedHost()} resolves internally and returns only a
     * boolean, and {@see self::resolveAndAssertAllowed()} is https-only and
     * refuses a URL carrying a port or credentials, which an ordinary monitor
     * target may well have. Adding a second resolver elsewhere in the codebase
     * was the alternative, and this class is deliberately the only DNS code in
     * the backend.
     *
     * Fail-closed and all-or-nothing on purpose: one denied address discards
     * the whole list rather than returning the survivors, because a host that
     * resolves to both a public and an internal address is exactly the
     * DNS-rebinding shape the rest of this class exists to refuse, and a
     * caller handed the public half would treat it as a clean answer.
     *
     * @param  string  $host  A bare host (not a full URL).
     * @return list<string> The resolved addresses, or `[]`.
     */
    public function resolvePublicHostIps(string $host): array
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.internal')) {
            return [];
        }

        $ips = $this->resolveHostIps($host);

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                return [];
            }
        }

        return $ips;
    }

    /**
     * Assert a full URL points at an allowed host, or throw.
     *
     * Parses the host out of the URL and raises a validation error keyed on
     * `url` when the host is missing or {@see self::isBlockedHost()} rejects
     * it. Callers outside the form-request layer (relay dispatch, service
     * jobs) use this to enforce the same denylist as request validation.
     *
     * @param  string  $url  The monitor target URL.
     *
     * @throws ValidationException When the URL has no host or targets an
     *                             internal address.
     */
    public function assertUrlAllowed(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw ValidationException::withMessages([
                'url' => 'The url must contain a valid host.',
            ]);
        }

        if ($this->isBlockedHost($host)) {
            throw ValidationException::withMessages([
                'url' => 'The url host is not allowed.',
            ]);
        }
    }

    /**
     * Assert an outbound webhook URL is safe to POST to and return its IPs.
     *
     * This is a stricter policy than {@see self::assertUrlAllowed()} (which
     * governs monitor probes and leaves scheme/port/credentials untouched):
     * an outbound webhook carries a tenant signing secret and is sent by the
     * platform itself, so the URL must be plain `https://host/path` with no
     * embedded credentials and no explicit port, and its host must resolve
     * entirely outside the SSRF denylist.
     *
     * The resolved addresses are returned so the caller can pin the connection
     * to the exact IP(s) validated here. Pinning (rather than a second check
     * at connect time) is what actually closes the DNS-rebinding window: a
     * re-resolution between validation and connect could return a different,
     * internal address; binding to the validated set cannot.
     *
     * @param  string  $url  The tenant-supplied webhook URL.
     * @return list<string> The validated resolved IPs to pin the connection to.
     *
     * @throws ValidationException When the scheme is not https, the URL carries
     *                             credentials or a port, the host is missing or
     *                             reserved, cannot be resolved, or any resolved
     *                             address falls inside a blocked range.
     */
    public function resolveAndAssertAllowed(string $url): array
    {
        $parts = parse_url($url);

        // 1. A malformed URL has no safe interpretation; reject outright.
        if (! is_array($parts)) {
            throw ValidationException::withMessages([
                'url' => 'The url is malformed.',
            ]);
        }

        // 2. Only https: a signing secret must never travel in cleartext, and
        //    non-http(s) schemes (file, gopher, ...) are classic SSRF vectors.
        if (($parts['scheme'] ?? null) !== 'https') {
            throw ValidationException::withMessages([
                'url' => 'The url must use the https scheme.',
            ]);
        }

        // 3. Reject embedded credentials or an explicit port: userinfo
        //    confusion and non-standard ports are internal-reach vectors.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw ValidationException::withMessages([
                'url' => 'The url must not contain credentials or a port.',
            ]);
        }

        $host = $parts['host'] ?? null;
        if (! is_string($host) || $host === '') {
            throw ValidationException::withMessages([
                'url' => 'The url must contain a valid host.',
            ]);
        }

        $host = strtolower(trim($host, '[]'));

        // 4. Reject reserved names that never front a public host.
        if ($host === 'localhost' || str_ends_with($host, '.internal')) {
            throw ValidationException::withMessages([
                'url' => 'The url host is not allowed.',
            ]);
        }

        // 5. Resolve exactly once. An unresolvable host cannot be pinned, so
        //    (unlike a monitor probe) it is rejected rather than allowed.
        $ips = $this->resolveHostIps($host);

        if ($ips === []) {
            throw ValidationException::withMessages([
                'url' => 'The url host could not be resolved.',
            ]);
        }

        // 6. Block when any resolved address is internal, then hand the exact
        //    validated set back for connection pinning.
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw ValidationException::withMessages([
                    'url' => 'The url host is not allowed.',
                ]);
            }
        }

        return $ips;
    }

    /**
     * Resolve a host to its candidate IP addresses.
     *
     * A dotted IPv4 / bracket-stripped IPv6 literal resolves to itself; an
     * integer, hex, or octal IPv4 literal (e.g. `2130706433`, `0x7f000001`)
     * normalizes to dotted-quad first so the range check sees the real
     * address; a hostname resolves via DNS to both its A and AAAA records.
     * A resolution failure yields an empty list (the host is treated as
     * unreachable, not blocked).
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        // 1. A canonical dotted IPv4 or IPv6 literal resolves to itself.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // 2. A bare numeric literal can masquerade as an IPv4 address
        //    (`http://2130706433` is 127.0.0.1); normalize before checking.
        $numeric = $this->normalizeNumericIpv4($host);
        if ($numeric !== null) {
            return [$numeric];
        }

        // 3. A hostname resolves via DNS to its A and AAAA records so an
        //    internal-only IPv6 target (::1, ULA) is caught as well as IPv4.
        return [
            ...$this->resolveIpv4($host),
            ...$this->resolveIpv6($host),
        ];
    }

    /**
     * Normalize an integer, hex, or octal IPv4 literal to dotted-quad.
     *
     * Mirrors `inet_aton`'s numeric forms: decimal (`2130706433`), hex
     * (`0x7f000001`), and octal (`017700000001`). Returns null when the host
     * is not a bare numeric literal or falls outside the 32-bit range, so a
     * real hostname flows on to DNS resolution untouched.
     */
    protected function normalizeNumericIpv4(string $host): ?string
    {
        if (preg_match('/^(0x[0-9a-f]+|0[0-7]*|[1-9][0-9]*)$/i', $host) !== 1) {
            return null;
        }

        // Base 0 auto-detects the 0x (hex) and 0 (octal) prefixes.
        $long = intval($host, 0);
        if ($long < 0 || $long > 0xFFFFFFFF) {
            return null;
        }

        return long2ip($long);
    }

    /**
     * Resolve a hostname's IPv4 (A record) addresses.
     *
     * @return list<string>
     */
    protected function resolveIpv4(string $host): array
    {
        $resolved = gethostbynamel($host);

        return $resolved === false ? [] : array_values($resolved);
    }

    /**
     * Resolve a hostname's IPv6 (AAAA record) addresses.
     *
     * @return list<string>
     */
    protected function resolveIpv6(string $host): array
    {
        if (! checkdnsrr($host, 'AAAA')) {
            return [];
        }

        $records = dns_get_record($host, DNS_AAAA);
        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ipv6'] ?? null,
            $records,
        )));
    }

    /**
     * Determine whether an IP address falls inside a blocked range.
     */
    protected function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->ipv4IsBlocked($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // An IPv4-mapped IPv6 literal (::ffff:169.254.169.254) reaches the
            // same IPv4 host, so unwrap it and run the embedded address through
            // the IPv4 ranges; without this the mapping bypasses the denylist.
            $mapped = $this->mappedIpv4($ip);
            if ($mapped !== null) {
                return $this->ipv4IsBlocked($mapped);
            }

            return $this->ipv6IsBlocked($ip);
        }

        return false;
    }

    /**
     * Determine whether an IPv4 address falls inside any blocked range.
     */
    protected function ipv4IsBlocked(string $ip): bool
    {
        foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
            if ($this->ipv4InCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the embedded dotted IPv4 of an IPv4-mapped IPv6 address
     * (`::ffff:a.b.c.d`), or null when the address is a native IPv6.
     *
     * The mapping is 10 zero bytes followed by `0xFFFF`, then the 4-byte IPv4
     * address, so a blocked internal target cannot slip through by being
     * expressed in its IPv6-mapped form.
     */
    protected function mappedIpv4(string $ip): ?string
    {
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        if (substr($packed, 0, 12) !== "\0\0\0\0\0\0\0\0\0\0\xFF\xFF") {
            return null;
        }

        $dotted = inet_ntop(substr($packed, 12, 4));

        return $dotted === false ? null : $dotted;
    }

    /**
     * Test whether an IPv4 address sits inside the given CIDR block.
     */
    protected function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $prefix = (int) $bits;

        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $ipLong = ip2long($ip) & 0xFFFFFFFF;
        $subnetLong = ip2long($subnet) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Test whether an IPv6 address is loopback (`::1`), unspecified (`::`),
     * ULA (`fc00::/7`), or link-local (`fe80::/10`).
     */
    protected function ipv6IsBlocked(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        // Loopback ::1 and the unspecified :: (binds every local interface).
        if ($packed === inet_pton('::1') || $packed === inet_pton('::')) {
            return true;
        }

        // Unique local addresses fc00::/7: the top 7 bits are 1111110,
        // so the first byte masked with 0xFE equals 0xFC (covers fc/fd).
        if ((ord($packed[0]) & 0xFE) === 0xFC) {
            return true;
        }

        // Link-local fe80::/10: first byte 0xFE and the top two bits of the
        // second byte are 10 (fronts IPv6 auto-config / metadata addresses).
        return ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80;
    }
}
