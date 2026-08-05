<?php

namespace App\Enums;

use App\Services\Monitoring\ResponseDigest;

/**
 * The structural shape {@see ResponseDigest} sniffed out of a captured response
 * body.
 *
 * Sniffed from the bytes, never read off a `content-type` header: the monitored
 * target controls that header, and a JSON API that mislabels itself
 * `text/html` is common enough that trusting the label would cost the digest
 * its whole JSON skeleton.
 *
 * `Unknown` is not a failure. A plain-text `OK` is a real health endpoint and an
 * empty body is a real answer to a `HEAD` check, so the shape says "no
 * structure to walk" rather than "could not be read".
 */
enum BodyShape: string
{
    case Json = 'json';
    case Html = 'html';
    case Xml = 'xml';
    case Unknown = 'unknown';
}
