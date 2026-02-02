import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/dashboard_controller.dart';

void main() {
  group('DashboardController', () {
    test('can be instantiated', () {
      final controller = DashboardController();
      expect(controller, isA<DashboardController>());
    });

    test('index() returns Widget', () {
      final controller = DashboardController();
      final result = controller.index();
      expect(result, isA<Widget>());
    });
  });
}
