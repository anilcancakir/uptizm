import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/metric_type.dart';

void main() {
  group('MetricType', () {
    test('has numeric type', () {
      expect(MetricType.numeric.value, 'numeric');
      expect(MetricType.numeric.label, 'Numeric');
    });

    test('has string type', () {
      expect(MetricType.string.value, 'string');
      expect(MetricType.string.label, 'String');
    });

    test('fromValue returns correct type', () {
      expect(MetricType.fromValue('numeric'), MetricType.numeric);
      expect(MetricType.fromValue('string'), MetricType.string);
    });

    test('fromValue returns null for invalid value', () {
      expect(MetricType.fromValue('invalid'), null);
      expect(MetricType.fromValue(null), null);
    });

    test('selectOptions includes all types', () {
      final options = MetricType.selectOptions;
      expect(options.length, 2);
      expect(options.any((opt) => opt.value == MetricType.numeric), true);
      expect(options.any((opt) => opt.value == MetricType.string), true);
    });
  });
}
