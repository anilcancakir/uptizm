import '../enums/assertion_type.dart';
import '../enums/assertion_operator.dart';

/// Value object representing an assertion rule for monitor checks.
///
/// Example usage:
/// ```dart
/// final rule = AssertionRule(
///   type: AssertionType.bodyJsonPath,
///   operator: AssertionOperator.equals,
///   value: 'healthy',
///   path: 'data.status',
/// );
///
/// print(rule.toDisplayString()); // "data.status == healthy"
/// ```
class AssertionRule {
  final AssertionType type;
  final AssertionOperator operator;
  final String value;
  final String? path; // For json_path type

  const AssertionRule({
    required this.type,
    required this.operator,
    required this.value,
    this.path,
  });

  /// Convert to Map for API/storage
  Map<String, dynamic> toMap() {
    final map = <String, dynamic>{
      'type': type.value,
      'operator': operator.value,
      'value': value,
    };

    if (path != null) {
      map['path'] = path;
    }

    return map;
  }

  /// Create from Map (from API/storage)
  factory AssertionRule.fromMap(Map<String, dynamic> map) {
    return AssertionRule(
      type: AssertionType.fromValue(map['type'] as String?)!,
      operator: AssertionOperator.fromValue(map['operator'] as String?)!,
      value: map['value'] as String,
      path: map['path'] as String?,
    );
  }

  /// Display string for UI (e.g., "data.status == healthy")
  String toDisplayString() {
    String left;
    String op;

    // Determine left side
    if (type == AssertionType.bodyJsonPath && path != null) {
      left = path!;
    } else if (type == AssertionType.bodyContains) {
      left = 'body';
    } else if (type == AssertionType.bodyRegex) {
      left = 'body';
    } else if (type == AssertionType.headerContains) {
      left = 'header';
    } else {
      left = type.value;
    }

    // Determine operator display
    op = operator.label;

    return '$left $op $value';
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is AssertionRule &&
          runtimeType == other.runtimeType &&
          type == other.type &&
          operator == other.operator &&
          value == other.value &&
          path == other.path;

  @override
  int get hashCode =>
      type.hashCode ^ operator.hashCode ^ value.hashCode ^ path.hashCode;

  @override
  String toString() => 'AssertionRule(${toDisplayString()})';
}
