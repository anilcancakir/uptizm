/// A single day's uptime data point from the API.
///
/// Read-only DTO representing the uptime status and percentage
/// for a specific calendar date.
///
/// ## Usage
///
/// ```dart
/// final day = UptimeDay.fromMap({'date': '2026-04-15', 'status': 'up', 'uptime_percent': 99.95});
/// print('${day.date}: ${day.status} (${day.uptimePercent}%)');
/// ```
class UptimeDay {
  /// The calendar date for this data point.
  final DateTime date;

  /// Status for the day: 'up', 'down', 'degraded', or null if no data.
  final String? status;

  /// Uptime percentage for the day (0.0 to 100.0).
  final double? uptimePercent;

  const UptimeDay({required this.date, this.status, this.uptimePercent});

  /// Creates an [UptimeDay] from an API response map.
  factory UptimeDay.fromMap(Map<String, dynamic> map) {
    return UptimeDay(
      date: DateTime.parse(map['date'] as String),
      status: map['status'] as String?,
      uptimePercent: (map['uptime_percent'] as num?)?.toDouble(),
    );
  }
}
