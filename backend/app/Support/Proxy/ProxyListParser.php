<?php

namespace App\Support\Proxy;

/**
 * Parses a Webshare-format proxy list (`host:port:username:password`, one
 * entry per line) into a list of {@see ParsedProxy} value objects.
 *
 * This is the design reference's own contract, ported line for line from
 * /Users/anilcan/Code/package-booster/internal/proxysrc/parser.go (read-only,
 * not a dependency): a proxy provider's list is trusted-ish third-party
 * input, fetched over HTTP, and it is normal for a handful of lines in an
 * otherwise-good list to be malformed. Raising on the first bad line would
 * let one typo in a thousand-line list block the refresh of every other
 * exit, so every unrecognised line, out-of-range port, or credential
 * carrying a stray `:` is DROPPED SILENTLY rather than thrown.
 *
 * A WHOLLY unparseable body (a maintenance page, a truncated response, a
 * revoked token's error page) is a different event and is deliberately left
 * for the caller to detect: this class only ever returns fewer rows, it
 * never reports why, and Step 5's refresher treats an empty result as a
 * failed refresh that must not sweep the existing pool.
 */
class ProxyListParser
{
    /**
     * Matches one `host:port:username:password` line.
     *
     * Group 1 (host) admits only what an IPv4 address or a hostname can
     * contain, and that narrowness is a security boundary rather than
     * tidiness. The host is interpolated into `'http://'.$host.':'.$port`
     * to build `CURLOPT_PROXY`, so a character that can end the authority
     * REPOINTS OUR EGRESS. Measured before this was tightened: a list line
     * of `ignored@evil.example:8080:u:p` parsed cleanly and produced
     * `http://ignored@evil.example:8080`, whose host is `evil.example`, so
     * one poisoned line in a provider's list (or a compromised list URL)
     * silently routed every catalog probe through an attacker's box, with
     * the provider credentials attached. `@`, `/`, `?` and `#` are the
     * characters that do it; allow-listing the legal charset closes all of
     * them at once instead of blocking them one at a time.
     *
     * IPv6 is not expressible here at all, and cannot be: the wire format
     * is colon-delimited, so an address containing colons could never be
     * told apart from the field separators.
     *
     * A leading `:`, whitespace or `#` stays impossible, so a
     * comment-prefixed line can never match (the caller's blank/`#` skip is
     * a fast path, not the only guard). Group 2 (port) accepts only 1-5
     * digits; the 1-65535 range is then enforced in code because
     * `\d{1,5}` alone still admits 99999. Groups 3 and 4 (username,
     * password) forbid `:` so a credential containing the field separator
     * is dropped instead of being silently mis-split across fields.
     */
    protected const string LINE_PATTERN = '/^([A-Za-z0-9][A-Za-z0-9._-]*):(\d{1,5}):([^:]+):([^:]+)$/';

    /**
     * The legal TCP port range; a port outside it is dropped like any other
     * malformed line rather than raising.
     */
    protected const int MIN_PORT = 1;

    protected const int MAX_PORT = 65535;

    /**
     * Parse a raw list body into ParsedProxy rows, dropping anything that
     * does not fit the wire format.
     *
     * @param  string  $body  The full, decoded response or file body; may be
     *                        empty, may use CRLF line endings, may carry
     *                        trailing whitespace on any line.
     * @return list<ParsedProxy> Zero or more parsed rows, in source order.
     *                           Never throws; an empty or wholly malformed
     *                           body simply yields an empty list.
     */
    public static function parse(string $body): array
    {
        $proxies = [];

        // 1. Split on any line ending so a CRLF-authored list and a bare-LF
        //    one both scan the same way; PHP_EOL would only match the OS
        //    running this process, not the list's own origin.
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            // 2. Trailing spaces/tabs are cosmetic and must not defeat the
            //    exact-match regex below.
            $line = rtrim($line, " \t");

            // 3. Blank lines and `#`-prefixed comments are not malformed
            //    data, just non-data; skip without counting them as drops.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! preg_match(self::LINE_PATTERN, $line, $match)) {
                continue;
            }

            $port = (int) $match[2];

            if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
                continue;
            }

            $proxies[] = new ParsedProxy(
                host: $match[1],
                port: $port,
                username: $match[3],
                password: $match[4],
            );
        }

        return $proxies;
    }
}
