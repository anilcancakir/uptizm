import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/alert_status.dart';

void main() {
  group('AlertStatus', () {
    test('has alerting and resolved values', () {
      expect(AlertStatus.values.length, 2);
      expect(AlertStatus.alerting.value, 'alerting');
      expect(AlertStatus.resolved.value, 'resolved');
    });

    test('fromValue returns correct enum', () {
      expect(AlertStatus.fromValue('alerting'), AlertStatus.alerting);
      expect(AlertStatus.fromValue('resolved'), AlertStatus.resolved);
    });

    test('fromValue returns alerting as default', () {
      expect(AlertStatus.fromValue('invalid'), AlertStatus.alerting);
    });

    test('color returns correct Color', () {
      expect(AlertStatus.alerting.color, const Color(0xFFDC2626));
      expect(AlertStatus.resolved.color, const Color(0xFF22C55E));
    });

    test('label returns human-readable text', () {
      expect(AlertStatus.alerting.label, 'Alerting');
      expect(AlertStatus.resolved.label, 'Resolved');
    });
  });
}
