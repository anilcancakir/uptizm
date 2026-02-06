import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/alert_operator.dart';

void main() {
  group('AlertOperator', () {
    test('has all 8 operators', () {
      expect(AlertOperator.values.length, 8);
    });

    test('fromValue returns correct enum', () {
      expect(AlertOperator.fromValue('>'), AlertOperator.greaterThan);
      expect(AlertOperator.fromValue('>='), AlertOperator.greaterThanOrEqual);
      expect(AlertOperator.fromValue('<'), AlertOperator.lessThan);
      expect(AlertOperator.fromValue('<='), AlertOperator.lessThanOrEqual);
      expect(AlertOperator.fromValue('=='), AlertOperator.equal);
      expect(AlertOperator.fromValue('!='), AlertOperator.notEqual);
      expect(AlertOperator.fromValue('between'), AlertOperator.between);
      expect(AlertOperator.fromValue('outside'), AlertOperator.outside);
    });

    test('requiresRange returns true only for between and outside', () {
      expect(AlertOperator.between.requiresRange, isTrue);
      expect(AlertOperator.outside.requiresRange, isTrue);
      expect(AlertOperator.greaterThan.requiresRange, isFalse);
      expect(AlertOperator.lessThan.requiresRange, isFalse);
    });

    test('label returns human-readable text', () {
      expect(AlertOperator.greaterThan.label, 'Greater than (>)');
      expect(AlertOperator.between.label, 'Between');
    });
  });
}
