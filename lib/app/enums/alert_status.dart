import 'package:flutter/material.dart';

enum AlertStatus {
  alerting('alerting', 'Alerting'),
  resolved('resolved', 'Resolved');

  const AlertStatus(this.value, this.label);

  final String value;
  final String label;

  static AlertStatus? fromValue(String? value) {
    if (value == null) return null;
    try {
      return AlertStatus.values.firstWhere((e) => e.value == value);
    } catch (e) {
      return AlertStatus.alerting;
    }
  }

  Color get color {
    switch (this) {
      case AlertStatus.alerting:
        return const Color(0xFFDC2626);
      case AlertStatus.resolved:
        return const Color(0xFF22C55E);
    }
  }
}
