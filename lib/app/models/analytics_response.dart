import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_series.dart';

class AnalyticsSummary {
  final int totalChecks;
  final double uptimePercent;
  final double avgResponseTime;

  const AnalyticsSummary({
    required this.totalChecks,
    required this.uptimePercent,
    required this.avgResponseTime,
  });

  factory AnalyticsSummary.fromMap(Map<String, dynamic> map) {
    return AnalyticsSummary(
      totalChecks: _toInt(map['total_checks']) ?? 0,
      uptimePercent: _toDouble(map['uptime_percent']) ?? 0.0,
      avgResponseTime: _toDouble(map['avg_response_time']) ?? 0.0,
    );
  }

  static int? _toInt(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value);
    return null;
  }

  static double? _toDouble(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    if (value is String) return double.tryParse(value);
    return null;
  }

  factory AnalyticsSummary.empty() {
    return const AnalyticsSummary(
      totalChecks: 0,
      uptimePercent: 0,
      avgResponseTime: 0,
    );
  }
}

class AnalyticsResponse {
  final String monitorId;
  final DateTime dateFrom;
  final DateTime dateTo;
  final String granularity;
  final List<AnalyticsSeries> series;
  final AnalyticsSummary summary;

  const AnalyticsResponse({
    required this.monitorId,
    required this.dateFrom,
    required this.dateTo,
    required this.granularity,
    required this.series,
    required this.summary,
  });

  factory AnalyticsResponse.fromMap(Map<String, dynamic> map) {
    final data = map['data'] ?? map;
    return AnalyticsResponse(
      monitorId: data['monitor_id']?.toString() ?? '',
      dateFrom: DateTime.tryParse(data['date_from'] ?? '') ?? DateTime.now(),
      dateTo: DateTime.tryParse(data['date_to'] ?? '') ?? DateTime.now(),
      granularity: data['granularity'] as String? ?? 'hourly',
      series:
          (data['series'] as List?)
              ?.map((e) => AnalyticsSeries.fromMap(e as Map<String, dynamic>))
              .toList() ??
          [],
      summary: data['summary'] != null
          ? AnalyticsSummary.fromMap(data['summary'] as Map<String, dynamic>)
          : AnalyticsSummary.empty(),
    );
  }

  AnalyticsSeries? getSeriesByKey(String key) {
    try {
      return series.firstWhere((s) => s.metricKey == key);
    } catch (_) {
      return null;
    }
  }

  List<AnalyticsSeries> get numericSeries =>
      series.where((s) => s.metricType == MetricType.numeric).toList();

  List<AnalyticsSeries> get statusSeries =>
      series.where((s) => s.metricType == MetricType.status).toList();
}
