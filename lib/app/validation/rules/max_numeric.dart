import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Validates that a numeric string value does not exceed a maximum value.
///
/// ## Usage
///
/// ```dart
/// validate(data, {
///   'age': [Required(), MaxNumeric(100)],
///   'timeout': [Required(), MaxNumeric(120)],
/// });
/// ```
class MaxNumeric extends Rule {
  /// The maximum numeric value.
  final num max;

  /// Create a MaxNumeric rule.
  ///
  /// [max] The maximum numeric value.
  MaxNumeric(this.max);

  @override
  bool passes(String attribute, dynamic value, Map<String, dynamic> data) {
    if (value == null) return true; // Let Required handle null

    // Convert to string if not already
    final stringValue = value.toString();
    if (stringValue.isEmpty) return true; // Let Required handle empty

    // Try to parse as number
    final numValue = num.tryParse(stringValue);
    if (numValue == null) return false; // Not a valid number

    // Check maximum
    return numValue <= max;
  }

  @override
  String message() => 'validation.max_numeric';

  @override
  Map<String, dynamic> params() => {'max': max};
}
