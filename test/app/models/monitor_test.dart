import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/monitor_auth_type.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/monitor_auth_config.dart';

void main() {
  group('Monitor', () {
    test('fillable includes auth_config', () {
      final monitor = Monitor();
      expect(monitor.fillable.contains('auth_config'), true);
    });

    test('authConfig getter returns MonitorAuthConfig from map', () {
      final monitor = Monitor();
      monitor.setRawAttributes({
        'auth_config': {
          'type': 'basic_auth',
          'basic_auth': {'username': 'admin', 'password': 'secret'},
        },
      }, sync: true);

      final config = monitor.authConfig;
      expect(config.type, MonitorAuthType.basicAuth);
      expect(config.basicAuthUsername, 'admin');
      expect(config.basicAuthPassword, 'secret');
    });

    test('authConfig getter returns none config when field is null', () {
      final monitor = Monitor();
      monitor.setRawAttributes({}, sync: true);

      final config = monitor.authConfig;
      expect(config.type, MonitorAuthType.none);
    });

    test('authConfig setter stores map correctly', () {
      final monitor = Monitor();
      monitor.authConfig = MonitorAuthConfig(
        type: MonitorAuthType.bearerToken,
        bearerToken: 'my-token',
      );

      final raw = monitor.getAttribute('auth_config') as Map<String, dynamic>;
      expect(raw['type'], 'bearer_token');
      expect(raw['bearer_token']['token'], 'my-token');
    });

    test('fillable includes metric_mappings', () {
      final monitor = Monitor();
      expect(monitor.fillable.contains('metric_mappings'), true);
    });

    test('metricMappings getter returns list from raw attributes', () {
      final monitor = Monitor();
      monitor.setRawAttributes({
        'metric_mappings': [
          {'label': 'DB Size', 'path': 'data.database.size', 'type': 'string'},
        ],
      }, sync: true);

      final mappings = monitor.metricMappings;
      expect(mappings, isNotNull);
      expect(mappings!.length, 1);
      expect(mappings[0]['label'], 'DB Size');
    });

    test('metricMappings getter returns null when field is null', () {
      final monitor = Monitor();
      monitor.setRawAttributes({}, sync: true);

      expect(monitor.metricMappings, null);
    });

    test('metricMappings setter stores list correctly', () {
      final monitor = Monitor();
      monitor.metricMappings = [
        {'label': 'Test', 'path': 'data.test', 'type': 'numeric', 'unit': 'ms'},
      ];

      final raw = monitor.getAttribute('metric_mappings') as List;
      expect(raw.length, 1);
      expect((raw[0] as Map)['label'], 'Test');
    });

    test('fillable includes incident_threshold', () {
      final monitor = Monitor();
      expect(monitor.fillable.contains('incident_threshold'), true);
    });

    test('incidentThreshold getter returns int from raw attributes', () {
      final monitor = Monitor();
      monitor.setRawAttributes({'incident_threshold': 3}, sync: true);

      expect(monitor.incidentThreshold, 3);
    });

    test('incidentThreshold getter handles num type from API', () {
      final monitor = Monitor();
      monitor.setRawAttributes({'incident_threshold': 5.0}, sync: true);

      expect(monitor.incidentThreshold, 5);
    });

    test('incidentThreshold getter returns null when field is null', () {
      final monitor = Monitor();
      monitor.setRawAttributes({}, sync: true);

      expect(monitor.incidentThreshold, isNull);
    });

    test('incidentThreshold setter stores value correctly', () {
      final monitor = Monitor();
      monitor.incidentThreshold = 7;

      expect(monitor.getAttribute('incident_threshold'), 7);
    });

    test('incidentThreshold setter stores null correctly', () {
      final monitor = Monitor();
      monitor.incidentThreshold = null;

      expect(monitor.getAttribute('incident_threshold'), isNull);
    });
  });
}
