/// Timestamp and duration formatting shared by the incident and monitor
/// value-objects and their views.
///
/// These functions were extracted from the mock layer so the type layer
/// (`lib/app/support/`) and the ORM models can format wall-clock and elapsed
/// strings without importing fixture data.
///
/// All are pure except [formatDuration], [formatRelativeAge] and
/// [formatRelativeMeta], which read their words from the locale catalogue: a
/// caller with no [TranslationLoader] registered gets the raw key back, so a
/// test asserting any of the three needs one.
library;

import 'package:magic/magic.dart';

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
/// fixture duration convention (e.g. `'14m'`, `'1h 06m'`), rendering as
/// `'14dk'` / `'1sa 06dk'` in Turkish.
///
/// The units come from the catalogue for the same reason
/// `formatBudgetMinutes()`'s do: this string is interpolated into the Turkish
/// postmortem sentence, so a hardcoded `m` there is the same defect class as an
/// English clause used as a Turkish grammatical subject. The zero-padded minute
/// is the convention this reproduces and is deliberately NOT unified with
/// `formatBudgetMinutes()`'s unpadded one.
String formatDuration(DateTime startedAt, DateTime until) {
  final Duration elapsed = until.difference(startedAt);
  final int totalMinutes = elapsed.inMinutes.abs();
  final String m = trans('uptizm.units.minutes');
  final String h = trans('uptizm.units.hours');
  if (totalMinutes < 60) return '$totalMinutes$m';
  final int hours = totalMinutes ~/ 60;
  final int minutes = totalMinutes % 60;
  return '$hours$h ${minutes.toString().padLeft(2, '0')}$m';
}

/// How long ago [instant] was, as a compact localized string: `"8 sn önce"`,
/// `"14 dk önce"`, `"2 sa önce"`, `"5 gün önce"` (`"8s ago"`, `"14m ago"`,
/// `"2h ago"`, `"5d ago"` in English).
///
/// Seconds are the finest grain because a monitor's last-checked line is read
/// against a check cadence measured in seconds; anything coarser renders a
/// just-completed check as if nothing had happened for a minute.
///
/// This is deliberately NOT the same function as `notification_center`'s
/// private `_relativeTime`, which floors at `time_just_now` instead. That is a
/// different granularity contract for a friendlier surface, not a duplicate to
/// unify: a notification does not gain from `"8 sn önce"`, and a monitor does.
String formatRelativeAge(DateTime instant) {
  final Duration elapsed = DateTime.now().difference(instant);

  final int seconds = elapsed.inSeconds.abs();
  if (seconds < 60) {
    return trans('uptizm.common.time_seconds_ago', {'count': '$seconds'});
  }

  final int minutes = elapsed.inMinutes.abs();
  if (minutes < 60) {
    return trans('uptizm.common.time_minutes_ago', {'count': '$minutes'});
  }

  final int hours = elapsed.inHours.abs();
  if (hours < 24) {
    return trans('uptizm.common.time_hours_ago', {'count': '$hours'});
  }

  return trans('uptizm.common.time_days_ago', {
    'count': '${elapsed.inDays.abs()}',
  });
}

/// The relative-time meta line: `"14 dk önce başladı"` / `"2 sa önce çözüldü"`
/// (`"started 14m ago"` / `"resolved 2h ago"` in English).
///
/// The whole clause comes from the catalogue with the age interpolated, rather
/// than a prefix concatenated onto [formatRelativeAge]. Word order is the
/// reason: English leads with the verb and Turkish closes with it, so a
/// `'$prefix $age'` would read `"başladı 14 dk önce"`, which is the shape a
/// naive port produces and a Turkish reader trips over.
///
/// Callers wanting the bare age (an AI inbox row has no start/resolve to state)
/// should call [formatRelativeAge] directly. Stripping the verb back off this
/// string used to be a regex over the English words, which stopped matching the
/// moment the clause was translated.
String formatRelativeMeta(DateTime startedAt, DateTime? resolvedAt) {
  final bool isResolved = resolvedAt != null;
  final String age = formatRelativeAge(resolvedAt ?? startedAt);

  return trans(
    isResolved ? 'uptizm.common.time_resolved' : 'uptizm.common.time_started',
    {'age': age},
  );
}

/// Formats an integer with the locale's thousands separator: `83365` renders as
/// `83,365` in English and `83.365` in Turkish.
///
/// The separator is locale DATA, not a style choice, so it comes from the
/// catalogue like the words above rather than from a hardcoded glyph. There were
/// two byte-identical private copies of this, in `UsageMeter` and
/// `PlanBillingView`, both hardcoding a comma; a Turkish billing page reported
/// `83,365 checks`.
///
/// A caller with no [TranslationLoader] registered gets the raw key back for the
/// separator, so the fallback keeps the digits readable instead of splicing a
/// key into the middle of a number.
String formatCount(int n) {
  final String key = trans('uptizm.common.thousands_separator');
  final String separator = key.length == 1 ? key : ',';
  final String digits = n.abs().toString();
  final StringBuffer buffer = StringBuffer();

  for (int i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) {
      buffer.write(separator);
    }
    buffer.write(digits[i]);
  }

  return n < 0 ? '-$buffer' : buffer.toString();
}
