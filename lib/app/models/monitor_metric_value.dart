import '../enums/metric_status_value.dart';

class MonitorMetricValue {
  final String id;
  final String monitorId;
  final String checkId;
  final String metricKey;
  final String metricLabel;
  final double? numericValue;
  final String? stringValue;
  final MetricStatusValue? statusValue;
  final String? unit;
  final DateTime? recordedAt;

  const MonitorMetricValue({
    required this.id,
    required this.monitorId,
    required this.checkId,
    required this.metricKey,
    required this.metricLabel,
    this.numericValue,
    this.stringValue,
    this.statusValue,
    this.unit,
    this.recordedAt,
  });

  factory MonitorMetricValue.fromMap(Map<String, dynamic> map) {
    return MonitorMetricValue(
      id: map['id'].toString(),
      monitorId: map['monitor_id'].toString(),
      checkId: map['check_id'].toString(),
      metricKey: map['metric_key'] as String,
      metricLabel: map['metric_label'] as String,
      numericValue: (map['numeric_value'] as num?)?.toDouble(),
      stringValue: map['string_value'] as String?,
      statusValue: MetricStatusValue.fromValue(map['status_value'] as String?),
      unit: map['unit'] as String?,
      recordedAt: map['recorded_at'] != null
          ? DateTime.parse(map['recorded_at'] as String)
          : null,
    );
  }

  bool get isUp => statusValue == MetricStatusValue.up;

  bool get isDown => statusValue == MetricStatusValue.down;

  bool get isStatusMetric => statusValue != null;
}
