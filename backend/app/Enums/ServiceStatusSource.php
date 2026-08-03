<?php

namespace App\Enums;

/**
 * Which official status feed (if any) enriches a catalog service's public
 * page with the provider's own published status.
 *
 * `None` is both the schema default and the honest state for a service
 * uptizm probes but whose provider publishes no lawfully-usable feed: such a
 * service still publishes on the strength of uptizm's own measurement alone,
 * it simply carries no {@see StatusProvenance::ProviderFeed} facts.
 * `StatuspageV2` covers every Atlassian Statuspage-hosted provider, because
 * its `summary.json` response shape is shared across every provider that
 * uses it. `GoogleCloud` and `GoogleWorkspace` are kept as separate cases
 * despite an identical payload shape, because they are different HOSTS
 * (`status.cloud.google.com` vs the Workspace dashboard) and this plan's
 * ingestion job (a later step) resolves the fetch URL from this value.
 */
enum ServiceStatusSource: string
{
    case StatuspageV2 = 'statuspage_v2';
    case GoogleCloud = 'google_cloud';
    case GoogleWorkspace = 'google_workspace';
    case None = 'none';
}
