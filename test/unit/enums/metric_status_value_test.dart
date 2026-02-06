import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/metric_status_value.dart';

void main() {
  group('MetricStatusValue', () {
    test('has up, down, and unknown values', () {
      expect(MetricStatusValue.values.length, 3);
      expect(MetricStatusValue.up, isNotNull);
      expect(MetricStatusValue.down, isNotNull);
      expect(MetricStatusValue.unknown, isNotNull);
    });

    test('up has correct value and label', () {
      expect(MetricStatusValue.up.value, 'up');
      expect(MetricStatusValue.up.label, 'Up');
    });

    test('down has correct value and label', () {
      expect(MetricStatusValue.down.value, 'down');
      expect(MetricStatusValue.down.label, 'Down');
    });

    test('unknown has correct value and label', () {
      expect(MetricStatusValue.unknown.value, 'unknown');
      expect(MetricStatusValue.unknown.label, 'Unknown');
    });

    test('fromValue parses correctly', () {
      expect(MetricStatusValue.fromValue('up'), MetricStatusValue.up);
      expect(MetricStatusValue.fromValue('down'), MetricStatusValue.down);
      expect(MetricStatusValue.fromValue('unknown'), MetricStatusValue.unknown);
    });

    test('fromValue returns unknown for invalid value', () {
      expect(MetricStatusValue.fromValue('invalid'), MetricStatusValue.unknown);
    });

    test('fromValue returns null for null', () {
      expect(MetricStatusValue.fromValue(null), isNull);
    });

    test('selectOptions returns all options', () {
      final options = MetricStatusValue.selectOptions;
      expect(options.length, 3);
      expect(options.any((o) => o.value == MetricStatusValue.up), isTrue);
      expect(options.any((o) => o.value == MetricStatusValue.down), isTrue);
      expect(options.any((o) => o.value == MetricStatusValue.unknown), isTrue);
    });
  });
}
