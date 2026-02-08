import 'package:magic/magic.dart';

enum AlertRuleType {
  status('status', 'Status'),
  threshold('threshold', 'Threshold'),
  anomaly('anomaly', 'Anomaly');

  const AlertRuleType(this.value, this.label);

  final String value;
  final String label;

  static AlertRuleType? fromValue(String? value) {
    if (value == null) return null;
    try {
      return AlertRuleType.values.firstWhere((e) => e.value == value);
    } catch (e) {
      return null;
    }
  }

  static List<SelectOption<AlertRuleType>> get selectOptions {
    return AlertRuleType.values
        .map((type) => SelectOption(value: type, label: type.label))
        .toList();
  }
}
