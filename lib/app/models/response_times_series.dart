import 'package:uptizm/app/models/response_time_point.dart';

/// A ranged response time series containing timestamped data points.
///
/// Read-only DTO grouping [ResponseTimePoint] entries under a named range
/// (e.g. '24h', '7d', '30d').
///
/// ## Usage
///
/// ```dart
/// final series = ResponseTimesSeries.fromMap(response.data);
/// print('Range: ${series.range}, points: ${series.points.length}');
/// for (final point in series.points) {
///   print('${point.timestamp}: ${point.responseTimeMs}ms');
/// }
/// ```
class ResponseTimesSeries {
  /// The range label for this series (e.g. '24h', '7d', '30d').
  final String range;

  /// Response time data points within the range.
  final List<ResponseTimePoint> points;

  const ResponseTimesSeries({required this.range, required this.points});

  /// Creates a [ResponseTimesSeries] from an API response map.
  factory ResponseTimesSeries.fromMap(Map<String, dynamic> map) {
    return ResponseTimesSeries(
      range: map['range'] as String? ?? '',
      points:
          (map['points'] as List<dynamic>?)
              ?.map(
                (item) =>
                    ResponseTimePoint.fromMap(item as Map<String, dynamic>),
              )
              .toList() ??
          [],
    );
  }
}
