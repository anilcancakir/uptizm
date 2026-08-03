<?php

namespace App\Services\Services;

use App\Enums\ServiceStatusSource;

/**
 * The single seam between {@see FeedFetcher} and one provider's own status-feed
 * format.
 *
 * Two implementations serve the three real {@see ServiceStatusSource} feeds:
 * {@see StatuspageV2Adapter} for every Atlassian Statuspage-hosted provider,
 * and {@see GoogleStatusAdapter} for both Google Cloud and Google Workspace
 * (identical `incidents.json` shape, different host, so a second class would be
 * copy-paste with variation). Resolution is a `match` on the enum inside
 * {@see FeedFetcher::adapterFor()}; there is deliberately no registry class,
 * because two implementations behind a four-case enum do not earn one.
 *
 * The contract every implementation owes:
 *
 *  1. It NEVER throws. A feed is untrusted remote input that arrives malformed,
 *     truncated, or with a key the provider renamed without telling anyone, and
 *     an exception here would either poison the queue or lose the honest
 *     "we could not read their feed" state the public page needs.
 *     {@see FeedReading::empty()} is the answer to a payload it cannot read.
 *  2. It NEVER paints an unparsed value healthy. An unrecognised status is a
 *     null (unknown) status, not `operational`.
 *  3. It NEVER binds a parse to a provider DISPLAY NAME. Google's own fallback
 *     documentation is explicit that id fields are Stable while display names
 *     are Unstable and "might change without warning"
 *     (https://docs.cloud.google.com/service-health/docs/service-health-fallback),
 *     so anything this catalog matches on must be an id.
 */
interface ServiceStatusAdapter
{
    /**
     * Read one decoded feed payload into the normalized reading.
     *
     * @param  array<mixed>  $payload  The json_decoded feed body. Statuspage sends an object, Google sends a top-level array, and neither is validated before it arrives here.
     * @param  string  $sourceUrl  The URL the payload was fetched from. Needed because Google publishes incident links as paths RELATIVE to the feed host (`incidents/<id>`), and one adapter serves two different Google hosts, so the host cannot be a constant on the adapter.
     */
    public function read(array $payload, string $sourceUrl): FeedReading;
}
