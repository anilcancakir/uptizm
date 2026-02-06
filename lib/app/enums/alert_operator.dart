import 'package:fluttersdk_wind/fluttersdk_wind.dart';

enum AlertOperator {
  greaterThan('>', 'Greater than (>)'),
  greaterThanOrEqual('>=', 'Greater than or equal (>=)'),
  lessThan('<', 'Less than (<)'),
  lessThanOrEqual('<=', 'Less than or equal (<=)'),
  equal('==', 'Equal (==)'),
  notEqual('!=', 'Not equal (!=)'),
  between('between', 'Between'),
  outside('outside', 'Outside range');

  const AlertOperator(this.value, this.label);

  final String value;
  final String label;

  static AlertOperator? fromValue(String? value) {
    if (value == null) return null;
    try {
      return AlertOperator.values.firstWhere((e) => e.value == value);
    } catch (e) {
      return null;
    }
  }

  static List<SelectOption<AlertOperator>> get selectOptions {
    return AlertOperator.values
        .map((op) => SelectOption(value: op, label: op.label))
        .toList();
  }

  bool get requiresRange => this == between || this == outside;
}
