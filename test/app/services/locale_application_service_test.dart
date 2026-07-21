import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_timezone/flutter_timezone.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/models/user.dart';
import 'package:uptizm/app/services/device_timezone_service.dart';
import 'package:uptizm/app/services/locale_application_service.dart';
import 'package:uptizm/config/localization.dart';

void main() {
  group('LocaleApplicationService.applyDetectedTimezone', () {
    setUp(() {
      MagicApp.reset();
      DateManager.reset();
    });

    tearDown(() {
      MagicApp.reset();
      DateManager.reset();
    });

    test(
      'boots DateManager and overrides its timezone with the detected IANA id',
      () async {
        await MagicApp.init(configs: [localizationConfig]);
        final service = LocaleApplicationService(
          timezoneService: DeviceTimezoneService(
            resolver: () async => TimezoneInfo(identifier: 'Europe/Istanbul'),
          ),
        );

        await service.applyDetectedTimezone();

        expect(DateManager.instance.timezoneName, 'Europe/Istanbul');
      },
    );

    test(
      'leaves DateManager on its own booted default when detection fails',
      () async {
        await MagicApp.init(configs: [localizationConfig]);
        final service = LocaleApplicationService(
          timezoneService: DeviceTimezoneService(
            resolver: () async =>
                throw StateError('platform channel unavailable'),
          ),
        );

        await service.applyDetectedTimezone();

        expect(DateManager.instance.isBooted, isTrue);
        expect(DateManager.instance.timezoneName, isNotEmpty);
      },
    );
  });

  group('LocaleApplicationService.syncLocaleWithAuthState', () {
    setUp(() {
      MagicApp.reset();
      Translator.reset();
    });

    tearDown(() {
      Auth.unfake();
      MagicApp.reset();
      Translator.reset();
    });

    test("applies the authenticated user's persisted locale to Lang", () async {
      Auth.fake(
        user: User.fromMap({'id': 'u1', 'name': 'Alice', 'locale': 'tr'}),
      );
      final service = LocaleApplicationService();

      await service.syncLocaleWithAuthState();

      expect(Lang.current.languageCode, 'tr');
    });

    test('does nothing when logged out', () async {
      Auth.fake();
      final service = LocaleApplicationService();

      await service.syncLocaleWithAuthState();

      expect(Lang.current.languageCode, 'en');
    });

    test(
      'does nothing when the authenticated user carries no locale preference',
      () async {
        Auth.fake(user: User.fromMap({'id': 'u1', 'name': 'Alice'}));
        final service = LocaleApplicationService();

        await service.syncLocaleWithAuthState();

        expect(Lang.current.languageCode, 'en');
      },
    );
  });
}
