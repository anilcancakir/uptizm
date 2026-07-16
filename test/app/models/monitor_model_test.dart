import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/models/monitor.dart';

/// A representative `MonitorResource` payload (backend `api/v1` snake_case
/// keys), matching `backend/app/Http/Resources/MonitorResource.php`. Used to
/// assert that [Monitor.fromMap] hydrates every accessor on the new model.
final Map<String, dynamic> _monitorResourcePayload = <String, dynamic>{
  'id': 'api',
  'team_id': 'team-1',
  'name': 'API gateway',
  'url': 'https://api.uptizm.com/health',
  'type': 'http',
  'method': 'GET',
  'status': 'active',
  'last_status': 'degraded',
  'check_interval_sec': 30,
  'timeout_sec': 10,
  'regions': <String>['us-east', 'eu-west'],
  'expected_status_code': 200,
  'request_headers': <String, dynamic>{'X-Api-Key': 'redacted'},
  'auth_config': <String, dynamic>{'type': 'bearer'},
  'slo_target': 99.95,
  'tags': <String>['critical', 'edge'],
  'show_on_status_page': true,
  'only_show_if_degraded': false,
  'alert_on_down': true,
  'alert_on_recover': true,
  'ssl_tracking': true,
  'ssl_expires_at': '2026-12-01T00:00:00.000Z',
  'ssl_last_checked_at': '2026-07-10T00:00:00.000Z',
  'ssl_alert_threshold_days': 14,
  'is_group': false,
  'parent_id': null,
  'last_checked_at': '2026-07-12T14:32:05.000Z',
  'last_response_ms': 412,
  'next_check_at': '2026-07-12T14:32:35.000Z',
  'consecutive_fails': 1,
  'incident_threshold': 2,
  'created_at': '2026-07-01T10:00:00.000Z',
  'updated_at': '2026-07-12T14:32:05.000Z',
};

