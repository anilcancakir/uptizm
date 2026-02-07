import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/alert.dart';
import 'package:uptizm/app/enums/alert_status.dart';

void main() {
  group('Alert', () {
    test('table returns alerts', () {
      final alert = Alert();
      expect(alert.table, 'alerts');
    });

    test('resource returns alerts', () {
      final alert = Alert();
      expect(alert.resource, 'alerts');
    });

    group('fromMap', () {
      test('creates instance with complete data', () {
        final map = {
          'id': 'test-uuid-1',
          'alert_rule_id': 'test-alert-rule-uuid-10',
          'monitor_id': 'test-monitor-uuid-20',
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
          'resolved_at': null,
          'trigger_value': 6500.0,
          'trigger_message': 'Response time exceeded 5000ms',
        };

        final alert = Alert.fromMap(map);

        expect(alert.id, 'test-uuid-1');
        expect(alert.alertRuleId, 'test-alert-rule-uuid-10');
        expect(alert.monitorId, 'test-monitor-uuid-20');
        expect(alert.status, AlertStatus.alerting);
        expect(alert.triggeredAt, DateTime.utc(2026, 2, 5, 10, 0, 0));
        expect(alert.resolvedAt, isNull);
        expect(alert.triggerValue, 6500.0);
        expect(alert.triggerMessage, 'Response time exceeded 5000ms');
      });

      test('creates resolved alert with resolvedAt', () {
        final map = {
          'id': 'test-uuid-1',
          'alert_rule_id': 'test-alert-rule-uuid-10',
          'monitor_id': 'test-monitor-uuid-20',
          'status': 'resolved',
          'triggered_at': '2026-02-05T10:00:00Z',
          'resolved_at': '2026-02-05T10:15:00Z',
        };

        final alert = Alert.fromMap(map);
        expect(alert.status, AlertStatus.resolved);
        expect(alert.isResolved, isTrue);
        expect(alert.resolvedAt, DateTime.utc(2026, 2, 5, 10, 15, 0));
      });
    });

    group('computed properties', () {
      test('isAlerting returns true when status is alerting', () {
        final alert = Alert.fromMap({
          'id': 'test-uuid-1',
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
        });
        expect(alert.isAlerting, isTrue);
        expect(alert.isResolved, isFalse);
      });

      test('duration calculates correctly for resolved alert', () {
        final alert = Alert.fromMap({
          'id': 'test-uuid-1',
          'status': 'resolved',
          'triggered_at': '2026-02-05T10:00:00Z',
          'resolved_at': '2026-02-05T10:15:00Z',
        });
        expect(alert.duration, const Duration(minutes: 15));
      });

      test('duration returns null when triggeredAt is null', () {
        final alert = Alert();
        expect(alert.duration, isNull);
      });
    });
  });
}
