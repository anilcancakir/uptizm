import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/alert_rule_type.dart';

void main() {
  group('AlertRuleType', () {
    test('has status, threshold, and anomaly values', () {
      expect(AlertRuleType.values.length, 3);
      expect(AlertRuleType.status.value, 'status');
      expect(AlertRuleType.threshold.value, 'threshold');
      expect(AlertRuleType.anomaly.value, 'anomaly');
    });

    test('fromValue returns correct enum for valid values', () {
      expect(AlertRuleType.fromValue('status'), AlertRuleType.status);
      expect(AlertRuleType.fromValue('threshold'), AlertRuleType.threshold);
      expect(AlertRuleType.fromValue('anomaly'), AlertRuleType.anomaly);
    });

    test('fromValue returns null for invalid value', () {
      expect(AlertRuleType.fromValue('invalid'), isNull);
      expect(AlertRuleType.fromValue(null), isNull);
    });

    test('selectOptions includes all types', () {
      final options = AlertRuleType.selectOptions;
      expect(options.length, 3);
      expect(options.any((o) => o.value == AlertRuleType.status), isTrue);
    });

    test('label returns human-readable text', () {
      expect(AlertRuleType.status.label, 'Status');
      expect(AlertRuleType.threshold.label, 'Threshold');
      expect(AlertRuleType.anomaly.label, 'Anomaly');
    });
  });
}
