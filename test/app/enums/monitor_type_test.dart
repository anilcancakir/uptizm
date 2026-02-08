import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/monitor_type.dart';

void main() {
  group('MonitorType', () {
    test('has http type', () {
      expect(MonitorType.http.value, 'http');
      expect(MonitorType.http.label, 'HTTP');
    });

    test('has ping type', () {
      expect(MonitorType.ping.value, 'ping');
      expect(MonitorType.ping.label, 'Ping');
    });

    test('has port type', () {
      expect(MonitorType.port.value, 'port');
      expect(MonitorType.port.label, 'Port');
    });

    test('fromValue returns correct type for port', () {
      expect(MonitorType.fromValue('port'), MonitorType.port);
    });

    test('fromValue returns correct type for all values', () {
      expect(MonitorType.fromValue('http'), MonitorType.http);
      expect(MonitorType.fromValue('ping'), MonitorType.ping);
      expect(MonitorType.fromValue('port'), MonitorType.port);
    });

    test('fromValue returns null for null', () {
      expect(MonitorType.fromValue(null), null);
    });

    test('fromValue returns null for unknown value', () {
      expect(MonitorType.fromValue('unknown'), isNull);
    });

    test('selectOptions includes all types', () {
      final options = MonitorType.selectOptions;
      expect(options.length, 3);
      expect(options.any((opt) => opt.value == MonitorType.http), true);
      expect(options.any((opt) => opt.value == MonitorType.ping), true);
      expect(options.any((opt) => opt.value == MonitorType.port), true);
    });
  });
}
