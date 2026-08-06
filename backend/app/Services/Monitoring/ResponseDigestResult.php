<?php

namespace App\Services\Monitoring;

use App\Enums\BodyShape;

/**
 * Outcome of one {@see ResponseDigest::digest()} call.
 *
 * `digest` is the rendered skeleton, at or under the configured character
 * budget. `shape` is what the body was sniffed to be, which the caller maps onto
 * a service class rather than re-sniffing. `truncated` says the digest describes
 * LESS than the whole body: either the character budget bound, or one of the
 * structural caps dropped a subtree.
 *
 * The distinction that flag draws is deliberate. A collapsed repetition (an
 * array rendered as its first element plus a count, a run of same-named XML
 * siblings rendered once with a count) is a complete description of a
 * repetition, so it does NOT set the flag. A dropped subtree is missing
 * evidence, so it does.
 */
readonly class ResponseDigestResult
{
    public function __construct(
        public string $digest,
        public BodyShape $shape,
        public bool $truncated,
    ) {}
}
