import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/metric_mapping.dart';
import 'package:uptizm/app/enums/metric_type.dart';

void main() {
  group('MetricMapping', () {
    test('fromMap/toMap roundtrip numeric', () {
      final map = {
        'label': 'DB Connections',
        'path': 'data.database.active_connections',
        'type': 'numeric',
        'unit': 'conn',
      };

      final mapping = MetricMapping.fromMap(map);
      expect(mapping.label, 'DB Connections');
      expect(mapping.path, 'data.database.active_connections');
      expect(mapping.type, MetricType.numeric);
      expect(mapping.unit, 'conn');
      expect(mapping.toMap(), map);
    });

    test('fromMap/toMap roundtrip string type', () {
      final map = {
        'label': 'DB Size',
        'path': 'data.database.size',
        'type': 'string',
      };

      final mapping = MetricMapping.fromMap(map);
      expect(mapping.label, 'DB Size');
      expect(mapping.type, MetricType.string);
      expect(mapping.unit, null);
      expect(mapping.toMap(), map);
    });

    test('fromMap handles missing unit', () {
      final mapping = MetricMapping.fromMap({
        'label': 'Test',
        'path': 'data.test',
        'type': 'numeric',
      });
      expect(mapping.unit, null);
    });

    test('toMap omits null unit', () {
      final mapping = MetricMapping(
        label: 'Test',
        path: 'data.test',
        type: MetricType.numeric,
      );
      expect(mapping.toMap().containsKey('unit'), false);
    });

    test('toDisplayString with unit', () {
      final mapping = MetricMapping(
        label: 'DB Connections',
        path: 'data.database.active_connections',
        type: MetricType.numeric,
        unit: 'conn',
      );
      expect(
        mapping.toDisplayString(),
        'DB Connections: data.database.active_connections (numeric, conn)',
      );
    });

    test('toDisplayString without unit', () {
      final mapping = MetricMapping(
        label: 'DB Size',
        path: 'data.database.size',
        type: MetricType.string,
      );
      expect(mapping.toDisplayString(), 'DB Size: data.database.size (string)');
    });

    test('equality', () {
      final a = MetricMapping(
        label: 'Test',
        path: 'data.test',
        type: MetricType.numeric,
        unit: 'ms',
      );
      final b = MetricMapping(
        label: 'Test',
        path: 'data.test',
        type: MetricType.numeric,
        unit: 'ms',
      );
      expect(a, b);
    });

    test('equality fails on different path', () {
      final a = MetricMapping(
        label: 'Test',
        path: 'data.test',
        type: MetricType.numeric,
      );
      final b = MetricMapping(
        label: 'Test',
        path: 'data.other',
        type: MetricType.numeric,
      );
      expect(a == b, false);
    });
  });
}
