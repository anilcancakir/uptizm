/// Timestamp and duration formatting shared by the incident and monitor
/// value-objects and their views.
///
/// These pure functions were extracted from the mock layer so the type layer
/// (`lib/app/support/`) and the ORM models can format wall-clock and elapsed
/// strings without importing fixture data.
library;

/// Formats an ISO-8601 timestamp string as a local `HH:mm` wall-clock
/// string. Returns `'—'` when [raw] is `null` or fails to parse.
String formatHourMinute(String? raw) {
  if (raw == null) return '—';
  final DateTime? parsed = DateTime.tryParse(raw);
  if (parsed == null) return '—';
  final DateTime local = parsed.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(local.hour)}:${two(local.minute)}';
}

/// Formats an ISO-8601 timestamp string as a local `HH:mm:ss` wall-clock
/// string. Returns `'—'` when [raw] is `null` or fails to parse.
///
/// Public (unlike the mock-layer `_formatTimeOfDay` it replaces) so the
/// relocated [CheckRow] value-object can reach it across support files.
String formatTimeOfDay(String? raw) {
  if (raw == null) return '—';
  final DateTime? parsed = DateTime.tryParse(raw);
  if (parsed == null) return '—';
  final DateTime local = parsed.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(local.hour)}:${two(local.minute)}:${two(local.second)}';
}

/// Formats the elapsed time between [startedAt] and [until] (`resolvedAt` or
/// now) as `"Xm"` when under an hour, or `"Xh YYm"` otherwise. Matches the
/// fixture duration convention (e.g. `'14m'`, `'1h 06m'`).
String formatDuration(DateTime startedAt, DateTime until) {
  final Duration elapsed = until.difference(startedAt);
  final int totalMinutes = elapsed.inMinutes.abs();
  if (totalMinutes < 60) return '${totalMinutes}m';
  final int hours = totalMinutes ~/ 60;
  final int minutes = totalMinutes % 60;
  return '${hours}h ${minutes.toString().padLeft(2, '0')}m';
}

/// Formats the relative-time meta line (e.g. `"started 14m ago"` or
/// `"resolved 2h ago"`) from [startedAt]/[resolvedAt].
String formatRelativeMeta(DateTime startedAt, DateTime? resolvedAt) {
  final bool isResolved = resolvedAt != null;
  final DateTime reference = resolvedAt ?? startedAt;
  final Duration elapsed = DateTime.now().difference(reference);
  final int minutes = elapsed.inMinutes.abs();
  final String magnitude = minutes < 60 ? '${minutes}m' : '${elapsed.inHours}h';
  return '${isResolved ? 'resolved' : 'started'} $magnitude ago';
}
