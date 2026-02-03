import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/assertion_operator.dart';

void main() {
  group('AssertionOperator', () {
    test('has equals operator', () {
      expect(AssertionOperator.equals.value, 'equals');
      expect(AssertionOperator.equals.label, '==');
    });

    test('has notEquals operator', () {
      expect(AssertionOperator.notEquals.value, 'not_equals');
      expect(AssertionOperator.notEquals.label, '!=');
    });

    test('has greaterThan operator', () {
      expect(AssertionOperator.greaterThan.value, 'greater_than');
      expect(AssertionOperator.greaterThan.label, '>');
    });

    test('has lessThan operator', () {
      expect(AssertionOperator.lessThan.value, 'less_than');
      expect(AssertionOperator.lessThan.label, '<');
    });

    test('has contains operator', () {
      expect(AssertionOperator.contains.value, 'contains');
      expect(AssertionOperator.contains.label, 'contains');
    });

    test('has notContains operator', () {
      expect(AssertionOperator.notContains.value, 'not_contains');
      expect(AssertionOperator.notContains.label, 'not contains');
    });

    test('has matchesRegex operator', () {
      expect(AssertionOperator.matchesRegex.value, 'matches_regex');
      expect(AssertionOperator.matchesRegex.label, 'matches');
    });

    test('fromValue returns correct operator', () {
      expect(AssertionOperator.fromValue('equals'), AssertionOperator.equals);
      expect(
        AssertionOperator.fromValue('not_equals'),
        AssertionOperator.notEquals,
      );
      expect(
        AssertionOperator.fromValue('greater_than'),
        AssertionOperator.greaterThan,
      );
    });

    test('fromValue returns null for invalid value', () {
      expect(AssertionOperator.fromValue('invalid'), null);
      expect(AssertionOperator.fromValue(null), null);
    });

    test('selectOptions includes all operators', () {
      final options = AssertionOperator.selectOptions;
      expect(options.length, 7);
      expect(options.any((opt) => opt.value == AssertionOperator.equals), true);
      expect(
        options.any((opt) => opt.value == AssertionOperator.notEquals),
        true,
      );
      expect(
        options.any((opt) => opt.value == AssertionOperator.greaterThan),
        true,
      );
      expect(
        options.any((opt) => opt.value == AssertionOperator.lessThan),
        true,
      );
      expect(
        options.any((opt) => opt.value == AssertionOperator.contains),
        true,
      );
      expect(
        options.any((opt) => opt.value == AssertionOperator.notContains),
        true,
      );
      expect(
        options.any((opt) => opt.value == AssertionOperator.matchesRegex),
        true,
      );
    });
  });
}