void main() {
  setUp(() {
    // Bind a fake network driver so Monitor.find / Monitor.all resolve the
    // `network` service. Individual tests override it with seeded envelopes.
    Http.fake();
  });

  tearDown(() {
    Magic.flush();
  });

  group('Monitor.fromMap', () {
    test('hydrates every MonitorSummary accessor from a resource payload', () {
      final Monitor monitor = Monitor.fromMap(_monitorResourcePayload);

      // The full MonitorSummary accessor surface.
      expect(monitor.id, 'api');
      expect(monitor.name, 'API gateway');
      expect(monitor.url, 'https://api.uptizm.com/health');
      expect(monitor.status, StatusKey.degraded);
      expect(monitor.responseMs, 412);
      expect(monitor.uptime, '—');
      expect(monitor.intervalLabel, '30s');
      expect(monitor.regions, <String>['us-east', 'eu-west']);
      expect(monitor.sloTarget, 99.95);
      expect(monitor.sloUptime7d, isNull);
      expect(monitor.sloUptime30d, isNull);
    });

    test('hydrates the typed write-surface + runtime-state accessors', () {
      final Monitor monitor = Monitor.fromMap(_monitorResourcePayload);

      // Scalar write surface.
      expect(monitor.type, 'http');
      expect(monitor.method, 'GET');
      expect(monitor.checkIntervalSec, 30);
      expect(monitor.timeoutSec, 10);
      expect(monitor.expectedStatusCode, 200);
      expect(monitor.requestHeaders, {'X-Api-Key': 'redacted'});
      expect(monitor.authConfig, {'type': 'bearer'});
      expect(monitor.tags, <String>['critical', 'edge']);
      expect(monitor.sslAlertThresholdDays, 14);

      // Boolean flags cast from wire truthiness.
      expect(monitor.showOnStatusPage, isTrue);
      expect(monitor.onlyShowIfDegraded, isFalse);
      expect(monitor.alertOnDown, isTrue);
      expect(monitor.alertOnRecover, isTrue);
      expect(monitor.sslTracking, isTrue);
      expect(monitor.isGroup, isFalse);

      // Runtime state.
      expect(monitor.teamId, 'team-1');
      expect(monitor.lastStatus, 'degraded');
      expect(monitor.consecutiveFails, 1);
      expect(monitor.incidentThreshold, 2);
      expect(monitor.createdAt, isNotNull);
      expect(monitor.updatedAt, isNotNull);
      expect(monitor.lastCheckedAt, isNotNull);
    });

    test('marks the model as existing when the payload carries an id', () {
      final Monitor monitor = Monitor.fromMap(_monitorResourcePayload);
      expect(monitor.exists, isTrue);
    });

    test('marks the model as non-existing when the payload has no id', () {
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'name': 'Unsaved monitor',
        'url': 'https://example.com',
      });
      expect(monitor.exists, isFalse);
      expect(monitor.name, 'Unsaved monitor');
    });

    test('uptime defaults to the em-dash placeholder when not emitted', () {
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'id': 'no-uptime',
        'name': 'No rollup',
        'check_interval_sec': 30,
      });
      expect(monitor.uptime, '—');
      expect(monitor.intervalLabel, '30s');
    });

    test('intervalLabel falls back to the em-dash without check_interval_sec', () {
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'id': 'no-interval',
        'name': 'No interval',
      });
      expect(monitor.intervalLabel, '—');
    });

    test('regions defaults to an empty list when absent on the wire', () {
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'id': 'no-regions',
        'name': 'No regions',
      });
      expect(monitor.regions, <String>[]);
    });
  });

  group('Monitor computed status', () {
    test('admin status paused wins over an up last_status', () {
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'id': 'docs',
        'name': 'Docs',
        'url': 'https://docs.uptizm.com',
        'status': 'paused',
        'last_status': 'up',
        'check_interval_sec': 60,
        'regions': <String>['eu-central'],
      });

      expect(monitor.status, StatusKey.paused);
    });

    test('admin active + last_status degraded resolves to degraded', () {
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'id': 'api',
        'name': 'API',
        'url': 'https://api.uptizm.com',
        'status': 'active',
        'last_status': 'degraded',
        'check_interval_sec': 30,
      });

      expect(monitor.status, StatusKey.degraded);
    });

    test('admin active + last_status up resolves to up', () {
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'id': 'marketing',
        'name': 'Marketing',
        'url': 'https://uptizm.com',
        'status': 'active',
        'last_status': 'up',
        'check_interval_sec': 30,
      });

      expect(monitor.status, StatusKey.up);
    });

    test('an unknown last_status wire value falls back safely', () {
      // A stale client must never crash on a probe status it does not know.
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'id': 'weird',
        'name': 'Weird',
        'url': 'https://weird.example.com',
        'status': 'active',
        'last_status': 'not_a_real_status',
        'check_interval_sec': 60,
      });

      expect(monitor.status, StatusKey.info);
    });

    test('admin active + no last_status yet resolves to pending', () {
      // A freshly created monitor awaiting its first check has no last_status;
      // it must read as neutral "Pending", not info/"Maintenance".
      final Monitor monitor = Monitor.fromMap(<String, dynamic>{
        'id': 'fresh',
        'name': 'Fresh',
        'url': 'https://fresh.example.com',
        'status': 'active',
        'last_status': null,
        'check_interval_sec': 60,
      });

      expect(monitor.status, StatusKey.pending);
    });
  });

  group('Monitor persistence routing', () {
    test('all() hydrates every monitor from GET /monitors', () async {
      Http.fake({
        'monitors': Http.response({
          'data': <Map<String, dynamic>>[
            <String, dynamic>{
              'id': 'api',
              'name': 'API',
              'url': 'https://api.uptizm.com',
              'status': 'active',
              'last_status': 'up',
              'check_interval_sec': 30,
              'regions': <String>['us-east'],
              'last_response_ms': 120,
            },
            <String, dynamic>{
              'id': 'docs',
              'name': 'Docs',
              'url': 'https://docs.uptizm.com',
              'status': 'paused',
              'last_status': 'up',
              'check_interval_sec': 60,
              'regions': <String>['eu-central'],
            },
          ],
        }),
      });

      final List<Monitor> monitors = await Monitor.all();

      expect(monitors.length, 2);
      expect(monitors[0].id, 'api');
      expect(monitors[0].status, StatusKey.up);
      expect(monitors[0].responseMs, 120);
      expect(monitors[0].exists, isTrue);
      expect(monitors[1].id, 'docs');
      expect(monitors[1].status, StatusKey.paused);
    });

    test('find(id) hydrates a single monitor from GET /monitors/:id', () async {
      Http.fake({
        'monitors/*': Http.response({
          'data': <String, dynamic>{
            'id': 'api',
            'name': 'API gateway',
            'url': 'https://api.uptizm.com/health',
            'status': 'active',
            'last_status': 'degraded',
            'check_interval_sec': 30,
            'regions': <String>['us-east', 'eu-west'],
            'last_response_ms': 412,
          },
        }),
      });

      final Monitor? monitor = await Monitor.find('api');

      expect(monitor, isNotNull);
      expect(monitor!.id, 'api');
      expect(monitor.name, 'API gateway');
      expect(monitor.status, StatusKey.degraded);
      expect(monitor.responseMs, 412);
      expect(monitor.regions, <String>['us-east', 'eu-west']);
      expect(monitor.exists, isTrue);
    });

    test('find(unknown) returns null when the API responds non-2xx', () async {
      Http.fake({
        'monitors/*': Http.response(<String, dynamic>{}, 404),
      });

      final Monitor? monitor = await Monitor.find('missing');

      expect(monitor, isNull);
    });
  });

  group('Monitor.fromJson', () {
    test('hydrates from a JSON string and delegates to fromMap', () {
      final Monitor monitor = Monitor.fromJson(jsonEncode(<String, dynamic>{
        'id': 'api',
        'name': 'API',
        'url': 'https://api.uptizm.com',
        'status': 'active',
        'last_status': 'up',
        'check_interval_sec': 30,
      }));

      expect(monitor.id, 'api');
      expect(monitor.intervalLabel, '30s');
      expect(monitor.status, StatusKey.up);
      expect(monitor.exists, isTrue);
    });
  });

  group('Monitor resource configuration', () {
    test('targets the monitors API resource and non-incrementing key', () {
      final Monitor monitor = Monitor();

      expect(monitor.table, 'monitors');
      expect(monitor.resource, 'monitors');
      expect(monitor.incrementing, isFalse);
    });

    test('fillable covers the create/edit write surface', () {
      final Monitor monitor = Monitor();

      // Every field validated by StoreMonitorRequest must be mass-assignable
      // so the create/edit form can hydrate the model via fill().
      for (final field in <String>[
        'name',
        'url',
        'type',
        'method',
        'check_interval_sec',
        'timeout_sec',
        'regions',
        'expected_status_code',
        'request_headers',
        'request_body',
        'slo_target',
        'tags',
        'show_on_status_page',
        'only_show_if_degraded',
        'alert_on_down',
        'alert_on_recover',
        'ssl_tracking',
        'ssl_alert_threshold_days',
        'auth_config',
      ]) {
        expect(monitor.fillable, contains(field));
      }
    });
  });
}
