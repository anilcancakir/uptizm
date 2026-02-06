import 'package:magic/magic.dart';

/// Validates that a numeric string value is at least a minimum value.
///
/// ## Usage
///
/// ```dart
/// validate(data, {
///   'age': [Required(), MinNumeric(18)],
///   'timeout': [Required(), MinNumeric(1)],
/// });
/// ```
class MinNumeric extends Rule {
  /// The minimum numeric value.
  final num min;

  /// Create a MinNumeric rule.
  ///
  /// [min] The minimum numeric value.
  MinNumeric(this.min);

  @override
  bool passes(String attribute, dynamic value, Map<String, dynamic> data) {
    if (value == null) return true; // Let Required handle null

    // Convert to string if not already
    final stringValue = value.toString();
    if (stringValue.isEmpty) return true; // Let Required handle empty

    // Try to parse as number
    final numValue = num.tryParse(stringValue);
    if (numValue == null) return false; // Not a valid number

    // Check minimum
    return numValue >= min;
  }

  @override
  String message() => 'validation.min_numeric';

  @override
  Map<String, dynamic> params() => {'min': min};
}
