<?php

namespace App\Enums;

use App\Http\Controllers\Api\V1\MonitorController;
use App\Support\Monitoring\AnalyzeRunStore;

/**
 * Lifecycle of one `POST /api/v1/monitors/analyze` run, tracked in
 * {@see AnalyzeRunStore} rather than in a table.
 *
 * UNLIKE {@see StatusPagePreviewStatus}, this enum DOES carry a `Queued`
 * case, and the difference is the moment each subject starts existing.
 * `status_pages.preview_render_status` is a nullable column and null already
 * means "never rendered", so a `pending` case there would be a second
 * spelling of the same fact. A run has no such column: it is CREATED by the
 * accepting request the instant the job is dispatched (see
 * {@see MonitorController::analyze()}), so `Queued` is not decorative, it is
 * the only state a run can be in before a worker has picked it up, and the
 * client's poll has to be able to render it rather than treat it as absent.
 *
 * `Probing` does not exist as a case. The relay probe that seeds the run
 * (region, status code, response time) finishes inside the REQUEST, before
 * {@see AnalyzeRunStore::start()} is ever called, so no run is ever observed
 * mid-probe: a run goes straight from not-yet-created to `Queued`.
 *
 * Wire values are lowercase strings and are a CONTRACT with
 * `AnalyzeProgressBroadcast::broadcastWith()`'s `status` field, which spells
 * them out as raw strings rather than importing this enum, because that event
 * is authored by a different step in the same wave and must not depend on
 * this class existing first. A mismatch between the two is invisible until a
 * live run: nothing decodes the broadcast payload against this enum.
 */
enum AnalyzeRunStatus: string
{
    case Queued = 'queued';
    case Analyzing = 'analyzing';
    case Completed = 'completed';
    case Failed = 'failed';
}
