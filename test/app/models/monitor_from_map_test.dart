import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';

void main() {
  group('MonitorSummary.fromMap', () {
    test('decodes a backend MonitorResource payload', () {
      final MonitorSummary monitor = MonitorSummary.fromMap({
        'id': 'api',
        'name': 'API gateway',
        'url': 'https://api.uptizm.com/health',
        'type': 'http',
        'method': 'GET',
        'status': 'active',
        'last_status': 'degraded',
        'check_interval_sec': 30,
        'timeout_sec': 10,
        'regions': ['us-east', 'eu-west'],
        'expected_status_code': 200,
        'slo_target': 99.95,
        'show_on_status_page': true,
        'only_show_if_degraded': false,
        'last_response_ms': 412,
      });

      expect(monitor.status, StatusKey.degraded);
      expect(monitor.intervalLabel, '30s');
      expect(monitor.responseMs, 412);
      expect(monitor.sloTarget, 99.95);
      expect(monitor.regions, ['us-east', 'eu-west']);
    });

    test('admin status active does not override an unknown last_status', () {
      final MonitorSummary monitor = MonitorSummary.fromMap({
        'id': 'weird',
        'name': 'Weird monitor',
        'url': 'https://weird.example.com',
        'status': 'active',
        'last_status': 'not_a_real_status',
        'check_interval_sec': 60,
        'regions': <String>[],
      });

      // Unknown wire value must fall back to a safe default, never throw.
      expect(monitor.status, StatusKey.info);
    });

    test('admin status paused wins over last_status', () {
      final MonitorSummary monitor = MonitorSummary.fromMap({
        'id': 'docs',
        'name': 'Docs',
        'url': 'https://docs.uptizm.com',
        'status': 'paused',
        'last_status': 'up',
        'check_interval_sec': 60,
        'regions': ['eu-central'],
      });

      expect(monitor.status, StatusKey.paused);
    });

    test('toMap round-trips the editable fields', () {
      const MonitorSummary monitor = MonitorSummary(
        id: 'marketing',
        name: 'Marketing site',
        url: 'https://uptizm.com',
        status: StatusKey.up,
        uptime: '100.00%',
        intervalLabel: '30s',
        regions: ['us-east', 'eu-west'],
        sloTarget: 99.9,
      );

      final Map<String, dynamic> map = monitor.toMap();

      expect(map['name'], 'Marketing site');
      expect(map['url'], 'https://uptizm.com');
      expect(map['check_interval_sec'], 30);
      expect(map['slo_target'], 99.9);
      expect(map['regions'], ['us-east', 'eu-west']);
    });
  });

  group('CheckRow.fromMap', () {
    test('decodes a backend MonitorCheckResource payload', () {
      final CheckRow row = CheckRow.fromMap({
        'region': 'us-east',
        'status': 'up',
        'status_code': 200,
        'response_ms': 142,
        'checked_at': '2026-07-09T14:32:05.000Z',
        'error_message': null,
      });

      expect(row.region, 'us-east');
      expect(row.status, StatusKey.up);
      expect(row.statusCode, 200);
      expect(row.responseMs, 142);
    });

    test('an unknown status wire value falls back safely', () {
      final CheckRow row = CheckRow.fromMap({
        'region': 'eu-west',
        'status': 'totally_unknown',
        'checked_at': '2026-07-09T14:32:05.000Z',
      });

      expect(row.status, StatusKey.info);
    });
  });

  group('IncidentSummary.fromMap', () {
    test('decodes lifecycle, severity, signal source, and impact safely', () {
      final IncidentSummary incident = IncidentSummary.fromMap({
        'id': 'checkout-503',
        'title': 'Checkout service returning 503s across all regions',
        'lifecycle': 'investigating',
        'severity': 'warn',
        'impact': 'critical',
        'signal_source': 'ai_anomaly',
        'ai_owned': true,
        'primary_monitor_id': 'checkout',
        'started_at': '2026-07-09T14:20:00.000Z',
        'resolved_at': null,
        'monitors': [
          {
            'monitor_id': 'checkout',
            'name': 'Checkout service',
            'component_status_at_start': 'down',
            'component_status_current': 'down',
          },
        ],
        'updates': [
          {
            'actor': 'human',
            'status': 'Investigating',
            'message': 'Rolling back the latest release now.',
            'is_public': true,
            'autonomous': false,
            'display_at': '2026-07-09T14:34:00.000Z',
          },
        ],
      });

      expect(incident.lifecycle, IncidentLifecycle.investigating);
      expect(incident.severity, IncidentSeverity.warning);
      expect(incident.impact, IncidentImpact.down);
      expect(incident.signalSource, SignalSource.anomaly);
      expect(incident.monitorName, 'Checkout service');
      expect(incident.affectedMonitors.single.statusAtStart, StatusKey.down);
      expect(incident.timeline.single.message,
          'Rolling back the latest release now.');
      expect(incident.timeline.single.isPublic, isTrue);
    });

    test('resolves the primary monitor by monitor_id, not by list order', () {
      final IncidentSummary incident = IncidentSummary.fromMap({
        'id': 'multi-affected',
        'title': 'Two components affected',
        'lifecycle': 'investigating',
        'severity': 'critical',
        'impact': 'critical',
        'signal_source': 'user_threshold',
        'primary_monitor_id': 'checkout',
        'started_at': '2026-07-09T14:20:00.000Z',
        'monitors': [
          {
            'monitor_id': 'marketing',
            'name': 'Marketing site',
            'component_status_at_start': 'degraded',
            'component_status_current': 'up',
          },
          {
            'monitor_id': 'checkout',
            'name': 'Checkout service',
            'component_status_at_start': 'down',
            'component_status_current': 'down',
          },
        ],
      });

      // The primary is the SECOND entry: matching on monitor_id (not falling
      // back to the first) is what makes the header name correct.
      expect(incident.monitorName, 'Checkout service');
      expect(incident.affectedCount, 2);
      expect(incident.affectedMonitors.first.name, 'Marketing site');
    });

    test('unknown lifecycle/severity/impact wire values fall back safely', () {
      final IncidentSummary incident = IncidentSummary.fromMap({
        'id': 'unknown-shape',
        'title': 'Unrecognized payload',
        'lifecycle': 'not_a_stage',
        'severity': 'not_a_severity',
        'impact': 'not_an_impact',
        'signal_source': 'not_a_source',
        'started_at': '2026-07-09T14:20:00.000Z',
        'monitors': [],
        'updates': [],
      });

      expect(incident.lifecycle, IncidentLifecycle.detected);
      expect(incident.severity, IncidentSeverity.info);
      expect(incident.impact, IncidentImpact.info);
      expect(incident.signalSource, SignalSource.manual);
    });
  });
}
