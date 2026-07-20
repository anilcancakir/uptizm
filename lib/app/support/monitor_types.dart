import 'package:flutter/foundation.dart';

import '../enums/status_key.dart' show StatusKey, statusKeyFromWire;
import 'formatters.dart' show formatTimeOfDay;

/// A single segment of the 90-day uptime history bar.
///
/// Each segment carries the health [status] at that point in time and a
/// human-readable [label] (e.g. `"7d ago"`) for tooltips.
@immutable
class UptimeSegment {
  /// Health state for this day, or `null` when no check ran that day (a
  /// no-data gap the bar renders as a neutral segment rather than green).
  final StatusKey? status;

  /// Tooltip label, e.g. `"7d ago"`.
  final String label;

  const UptimeSegment({required this.status, required this.label});
}

/// A single row in the recent-checks table.
///
/// Represents the outcome of one probe from a specific region.
@immutable
class CheckRow {
  /// Time of the check, e.g. `"14:32:05"`.
  final String time;

  /// Region identifier, e.g. `"us-east"`.
  final String region;

  /// Result status of this individual check.
  final StatusKey status;

  /// Response time in milliseconds. `null` when the probe timed out or failed
  /// before receiving a response.
  final int? responseMs;

  /// HTTP status code returned by the probe target.
  final int? statusCode;

  const CheckRow({
    required this.time,
    required this.region,
    required this.status,
    this.responseMs,
    this.statusCode,
  });

  /// Builds a [CheckRow] from a `MonitorCheckResource` payload (backend
  /// `api/v1` snake_case keys).
  ///
  /// `checked_at` is an ISO-8601 timestamp; it is reduced to the wall-clock
  /// `HH:mm:ss` string the table renders. An unparsable or missing timestamp
  /// falls back to `'—'` rather than throwing.
  factory CheckRow.fromMap(Map<String, dynamic> map) {
    return CheckRow(
      time: formatTimeOfDay(map['checked_at'] as String?),
      region: (map['region'] as String?) ?? '',
      status: statusKeyFromWire(map['status'] as String?),
      responseMs: (map['response_ms'] as num?)?.toInt(),
      statusCode: (map['status_code'] as num?)?.toInt(),
    );
  }
}

/// A selectable probe region shown in the monitor form.
@immutable
class ProbeRegion {
  /// Machine identifier used in API payloads.
  final String value;

  /// Human-readable display name.
  final String label;

  /// Flag emoji for visual disambiguation in pickers.
  final String flag;

  const ProbeRegion({
    required this.value,
    required this.label,
    required this.flag,
  });
}
