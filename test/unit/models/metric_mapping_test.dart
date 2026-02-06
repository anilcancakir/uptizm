import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/metric_mapping.dart';
import 'package:uptizm/app/enums/metric_type.dart';

void main() {
  group('MetricMapping', () {
    test('fromMap parses all basic fields', () {
      final map = {
        'label': 'Response Time',
        'path': 'data.response_time',
        'type': 'numeric',
        'unit': 'ms',
      };

      final mapping = MetricMapping.fromMap(map);

      expect(mapping.label, 'Response Time');
      expect(mapping.path, 'data.response_time');
      expect(mapping.type, MetricType.numeric);
      expect(mapping.unit, 'ms');
    });

    test('fromMap parses upWhen field for status type', () {
      final map = {
        'label': 'Service Health',
        'path': 'data.is_healthy',
        'type': 'status',
        'up_when': 'truthy',
      };

      final mapping = MetricMapping.fromMap(map);

      expect(mapping.label, 'Service Health');
      expect(mapping.path, 'data.is_healthy');
      expect(mapping.type, MetricType.status);
      expect(mapping.upWhen, 'truthy');
    });

    test('fromMap handles missing upWhen', () {
      final map = {'label': 'CPU Usage', 'path': 'data.cpu', 'type': 'numeric'};

      final mapping = MetricMapping.fromMap(map);

      expect(mapping.upWhen, isNull);
    });

    test('toMap includes upWhen when type is status', () {
      const mapping = MetricMapping(
        label: 'Database Connection',
        path: 'data.db_connected',
        type: MetricType.status,
        upWhen: 'not_null',
      );

      final map = mapping.toMap();

      expect(map['label'], 'Database Connection');
      expect(map['path'], 'data.db_connected');
      expect(map['type'], 'status');
      expect(map['up_when'], 'not_null');
    });

    test('toMap excludes upWhen when type is not status', () {
      const mapping = MetricMapping(
        label: 'Response Time',
        path: 'data.response_time',
        type: MetricType.numeric,
        unit: 'ms',
      );

      final map = mapping.toMap();

      expect(map.containsKey('up_when'), isFalse);
    });

    test('toMap excludes upWhen when it is null', () {
      const mapping = MetricMapping(
        label: 'Status Check',
        path: 'data.status',
        type: MetricType.status,
      );

      final map = mapping.toMap();

      expect(map.containsKey('up_when'), isFalse);
    });

    test('equality includes upWhen', () {
      const mapping1 = MetricMapping(
        label: 'Health',
        path: 'data.health',
        type: MetricType.status,
        upWhen: 'truthy',
      );

      const mapping2 = MetricMapping(
        label: 'Health',
        path: 'data.health',
        type: MetricType.status,
        upWhen: 'truthy',
      );

      const mapping3 = MetricMapping(
        label: 'Health',
        path: 'data.health',
        type: MetricType.status,
        upWhen: 'falsy',
      );

      expect(mapping1, equals(mapping2));
      expect(mapping1, isNot(equals(mapping3)));
    });
  });
}
