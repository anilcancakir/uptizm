import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/alert_controller.dart';
import 'package:uptizm/app/models/alert_rule.dart';
import 'package:uptizm/app/models/alert.dart';

void main() {
  group('AlertController', () {
    late AlertController controller;

    setUp(() {
      controller = AlertController.instance;
      // Reset state before each test
      controller.alertRulesNotifier.value = [];
      controller.alertsNotifier.value = [];
      controller.activeAlertsNotifier.value = [];
      controller.isLoadingNotifier.value = false;
    });

    test('can be instantiated', () {
      expect(controller, isA<AlertController>());
    });

    test('alertRulesNotifier starts with empty list', () {
      expect(controller.alertRulesNotifier.value, isEmpty);
    });

    test('alertsNotifier starts with empty list', () {
      expect(controller.alertsNotifier.value, isEmpty);
    });

    test('activeAlertsNotifier starts with empty list', () {
      expect(controller.activeAlertsNotifier.value, isEmpty);
    });

    test('isLoadingNotifier starts as false', () {
      expect(controller.isLoadingNotifier.value, isFalse);
    });

    group('computed getters', () {
      test('activeAlertCount returns count of alerting alerts', () {
        controller.alertsNotifier.value = [
          Alert.fromMap({
            'id': 'test-uuid-1',
            'status': 'alerting',
            'triggered_at': '2026-02-05T10:00:00Z',
          }),
          Alert.fromMap({
            'id': 'test-uuid-2',
            'status': 'resolved',
            'triggered_at': '2026-02-05T10:00:00Z',
          }),
          Alert.fromMap({
            'id': 'test-uuid-3',
            'status': 'alerting',
            'triggered_at': '2026-02-05T10:00:00Z',
          }),
        ];
        expect(controller.activeAlertCount, 2);
      });

      test('criticalAlertCount returns count of critical alerting alerts', () {
        controller.alertsNotifier.value = [
          Alert.fromMap({
            'id': 'test-uuid-1',
            'status': 'alerting',
            'triggered_at': '2026-02-05T10:00:00Z',
            'alert_rule': {'severity': 'critical'},
          }),
          Alert.fromMap({
            'id': 'test-uuid-2',
            'status': 'alerting',
            'triggered_at': '2026-02-05T10:00:00Z',
            'alert_rule': {'severity': 'warning'},
          }),
        ];
        expect(controller.criticalAlertCount, 1);
      });
    });

    group('teamLevelRules and monitorLevelRules', () {
      test('filters rules by scope correctly', () {
        controller.alertRulesNotifier.value = [
          AlertRule.fromMap({
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-10',
            'monitor_id': null,
            'name': 'Team Rule',
          }),
          AlertRule.fromMap({
            'id': 'test-uuid-2',
            'team_id': 'test-team-uuid-10',
            'monitor_id': 'test-monitor-uuid-20',
            'name': 'Monitor Rule',
          }),
        ];

        expect(controller.teamLevelRules.length, 1);
        expect(controller.teamLevelRules.first.name, 'Team Rule');
        expect(controller.monitorLevelRules.length, 1);
        expect(controller.monitorLevelRules.first.name, 'Monitor Rule');
      });
    });
  });
}
