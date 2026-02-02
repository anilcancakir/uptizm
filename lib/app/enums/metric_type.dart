import 'package:fluttersdk_wind/fluttersdk_wind.dart';

enum MetricType {
  numeric('numeric', 'Numeric'),
  string('string', 'String');

  const MetricType(this.value, this.label);

  final String value;
  final String label;

  static MetricType? fromValue(String? value) {
    if (value == null) return null;
    try {
      return MetricType.values.firstWhere(
        (type) => type.value == value,
      );
    } catch (e) {
      return null;
    }
  }

  static List<SelectOption<MetricType>> get selectOptions {
    return MetricType.values
        .map((type) => SelectOption(value: type, label: type.label))
        .toList();
  }
}
