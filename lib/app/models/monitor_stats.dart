/// Aggregated monitor statistics from the API.
///
/// Read-only DTO representing summary counts and average response time
/// across all monitors for the current team.
///
/// ## Usage
///
/// ```dart
/// final stats = MonitorStats.fromMap(response.data);
/// print('${stats.up} of ${stats.total} monitors are up');
/// print('Avg response: ${stats.avgResponseTimeMs}ms');
/// ```
class MonitorStats {
  /// Total number of monitors.
  final int total;

  /// Number of monitors currently up.
  final int up;

  /// Number of monitors currently down.
  final int down;

  /// Number of monitors in degraded state.
  final int degraded;

  /// Average response time across all monitors in milliseconds.
  final double? avgResponseTimeMs;

  const MonitorStats({
    required this.total,
    required this.up,
    required this.down,
    required this.degraded,
    this.avgResponseTimeMs,
  });

  /// Creates a [MonitorStats] from an API response map.
  factory MonitorStats.fromMap(Map<String, dynamic> map) {
    return MonitorStats(
      total: (map['total'] as num?)?.toInt() ?? 0,
      up: (map['up'] as num?)?.toInt() ?? 0,
      down: (map['down'] as num?)?.toInt() ?? 0,
      degraded: (map['degraded'] as num?)?.toInt() ?? 0,
      avgResponseTimeMs: (map['avg_response_time_ms'] as num?)?.toDouble(),
    );
  }
}
