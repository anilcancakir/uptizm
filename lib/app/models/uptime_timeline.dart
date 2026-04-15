import 'package:uptizm/app/models/uptime_day.dart';

/// A ranged uptime timeline containing daily uptime data points.
///
/// Read-only DTO grouping [UptimeDay] entries under a named range
/// (e.g. '24h', '7d', '30d').
///
/// ## Usage
///
/// ```dart
/// final timeline = UptimeTimeline.fromMap(response.data);
/// print('Range: ${timeline.range}, days: ${timeline.days.length}');
/// for (final day in timeline.days) {
///   print('${day.date}: ${day.status}');
/// }
/// ```
class UptimeTimeline {
  /// The range label for this timeline (e.g. '24h', '7d', '30d').
  final String range;

  /// Daily uptime data points within the range.
  final List<UptimeDay> days;

  const UptimeTimeline({required this.range, required this.days});

  /// Creates an [UptimeTimeline] from an API response map.
  factory UptimeTimeline.fromMap(Map<String, dynamic> map) {
    return UptimeTimeline(
      range: map['range'] as String? ?? '',
      days:
          (map['days'] as List<dynamic>?)
              ?.map((item) => UptimeDay.fromMap(item as Map<String, dynamic>))
              .toList() ??
          [],
    );
  }
}
