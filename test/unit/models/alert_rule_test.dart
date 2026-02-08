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
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
          'monitor_id': 'test-monitor-uuid-20',
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

        expect(rule.id, 'test-uuid-1');
        expect(rule.teamId, 'test-team-uuid-10');
        expect(rule.monitorId, 'test-monitor-uuid-20');
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
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
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
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
          'monitor_id': 'test-monitor-uuid-20',
          'name': 'Monitor Override',
          'type': 'threshold',
        };

        final rule = AlertRule.fromMap(map);
        expect(rule.isTeamLevel, isFalse);
        expect(rule.isMonitorLevel, isTrue);
      });

      test('handles range operators with min/max', () {
        final map = {
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
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
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
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
        final map = {
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
          'name': 'Test',
        };
        final rule = AlertRule.fromMap(map);
        expect(rule.enabled, isTrue);
      });

      test('defaults consecutiveChecks to 1', () {
        final map = {
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
          'name': 'Test',
        };
        final rule = AlertRule.fromMap(map);
        expect(rule.consecutiveChecks, 1);
      });

      group('handles String values from API', () {
        test('parses threshold values from String', () {
          final map = {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-10',
            'name': 'String Test',
            'type': 'threshold',
            'threshold_value': '5000.0000',
            'threshold_min': '100.50',
            'threshold_max': '500.99',
          };

          final rule = AlertRule.fromMap(map);

          expect(rule.thresholdValue, 5000.0);
          expect(rule.thresholdMin, 100.50);
          expect(rule.thresholdMax, 500.99);
        });

        test('parses consecutiveChecks from String', () {
          final map = {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-10',
            'name': 'String Test',
            'consecutive_checks': '3',
          };

          final rule = AlertRule.fromMap(map);
          expect(rule.consecutiveChecks, 3);
        });

        test('handles null threshold values', () {
          final map = {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-10',
            'name': 'Null Test',
            'threshold_value': null,
            'threshold_min': null,
            'threshold_max': null,
          };

          final rule = AlertRule.fromMap(map);

          expect(rule.thresholdValue, isNull);
          expect(rule.thresholdMin, isNull);
          expect(rule.thresholdMax, isNull);
        });

        test('handles empty String threshold values', () {
          final map = {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-10',
            'name': 'Empty Test',
            'threshold_value': '',
            'threshold_min': '',
            'threshold_max': '',
          };

          final rule = AlertRule.fromMap(map);

          expect(rule.thresholdValue, isNull);
          expect(rule.thresholdMin, isNull);
          expect(rule.thresholdMax, isNull);
        });

        test('handles empty String consecutiveChecks defaults to 1', () {
          final map = {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-10',
            'name': 'Empty Test',
            'consecutive_checks': '',
          };

          final rule = AlertRule.fromMap(map);
          expect(rule.consecutiveChecks, 1);
        });
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
