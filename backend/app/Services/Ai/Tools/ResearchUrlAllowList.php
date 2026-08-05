<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\MetricDiscoveryPayload;
use App\Support\Monitoring\HostGuard;

/**
 * The per-run set of hosts the model is allowed to fetch: the minted answer
 * space for {@see WebFetchTool}'s `url` argument.
 *
 * This is {@see MetricDiscoveryPayload::isKnownRef()}'s indirection applied to a
 * URL. There, the model may only answer with an extraction ref the backend
 * generated; here, it may only fetch a host the backend already put in front of
 * it: the monitor's own target, plus the hosts of results a search returned IN
 * THE SAME RUN. A host the model composed itself resolves to nothing and the
 * fetch is refused, so a page of attacker-authored text cannot talk the model
 * into reaching an address of the attacker's choosing.
 *
 * ONE INSTANCE PER ANALYZE, SHARED BY BOTH TOOLS
 *
 * The object is mutable and deliberately not readonly: a search EXTENDS it. It
 * is also scoped to a single run and must never be cached, bound as a singleton,
 * or reused across requests, because a host minted for one team's target would
 * then be fetchable while analyzing another team's.
 *
 * MINTING IS LENIENT, ADMITTING IS STRICT
 *
 * {@see mint()} takes only the host out of a URL the BACKEND obtained, and does
 * not care about its scheme or port: a monitor target is `http` or `https` and a
 * TCP monitor's target is neither. It does require an absolute URL with a host,
 * so a target written as bare `host:port` seeds nothing and the model has to
 * search before it can fetch anything.
 *
 * {@see allows()} judges a URL the MODEL composed, so it is strict on every axis
 * a looser rule would leak through, and the comparison is on the exact
 * normalized host rather than on any kind of suffix:
 *
 *   - `evil-example.com` and `example.com.evil.net` both contain a minted
 *     `example.com`; a `str_ends_with` or `str_contains` rule admits them.
 *   - `sub.example.com` is a different host and may belong to somebody else on
 *     a shared domain, so a minted parent does not vouch for it.
 *   - `https://example.com@evil.net/` reads as the minted host and resolves to
 *     `evil.net`, which is why any user-info at all is refused.
 *   - A backslash is where PHP's parser and the WHATWG one disagree
 *     (`https://a.com\@b.net` is host `b.net` to `parse_url` and host `a.com` to
 *     a browser). A disagreement between the checker and the fetcher is exactly
 *     the bug class this file exists to prevent, so the character is refused.
 *   - A URL carrying another absolute URL in its query is an open-redirect shape
 *     whose fetch would land off the allow list, and the remote fetcher's
 *     redirect policy is not ours to set.
 *   - An IP literal is never a research target (an address has no
 *     documentation) and is how most allowlist bypasses are written.
 *
 * The set holds hosts and nothing else. It never carries a credential, a
 * request header, or any part of `auth_config`, and it performs no I/O:
 * reachability is {@see HostGuard}'s judgement, and both tools apply it in front
 * of every request as the layer behind this one.
 */
class ResearchUrlAllowList
{
    /**
     * Ceiling on how many hosts one run may mint.
     *
     * A search answers with at most a handful of results, so this is only ever
     * reached by something pathological, and then it bounds it.
     */
    public const int MAX_HOSTS = 24;

    /**
     * Ceiling on the length of a URL the model may hand back. Longer than any
     * documentation URL and short enough that nothing is smuggled by volume.
     */
    public const int MAX_URL_LENGTH = 2048;

    /**
     * The maximum length of a DNS name, per RFC 1035.
     */
    protected const int MAX_HOST_LENGTH = 253;

    /**
     * How many times an embedded-URL check re-decodes before giving up.
     *
     * Two is enough for a single and a double encoding, which is every form
     * seen in practice; the check runs at each depth AND after the last one.
     */
    protected const int MAX_DECODE_PASSES = 2;

    /**
     * The minted hosts, keyed by host so a repeat mint cannot grow the set.
     *
     * @var array<string, true>
     */
    protected array $hosts = [];

    /**
     * Seed the run with the monitor target's own host.
     *
     * @param  string  $targetUrl  The monitor's target URL, as the payload carries it.
     */
    public function __construct(string $targetUrl)
    {
        $this->mint($targetUrl);
    }

