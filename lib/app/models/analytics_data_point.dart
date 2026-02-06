class AnalyticsDataPoint {
  final DateTime timestamp;
  final double? value;
  final double? min;
  final double? max;
  final int? count;
  final int? upCount;
  final int? downCount;
  final int? total;

  const AnalyticsDataPoint({
    required this.timestamp,
    this.value,
    this.min,
    this.max,
    this.count,
    this.upCount,
    this.downCount,
    this.total,
  });

  factory AnalyticsDataPoint.fromMap(Map<String, dynamic> map) {
    return AnalyticsDataPoint(
      timestamp: DateTime.parse(map['timestamp'] as String),
      value: map['value'] is num
          ? (map['value'] as num).toDouble()
          : double.tryParse(map['value']?.toString() ?? ''),
      min: map['min'] is num
          ? (map['min'] as num).toDouble()
          : double.tryParse(map['min']?.toString() ?? ''),
      max: map['max'] is num
          ? (map['max'] as num).toDouble()
          : double.tryParse(map['max']?.toString() ?? ''),
      count: map['count'] is num
          ? (map['count'] as num).toInt()
          : int.tryParse(map['count']?.toString() ?? ''),
      upCount: map['up_count'] is num
          ? (map['up_count'] as num).toInt()
          : int.tryParse(map['up_count']?.toString() ?? ''),
      downCount: map['down_count'] is num
          ? (map['down_count'] as num).toInt()
          : int.tryParse(map['down_count']?.toString() ?? ''),
      total: map['total'] is num
          ? (map['total'] as num).toInt()
          : int.tryParse(map['total']?.toString() ?? ''),
    );
  }

  double? get uptimePercent =>
      total != null && total! > 0 ? (upCount ?? 0) / total! * 100 : null;
}
