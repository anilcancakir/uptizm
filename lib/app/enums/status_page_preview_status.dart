/// Lifecycle state of a status page's headless PNG preview render.
///
/// Backed by the backend's nullable `preview_render_status` column, written
/// by `RenderStatusPagePreview` (`backend/app/Jobs/RenderStatusPagePreview.php`).
/// There is deliberately no `pending` case: `null` on the wire IS "never
/// rendered", and a `pending` case alongside it would give two
/// representations of the same absence and force every reader to handle
/// both.
enum StatusPagePreviewStatus {
  /// A render is queued or in flight for this page.
  rendering,

  /// The most recent render finished and produced a PNG.
  completed,

  /// The most recent render attempt failed.
  failed,
}

/// Decodes the backend `preview_render_status` wire value into a
/// [StatusPagePreviewStatus], or `null` when [raw] is absent, `null`, or an
/// unrecognized value.
///
/// Unlike most enum decoders in this codebase, this one has no non-null
/// fallback: `null` is itself the meaningful "never rendered" state (see the
/// enum's docblock), so an unknown wire value degrades to that same state
/// rather than being coerced into `rendering`/`completed`/`failed`.
StatusPagePreviewStatus? statusPagePreviewStatusFromWire(String? raw) {
  if (raw == null) return null;
  for (final StatusPagePreviewStatus status in StatusPagePreviewStatus.values) {
    if (status.name == raw) return status;
  }
  return null;
}
