import 'package:flutter/material.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';

enum AlertSeverity {
  critical('critical', 'Critical'),
  warning('warning', 'Warning'),
  info('info', 'Info');

  const AlertSeverity(this.value, this.label);

  final String value;
  final String label;

  static AlertSeverity? fromValue(String? value) {
    if (value == null) return null;
    try {
      return AlertSeverity.values.firstWhere((e) => e.value == value);
    } catch (e) {
      return AlertSeverity.warning;
    }
  }

  Color get color {
    switch (this) {
      case AlertSeverity.critical:
        return const Color(0xFFDC2626);
      case AlertSeverity.warning:
        return const Color(0xFFF59E0B);
      case AlertSeverity.info:
        return const Color(0xFF3B82F6);
    }
  }

  static List<SelectOption<AlertSeverity>> get selectOptions {
    return AlertSeverity.values
        .map((severity) => SelectOption(value: severity, label: severity.label))
        .toList();
  }
}
