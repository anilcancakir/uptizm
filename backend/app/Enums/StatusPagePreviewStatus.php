<?php

namespace App\Enums;

use App\Models\StatusPage;

/**
 * State of a {@see StatusPage}'s headless preview render.
 *
 * There is deliberately no `pending` case. `status_pages.preview_render_status`
 * is nullable and NULL alone means "never rendered", so a `pending` case would
 * give two representations of the same fact and force every reader (including
 * the Flutter editor) to handle both.
 *
 * `rendering` is never a terminal state: the job writes `completed` or `failed`
 * on every path, so the editor cannot strand on a skeleton.
 *
 * Wire values are snake_case strings to match the Flutter client's JSON
 * contract (see lib/app/enums/status_page_preview_status.dart).
 */
enum StatusPagePreviewStatus: string
{
    case Rendering = 'rendering';
    case Completed = 'completed';
    case Failed = 'failed';
}
