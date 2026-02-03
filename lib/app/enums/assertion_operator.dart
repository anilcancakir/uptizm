import 'package:fluttersdk_magic/fluttersdk_magic.dart';

enum AssertionOperator {
  equals('equals', '=='),
  notEquals('not_equals', '!='),
  greaterThan('greater_than', '>'),
  lessThan('less_than', '<'),
  contains('contains', 'contains'),
  notContains('not_contains', 'not contains'),
  matchesRegex('matches_regex', 'matches');

  const AssertionOperator(this.value, this.label);

  final String value;
  final String label;

  static AssertionOperator? fromValue(String? value) {
    if (value == null) return null;
    try {
      return AssertionOperator.values.firstWhere((op) => op.value == value);
    } catch (e) {
      return null;
    }
  }

  static List<SelectOption<AssertionOperator>> get selectOptions {
    return AssertionOperator.values
        .map((op) => SelectOption(value: op, label: op.label))
        .toList();
  }
}
