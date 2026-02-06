import '../enums/metric_type.dart';

class MetricMapping {
  final String label;
  final String path;
  final MetricType type;
  final String? unit;
  final String? upWhen;

  const MetricMapping({
    required this.label,
    required this.path,
    required this.type,
    this.unit,
    this.upWhen,
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

    if (upWhen != null) {
      map['up_when'] = upWhen;
    }

    return map;
  }

  factory MetricMapping.fromMap(Map<String, dynamic> map) {
    final labelStr = map['label'] as String?;
    final pathStr = map['path'] as String?;
    final typeValue = map['type'] as String?;

    if (labelStr == null || pathStr == null || typeValue == null) {
      throw ArgumentError(
        'MetricMapping.fromMap: Missing required fields (label, path, or type)',
      );
    }

    final type = MetricType.fromValue(typeValue);
    if (type == null) {
      throw ArgumentError(
        'MetricMapping.fromMap: Invalid type value: $typeValue',
      );
    }

    return MetricMapping(
      label: labelStr,
      path: pathStr,
      type: type,
      unit: map['unit'] as String?,
      upWhen: map['up_when'] as String?,
    );
  }

  /// Safely parse a map to MetricMapping, returns null if invalid
  static MetricMapping? tryFromMap(Map<String, dynamic>? map) {
    if (map == null) return null;

    final labelStr = map['label'] as String?;
    final pathStr = map['path'] as String?;
    final typeValue = map['type'] as String?;

    if (labelStr == null || pathStr == null || typeValue == null) {
      return null;
    }

    final type = MetricType.fromValue(typeValue);
    if (type == null) {
      return null;
    }

    return MetricMapping(
      label: labelStr,
      path: pathStr,
      type: type,
      unit: map['unit'] as String?,
      upWhen: map['up_when'] as String?,
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
          unit == other.unit &&
          upWhen == other.upWhen;

  @override
  int get hashCode =>
      label.hashCode ^
      path.hashCode ^
      type.hashCode ^
      unit.hashCode ^
      upWhen.hashCode;

  @override
  String toString() => 'MetricMapping(${toDisplayString()})';
}
