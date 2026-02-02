import '../enums/metric_type.dart';

class MetricMapping {
  final String label;
  final String path;
  final MetricType type;
  final String? unit;

  const MetricMapping({
    required this.label,
    required this.path,
    required this.type,
    this.unit,
  });

  Map<String, dynamic> toMap() {
    final map = <String, dynamic>{
      'label': label,
      'path': path,
      'type': type.value,
    };

    if (unit != null) {
      map['unit'] = unit;
    }

    return map;
  }

  factory MetricMapping.fromMap(Map<String, dynamic> map) {
    return MetricMapping(
      label: map['label'] as String,
      path: map['path'] as String,
      type: MetricType.fromValue(map['type'] as String?)!,
      unit: map['unit'] as String?,
    );
  }

  String toDisplayString() {
    final buffer = StringBuffer('$label: $path (${type.value}');
    if (unit != null) {
      buffer.write(', $unit');
    }
    buffer.write(')');
    return buffer.toString();
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is MetricMapping &&
          runtimeType == other.runtimeType &&
          label == other.label &&
          path == other.path &&
          type == other.type &&
          unit == other.unit;

  @override
  int get hashCode =>
      label.hashCode ^ path.hashCode ^ type.hashCode ^ unit.hashCode;

  @override
  String toString() => 'MetricMapping(${toDisplayString()})';
}
