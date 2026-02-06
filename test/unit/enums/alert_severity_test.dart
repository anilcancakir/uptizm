import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/alert_severity.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';

void main() {
  group('AlertSeverity', () {
    test('has critical, warning, and info values', () {
      expect(AlertSeverity.values.length, 3);
      expect(AlertSeverity.critical.value, 'critical');
      expect(AlertSeverity.warning.value, 'warning');
      expect(AlertSeverity.info.value, 'info');
    });

    test('fromValue returns correct enum', () {
      expect(AlertSeverity.fromValue('critical'), AlertSeverity.critical);
      expect(AlertSeverity.fromValue('warning'), AlertSeverity.warning);
      expect(AlertSeverity.fromValue('info'), AlertSeverity.info);
    });

    test('fromValue returns warning as default for invalid', () {
      expect(AlertSeverity.fromValue('invalid'), AlertSeverity.warning);
    });

    test('fromValue returns null for null input', () {
      expect(AlertSeverity.fromValue(null), isNull);
    });

    test('color returns correct Color for each severity', () {
      expect(AlertSeverity.critical.color, const Color(0xFFDC2626));
      expect(AlertSeverity.warning.color, const Color(0xFFF59E0B));
      expect(AlertSeverity.info.color, const Color(0xFF3B82F6));
    });

    test('selectOptions includes all severities', () {
      final options = AlertSeverity.selectOptions;
      expect(options.length, 3);
    });
  });
}
