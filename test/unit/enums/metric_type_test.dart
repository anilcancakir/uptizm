import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/metric_type.dart';

void main() {
  group('MetricType', () {
    test('has numeric, string, and status values', () {
      expect(MetricType.values.length, 3);
      expect(MetricType.numeric, isNotNull);
      expect(MetricType.string, isNotNull);
      expect(MetricType.status, isNotNull);
    });

    test('status has correct value and label', () {
      expect(MetricType.status.value, 'status');
      expect(MetricType.status.label, 'Status');
    });

    test('fromValue returns MetricType.status for "status"', () {
      final result = MetricType.fromValue('status');
      expect(result, MetricType.status);
    });

    test('fromValue returns null for unknown value', () {
      final result = MetricType.fromValue('unknown');
      expect(result, isNull);
    });

    test('fromValue returns null for null', () {
      final result = MetricType.fromValue(null);
      expect(result, isNull);
    });

    test('selectOptions includes status option', () {
      final options = MetricType.selectOptions;
      expect(options.length, 3);
      expect(options.any((o) => o.value == MetricType.status), isTrue);
    });
  });
}
