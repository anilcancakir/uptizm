/// A single response time data point from the API.
///
/// Read-only DTO representing a monitor's response time measurement
/// at a specific timestamp.
///
/// ## Usage
///
/// ```dart
/// final point = ResponseTimePoint.fromMap({
///   'timestamp': '2026-04-15T12:00:00Z',
///   'response_time_ms': 245,
///   'status': 'up',
/// });
/// print('${point.timestamp}: ${point.responseTimeMs}ms (${point.status})');
/// ```
class ResponseTimePoint {
  /// The timestamp of this measurement.
  final DateTime timestamp;

  /// Response time in milliseconds.
  final int responseTimeMs;

  /// Status at this point: 'up', 'down', or 'degraded'.
  final String status;

  const ResponseTimePoint({
    required this.timestamp,
    required this.responseTimeMs,
    required this.status,
  });

  /// Creates a [ResponseTimePoint] from an API response map.
  factory ResponseTimePoint.fromMap(Map<String, dynamic> map) {
    return ResponseTimePoint(
      timestamp: DateTime.parse(map['timestamp'] as String),
      responseTimeMs: (map['response_time_ms'] as num?)?.toInt() ?? 0,
      status: map['status'] as String? ?? 'up',
    );
  }
}
