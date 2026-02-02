import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';

void main() {
  group('MonitorController', () {
    test('can be instantiated', () {
      final controller = MonitorController();
      expect(controller, isA<MonitorController>());
    });

    test('create() returns Widget', () {
      final controller = MonitorController();
      final result = controller.create();
      expect(result, isA<Widget>());
    });
  });
}
