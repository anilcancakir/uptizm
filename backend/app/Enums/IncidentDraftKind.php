<?php

namespace App\Enums;

/**
 * Which piece of writing the draft gateway is being asked for.
 *
 * The two differ in audience before they differ in length, and every other
 * decision follows from that. An UPDATE is published to the status page while
 * the incident runs, so it is read by the operator's customers: short, plain,
 * and carrying nothing about the inside of the system. A POSTMORTEM is written
 * after it ends and is an internal draft until the operator publishes it, so it
 * may name what the probes actually saw.
 *
 * That is why the evidence differs too: the response-body slice is included for
 * a postmortem and withheld from an update. A customer status note quoting a
 * `checks.storage.details.disk_used_percent` is leaking the inside of a system
 * to someone who cannot act on it, and once published it cannot be unpublished.
 */
enum IncidentDraftKind: string
{
    /**
     * A short public status update, posted while the incident is live.
     */
    case Update = 'update';

    /**
     * The closing write-up, drafted after the incident resolved.
     */
    case Postmortem = 'postmortem';
}
