import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/monitor_location.dart';

void main() {
  group('MonitorLocation', () {
    test('has 6 values', () {
      expect(MonitorLocation.values.length, 6);
    });

    test('usEast location', () {
      expect(MonitorLocation.usEast.value, 'us-east');
      expect(MonitorLocation.usEast.label, 'US East');
    });

    test('usWest location', () {
      expect(MonitorLocation.usWest.value, 'us-west');
      expect(MonitorLocation.usWest.label, 'US West');
    });

    test('euWest location', () {
      expect(MonitorLocation.euWest.value, 'eu-west');
      expect(MonitorLocation.euWest.label, 'EU West');
    });

    test('euCentral location', () {
      expect(MonitorLocation.euCentral.value, 'eu-central');
      expect(MonitorLocation.euCentral.label, 'EU Central');
    });

    test('apSoutheast location', () {
      expect(MonitorLocation.apSoutheast.value, 'ap-southeast');
      expect(MonitorLocation.apSoutheast.label, 'AP Southeast');
    });

    test('apNortheast location', () {
      expect(MonitorLocation.apNortheast.value, 'ap-northeast');
      expect(MonitorLocation.apNortheast.label, 'AP Northeast');
    });

    test('fromValue returns correct location', () {
      expect(MonitorLocation.fromValue('us-east'), MonitorLocation.usEast);
      expect(MonitorLocation.fromValue('eu-west'), MonitorLocation.euWest);
      expect(
        MonitorLocation.fromValue('ap-southeast'),
        MonitorLocation.apSoutheast,
      );
    });

    test('fromValue returns null for null', () {
      expect(MonitorLocation.fromValue(null), null);
    });

    test('fromValue returns usEast for unknown value', () {
      expect(MonitorLocation.fromValue('unknown'), MonitorLocation.usEast);
    });

    test('selectOptions returns 6 options', () {
      final options = MonitorLocation.selectOptions;
      expect(options.length, 6);
    });

    test('fromValueList converts list of strings', () {
      final locations = MonitorLocation.fromValueList(['us-east', 'eu-west']);
      expect(locations.length, 2);
      expect(locations[0], MonitorLocation.usEast);
      expect(locations[1], MonitorLocation.euWest);
    });

    test('fromValueList returns empty for null', () {
      expect(MonitorLocation.fromValueList(null), isEmpty);
    });

    test('toValueList converts to string list', () {
      final values = MonitorLocation.toValueList([
        MonitorLocation.usEast,
        MonitorLocation.euCentral,
      ]);
      expect(values, ['us-east', 'eu-central']);
    });
  });
}