    /**
     * Mint the host of a URL the backend obtained, so the model may fetch it.
     *
     * Only ever called with a URL this backend produced or received from the
     * research service, never with one the model composed.
     *
     * @return bool True when the host is now in the set.
     */
    public function mint(string $url): bool
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }

        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));

        if ($host === null) {
            return false;
        }

        if (isset($this->hosts[$host])) {
            return true;
        }

        if (count($this->hosts) >= self::MAX_HOSTS) {
            return false;
        }

        $this->hosts[$host] = true;

        return true;
    }

    /**
     * Determine whether a URL the MODEL supplied may be fetched.
     *
     * The range check that makes the model incapable of choosing a host: a URL
     * whose normalized host is not in the minted set, or whose shape is anything
     * other than a plain `https://host/...`, is refused.
     */
    public function allows(string $url): bool
    {
        $host = $this->admissibleHost($url);

        return $host !== null && isset($this->hosts[$host]);
    }

    /**
     * Whether a URL's SHAPE is admissible, ignoring membership.
     *
     * The two halves of {@see allows()} split apart because a caller minting a
     * search result needs the first half BEFORE the second can be true: the host
     * is not in the set yet, and a row whose printed URL could never be fetched
     * should not leave its host behind in the run's set. Everything
     * {@see admissibleHost()} refuses is refused here.
     */
    public function admits(string $url): bool
    {
        return $this->admissibleHost($url) !== null;
    }

    /**
     * The minted hosts, in the order they were minted (the target first).
     *
     * Read by the tools to tell the model what it may actually reach, which is
     * what makes a refusal actionable rather than a dead end.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_keys($this->hosts);
    }

    /**
     * The normalized host of a model-supplied URL, or null when the URL's shape
     * disqualifies it before its host is even considered.
     */
    protected function admissibleHost(string $url): ?string
    {
        // 1. Bound the input, then refuse the characters an https URL cannot
        //    carry raw: a control character or a space is malformed, and a
        //    backslash is where this parser and a browser's disagree.
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
            return null;
        }

        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        // 2. https only. A scheme-relative or schemeless URL has no host to
        //    trust, and every other scheme (`javascript:`, `data:`, `file:`) is
        //    either not a fetch or not one we make.
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        // 3. No user-info, because it reads as a host and resolves as one
        //    somewhere else; no explicit port, because research reaches
        //    documentation on 443 and nothing else.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        // 4. No second absolute URL riding along in the path, query or fragment.
        if ($this->carriesEmbeddedUrl($parts)) {
            return null;
        }

        return $this->normalizeHost($parts['host'] ?? null);
    }

    /**
     * Normalize a host for comparison, or return null when it is not a name
     * this set may ever hold.
     *
     * Minting and admitting share this method on purpose: two normalizations
     * would let the comparison be decided by spelling rather than by identity.
     */
    protected function normalizeHost(mixed $host): ?string
    {
        if (! is_string($host) || $host === '') {
            return null;
        }

        // `EXAMPLE.com.` and `example.com` are the same host, and an IPv6
        // literal arrives bracketed.
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        if ($host === '' || strlen($host) > self::MAX_HOST_LENGTH) {
            return null;
        }

        // An address is not a research target. The numeric forms are listed
        // because `2130706433` and `0x7f000001` are both 127.0.0.1 written so
        // that a name comparison would not notice.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (preg_match('/^(0x[0-9a-f]+|[0-9]+)$/', $host) === 1) {
            return null;
        }

        return $host;
    }

    /**
     * Detect an absolute URL embedded in the part of a URL after its authority.
     *
     * `https://minted.example/r?next=https://evil.net/` passes a host check and
     * then lands wherever the redirect points, so the shape is refused rather
     * than trusted to somebody else's redirect policy.
     *
     * @param  array<string, mixed>  $parts  The `parse_url` parts of the URL.
     */
    protected function carriesEmbeddedUrl(array $parts): bool
    {
        $rest = implode('?', [
            (string) ($parts['path'] ?? ''),
            (string) ($parts['query'] ?? ''),
        ]).'#'.(string) ($parts['fragment'] ?? '');

        // Checked at every decoding depth INCLUDING the last: one pass turns
        // `%3A%2F%2F` into `://`, a second makes a double encoding
        // (`%253A%252F%252F`) visible, and a loop that decodes without checking
        // afterwards throws away the pass it just paid for.
        //
        // Two shapes, not one. An absolute URL names its scheme; a
        // protocol-relative one (`?next=//evil.net`) does not and inherits ours,
        // which is the same open redirect written shorter.
        for ($pass = 0; $pass <= self::MAX_DECODE_PASSES; $pass++) {
            if (preg_match('#[a-z][a-z0-9+.\-]*://#i', $rest) === 1) {
                return true;
            }

            if (preg_match('#(^|[?&=])//[a-z0-9.\-]#i', $rest) === 1) {
                return true;
            }

            if ($pass === self::MAX_DECODE_PASSES) {
                break;
            }

            $rest = rawurldecode($rest);
        }

        return false;
    }
}
