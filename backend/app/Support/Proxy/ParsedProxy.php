<?php

namespace App\Support\Proxy;

/**
 * One parsed `host:port:username:password` row, verbatim from the source
 * list: no percent-encoding, no URL assembly, no validation beyond
 * {@see ProxyListParser}'s own regex and port range. Credentials are handed to
 * `CURLOPT_PROXYUSERPWD` by the probe engine precisely so characters like `@`,
 * `/` and `#` in a password never have to survive a URL parser.
 *
 * It lives in its own file, and that is not a style preference. Co-located in
 * `ProxyListParser.php` it was not autoloadable at all: PSR-4 maps one class per
 * file, so `new ParsedProxy(...)` threw `Class "App\Support\Proxy\ParsedProxy" not
 * found` unless the parser happened to be loaded first. Every later consumer that
 * builds a row as a test fixture would have hit that, and the failure names the
 * wrong cause.
 */
readonly class ParsedProxy
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
    ) {}
}
