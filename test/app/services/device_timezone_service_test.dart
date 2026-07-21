import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_timezone/flutter_timezone.dart';

import 'package:uptizm/app/services/device_timezone_service.dart';

void main() {
  group('DeviceTimezoneService', () {
    test('returns the detected IANA identifier on success', () async {
      final service = DeviceTimezoneService(
        resolver: () async => TimezoneInfo(identifier: 'Europe/Istanbul'),
      );

      final String? detected = await service.detect();

      expect(detected, 'Europe/Istanbul');
    });

    test('returns null (no throw) when detection fails', () async {
      final service = DeviceTimezoneService(
        resolver: () async => throw StateError('platform channel unavailable'),
      );

      final String? detected = await service.detect();

      expect(detected, isNull);
    });
  });
}
