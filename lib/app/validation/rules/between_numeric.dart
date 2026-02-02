import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Validates that a numeric string value is between a minimum and maximum value.
///
/// ## Usage
///
/// ```dart
/// validate(data, {
///   'age': [Required(), BetweenNumeric(18, 100)],
///   'port': [Required(), BetweenNumeric(1, 65535)],
/// });
/// ```
class BetweenNumeric extends Rule {
  /// The minimum numeric value.
  final num min;

  /// The maximum numeric value.
  final num max;

  /// Create a BetweenNumeric rule.
  ///
  /// [min] The minimum numeric value.
  /// [max] The maximum numeric value.
  BetweenNumeric(this.min, this.max);

  @override
  bool passes(String attribute, dynamic value, Map<String, dynamic> data) {
    if (value == null) return true; // Let Required handle null

    // Convert to string if not already
    final stringValue = value.toString();
    if (stringValue.isEmpty) return true; // Let Required handle empty

    // Try to parse as number
    final numValue = num.tryParse(stringValue);
    if (numValue == null) return false; // Not a valid number

    // Check range
    return numValue >= min && numValue <= max;
  }

  @override
  String message() => 'validation.between_numeric';

  @override
  Map<String, dynamic> params() => {'min': min, 'max': max};
}
