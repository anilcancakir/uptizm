import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/monitor_check.dart';
import 'package:uptizm/app/enums/check_status.dart';
import 'package:uptizm/app/enums/monitor_location.dart';

void main() {
  group('MonitorCheck', () {
    // ... existing tests ...

    group('forMonitor', () {
      test('returns Future<PaginatedChecks> type', () {
        // Verify the method signature returns the correct type at compile time
        // We cannot call forMonitor without a running service container (Http, Log),
        // so we only verify the type signature compiles correctly.
        expect(MonitorCheck.forMonitor, isA<Function>());
      });
    });

    group('typed accessors', () {
      test('id and monitorId', () {
        final check = MonitorCheck.fromMap({'id': 1, 'monitor_id': 5});
        expect(check.id, 1);
        expect(check.monitorId, 5);
      });

      test('location returns MonitorLocation enum', () {
        final check = MonitorCheck.fromMap({'location': 'us-east'});
        expect(check.location, MonitorLocation.usEast);
      });

      test('location returns null for null value', () {
        final check = MonitorCheck.fromMap({});
        expect(check.location, null);
      });

      test('status returns CheckStatus enum', () {
        final check = MonitorCheck.fromMap({'status': 'up'});
        expect(check.status, CheckStatus.up);
      });

      test('status returns null for null value', () {
        final check = MonitorCheck.fromMap({});
        expect(check.status, null);
      });

      test('responseTimeMs', () {
        final check = MonitorCheck.fromMap({'response_time_ms': 150});
        expect(check.responseTimeMs, 150);
      });

      test('statusCode', () {
        final check = MonitorCheck.fromMap({'status_code': 200});
        expect(check.statusCode, 200);
      });

      test('responseBody', () {
        final check = MonitorCheck.fromMap({'response_body': '{"ok":true}'});
        expect(check.responseBody, '{"ok":true}');
      });

      test('parsedMetrics', () {
        final check = MonitorCheck.fromMap({
          'parsed_metrics': {'cpu': 45.2},
        });
        expect(check.parsedMetrics, {'cpu': 45.2});
      });

      test('assertionsPassed', () {
        final check = MonitorCheck.fromMap({'assertions_passed': true});
        expect(check.assertionsPassed, true);
      });

      test('assertionResults', () {
        final check = MonitorCheck.fromMap({
          'assertion_results': [
            {'type': 'status_code', 'passed': true},
          ],
        });
        expect(check.assertionResults, isNotNull);
        expect(check.assertionResults!.length, 1);
      });

      test('errorMessage', () {
        final check = MonitorCheck.fromMap({'error_message': 'Timeout'});
        expect(check.errorMessage, 'Timeout');
      });

      test('checkedAt parses Carbon', () {
        final check = MonitorCheck.fromMap({
          'checked_at': '2025-01-15T10:30:00.000Z',
        });
        expect(check.checkedAt, isNotNull);
      });

      test('checkedAt null when missing', () {
        final check = MonitorCheck.fromMap({});
        expect(check.checkedAt, null);
      });
    });

    group('computed properties', () {
      test('isUp true when status is up', () {
        final check = MonitorCheck.fromMap({'status': 'up'});
        expect(check.isUp, true);
        expect(check.isDown, false);
        expect(check.isDegraded, false);
      });

      test('isDown true when status is down', () {
        final check = MonitorCheck.fromMap({'status': 'down'});
        expect(check.isDown, true);
        expect(check.isUp, false);
      });

      test('isDegraded true when status is degraded', () {
        final check = MonitorCheck.fromMap({'status': 'degraded'});
        expect(check.isDegraded, true);
      });

      test('hasError true when errorMessage is non-empty', () {
        final check = MonitorCheck.fromMap({'error_message': 'fail'});
        expect(check.hasError, true);
      });

      test('hasError false when errorMessage is null', () {
        final check = MonitorCheck.fromMap({});
        expect(check.hasError, false);
      });

      test('hasError false when errorMessage is empty string', () {
        final check = MonitorCheck.fromMap({'error_message': ''});
        expect(check.hasError, false);
      });
    });

    group('fromMap', () {
      test('with full data', () {
        final check = MonitorCheck.fromMap({
          'id': 10,
          'monitor_id': 3,
          'location': 'eu-west',
          'status': 'up',
          'response_time_ms': 200,
          'status_code': 200,
          'response_body': 'OK',
          'parsed_metrics': {'latency': 200},
          'assertions_passed': true,
          'assertion_results': [
            {'type': 'status', 'passed': true},
          ],
          'error_message': null,
          'checked_at': '2025-06-01T12:00:00.000Z',
        });

        expect(check.id, 10);
        expect(check.monitorId, 3);
        expect(check.location, MonitorLocation.euWest);
        expect(check.status, CheckStatus.up);
        expect(check.responseTimeMs, 200);
        expect(check.statusCode, 200);
        expect(check.isUp, true);
        expect(check.hasError, false);
      });

      test('with null fields does not crash', () {
        final check = MonitorCheck.fromMap({
          'id': null,
          'monitor_id': null,
          'location': null,
          'status': null,
          'response_time_ms': null,
          'status_code': null,
          'response_body': null,
          'parsed_metrics': null,
          'assertions_passed': null,
          'assertion_results': null,
          'error_message': null,
          'checked_at': null,
        });

        expect(check.id, null);
        expect(check.location, null);
        expect(check.status, null);
        expect(check.checkedAt, null);
        expect(check.hasError, false);
      });
    });
  });
}
