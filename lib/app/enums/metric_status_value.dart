import 'package:magic/magic.dart';

enum MetricStatusValue {
  up('up', 'Up'),
  down('down', 'Down'),
  unknown('unknown', 'Unknown');

  const MetricStatusValue(this.value, this.label);

  final String value;
  final String label;

  static MetricStatusValue? fromValue(String? value) {
    if (value == null) return null;
    return MetricStatusValue.values.firstWhere(
      (status) => status.value == value,
      orElse: () => MetricStatusValue.unknown,
    );
  }

  static List<SelectOption<MetricStatusValue>> get selectOptions {
    return MetricStatusValue.values
        .map((status) => SelectOption(value: status, label: status.label))
        .toList();
  }
}
