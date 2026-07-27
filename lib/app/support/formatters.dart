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

/// Formats [value] as a local `MM-DD HH:mm` stamp (e.g. `'07-27 14:00'`).
/// Returns `'—'` when [value] is `null`.
///
/// Numeric on purpose: a multi-day window (an on-call override routinely spans
/// two calendar days) needs the date, and month NAMES would leak untranslated
/// English into every non-English locale. Render it with the mono +
/// `tabular-nums` utilities, like every other timestamp column.
String formatMonthDayTime(DateTime? value) {
  if (value == null) return '—';
  final DateTime local = value.toLocal();
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(local.month)}-${two(local.day)} '
      '${two(local.hour)}:${two(local.minute)}';
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

/// Formats how long ago [checkedAt] was, at the granularity a monitor's check
/// cadence needs: `"8s ago"`, `"3m ago"`, `"2h ago"`, or `"5d ago"`.
String formatCheckedAgo(DateTime checkedAt) {
  final Duration elapsed = DateTime.now().difference(checkedAt);
  final int seconds = elapsed.inSeconds.abs();
  if (seconds < 60) return '${seconds}s ago';
  final int minutes = elapsed.inMinutes.abs();
  if (minutes < 60) return '${minutes}m ago';
  final int hours = elapsed.inHours.abs();
  if (hours < 24) return '${hours}h ago';
  return '${elapsed.inDays.abs()}d ago';
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
