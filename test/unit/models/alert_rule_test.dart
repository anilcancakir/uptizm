import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/alert_rule.dart';
import 'package:uptizm/app/enums/alert_rule_type.dart';
import 'package:uptizm/app/enums/alert_severity.dart';
import 'package:uptizm/app/enums/alert_operator.dart';

void main() {
  group('AlertRule', () {
    test('table returns alert_rules', () {
      final rule = AlertRule();
      expect(rule.table, 'alert_rules');
    });

    test('resource returns alert-rules', () {
      final rule = AlertRule();
      expect(rule.resource, 'alert-rules');
    });

    test('fillable includes all required fields', () {
      final rule = AlertRule();
      expect(
        rule.fillable,
        containsAll([
          'team_id',
          'monitor_id',
          'name',
          'type',
          'enabled',
          'metric_key',
          'operator',
          'threshold_value',
          'threshold_min',
          'threshold_max',
          'severity',
          'consecutive_checks',
        ]),
      );
    });

    group('fromMap', () {
      test('creates instance with complete data', () {
        final map = {
          'id': 1,
          'team_id': 10,
          'monitor_id': 20,
          'name': 'High Response Time',
          'type': 'threshold',
          'enabled': true,
          'metric_key': 'response_time',
          'operator': '>',
          'threshold_value': 5000.0,
          'severity': 'warning',
          'consecutive_checks': 2,
          'created_at': '2026-02-05T10:00:00Z',
        };

        final rule = AlertRule.fromMap(map);

        expect(rule.id, 1);
        expect(rule.teamId, 10);
        expect(rule.monitorId, 20);
        expect(rule.name, 'High Response Time');
        expect(rule.type, AlertRuleType.threshold);
        expect(rule.enabled, isTrue);
        expect(rule.metricKey, 'response_time');
        expect(rule.operator, AlertOperator.greaterThan);
        expect(rule.thresholdValue, 5000.0);
        expect(rule.severity, AlertSeverity.warning);
        expect(rule.consecutiveChecks, 2);
      });

      test('creates team-level rule when monitor_id is null', () {
        final map = {
          'id': 1,
          'team_id': 10,
          'monitor_id': null,
          'name': 'Team Default',
          'type': 'status',
          'severity': 'critical',
        };

        final rule = AlertRule.fromMap(map);
        expect(rule.isTeamLevel, isTrue);
        expect(rule.isMonitorLevel, isFalse);
      });

      test('creates monitor-level rule when monitor_id is set', () {
        final map = {
          'id': 1,
          'team_id': 10,
          'monitor_id': 20,
          'name': 'Monitor Override',
          'type': 'threshold',
        };

        final rule = AlertRule.fromMap(map);
        expect(rule.isTeamLevel, isFalse);
        expect(rule.isMonitorLevel, isTrue);
      });

      test('handles range operators with min/max', () {
        final map = {
          'id': 1,
          'team_id': 10,
          'name': 'Between Rule',
          'type': 'threshold',
          'operator': 'between',
          'threshold_min': 100.0,
          'threshold_max': 500.0,
        };

        final rule = AlertRule.fromMap(map);
        expect(rule.operator, AlertOperator.between);
        expect(rule.thresholdMin, 100.0);
        expect(rule.thresholdMax, 500.0);
      });

      test('handles anomaly type rule', () {
        final map = {
          'id': 1,
          'team_id': 10,
          'name': 'Order Anomaly',
          'type': 'anomaly',
          'metric_key': 'order_count',
          'severity': 'warning',
        };

        final rule = AlertRule.fromMap(map);
        expect(rule.type, AlertRuleType.anomaly);
        expect(rule.metricKey, 'order_count');
      });

      test('defaults enabled to true', () {
        final map = {'id': 1, 'team_id': 10, 'name': 'Test'};
        final rule = AlertRule.fromMap(map);
        expect(rule.enabled, isTrue);
      });

      test('defaults consecutiveChecks to 1', () {
        final map = {'id': 1, 'team_id': 10, 'name': 'Test'};
        final rule = AlertRule.fromMap(map);
        expect(rule.consecutiveChecks, 1);
      });
    });

    group('setters', () {
      test('type setter stores correct value', () {
        final rule = AlertRule();
        rule.type = AlertRuleType.anomaly;
        expect(rule.getAttribute('type'), 'anomaly');
      });

      test('severity setter stores correct value', () {
        final rule = AlertRule();
        rule.severity = AlertSeverity.critical;
        expect(rule.getAttribute('severity'), 'critical');
      });

      test('operator setter stores correct value', () {
        final rule = AlertRule();
        rule.operator = AlertOperator.between;
        expect(rule.getAttribute('operator'), 'between');
      });
    });
  });
}
