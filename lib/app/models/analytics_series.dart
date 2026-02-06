import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_data_point.dart';

class AnalyticsSeries {
  final String metricKey;
  final String metricLabel;
  final MetricType metricType;
  final String? unit;
  final List<AnalyticsDataPoint> dataPoints;

  const AnalyticsSeries({
    required this.metricKey,
    required this.metricLabel,
    required this.metricType,
    required this.dataPoints,
    this.unit,
  });

  factory AnalyticsSeries.fromMap(Map<String, dynamic> map) {
    return AnalyticsSeries(
      metricKey: map['metric_key'] as String,
      metricLabel: map['metric_label'] as String,
      metricType:
          MetricType.fromValue(map['metric_type'] as String) ??
          MetricType.numeric,
      unit: map['unit'] as String?,
      dataPoints:
          (map['data_points'] as List?)
              ?.map(
                (e) => AnalyticsDataPoint.fromMap(e as Map<String, dynamic>),
              )
              .toList() ??
          [],
    );
  }

  bool get isEmpty => dataPoints.isEmpty;

  DateTimeRange? get dateRange {
    if (isEmpty) return null;
    return DateTimeRange(
      start: dataPoints.first.timestamp,
      end: dataPoints.last.timestamp,
    );
  }

  List<FlSpot> toChartSpots() {
    return dataPoints
        .where((p) => p.value != null)
        .map(
          (p) =>
              FlSpot(p.timestamp.millisecondsSinceEpoch.toDouble(), p.value!),
        )
        .toList();
  }
}
