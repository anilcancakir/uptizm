<?php

namespace App\Enums;

use App\Models\Service;

/**
 * Which measurement produced a status fact shown on a public service page:
 * uptizm's OWN probe, or the provider's OWN published status feed.
 *
 * No existing enum covers this distinction; each of the three that come
 * closest answers a different question. {@see SignalSource} answers "who
 * noticed first" for an INCIDENT (a user threshold, an AI detector, or a
 * human filing it manually). {@see EvidenceSource} answers "which owned zone
 * may an AI cite" for an analysis citation (the incident timeline, a
 * recorded check, or the affected monitor). {@see MetricSource} answers "how
 * was a metric value extracted" from a check response (JSONPath, regex,
 * XPath, header, or the HTTP status itself). None of the three describes
 * WHOSE measurement a status fact came from, which is exactly the axis the
 * public service catalog's Must Have requires every displayed status fact to
 * carry, so a page never blends a probe result and a provider claim into one
 * unlabeled number (see {@see Service::canPublish()} for the
 * companion enforcement that a page always carries at least one own-probe
 * fact).
 */
enum StatusProvenance: string
{
    case OwnProbe = 'own_probe';
    case ProviderFeed = 'provider_feed';
}
