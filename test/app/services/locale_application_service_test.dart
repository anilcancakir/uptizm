import 'package:flutter/widgets.dart' show WidgetsBinding;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/models/user.dart';
import 'package:uptizm/app/services/locale_application_service.dart';
import 'package:uptizm/config/localization.dart';

void main() {
  group('framework DateManager boot (replaces the removed timezone hook)', () {
    setUp(() {
      MagicApp.reset();
      DateManager.reset();
      Translator.reset();
    });

    tearDown(() {
      MagicApp.reset();
      DateManager.reset();
      Translator.reset();
    });

    test(
      'LocalizationServiceProvider boots DateManager and applies the '
      'configured timezone without a manual applyDetectedTimezone() call',
      () async {
        // Mirrors the merge magic performs at boot: its own weak default
        // merged first, then uptizm's localizationConfig layered on top (see
        // test/config/localization_config_test.dart for the config-merge
        // regression). LocalizationServiceProvider.boot() logs through the
        // `log` service; in production `LogManager` is bound by `Magic.init`
        // before providers boot (see lib/main.dart), so this test mirrors
        // that binding.
        await MagicApp.init(configs: [localizationConfig]);
        Magic.singleton('log', () => LogManager());
        Magic.register(LocalizationServiceProvider(Magic.app));

        expect(DateManager.instance.isBooted, isFalse);

        await Magic.boot();

        // Plain widget tests have no `flutter_timezone` platform channel, so
        // platform detection resolves to null and the configured
        // `localization.timezone` ('UTC') stays authoritative. This is the
        // behaviour the deleted `applyDetectedTimezone()` used to force by
        // hand; asserting the boot completed and the configured zone landed
        // proves `Magic.init` alone now boots DateManager.
        expect(DateManager.instance.isBooted, isTrue);
        expect(DateManager.instance.timezoneName, isNotEmpty);
        expect(DateManager.instance.timezoneName, 'UTC');
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

    testWidgets(
      'asks the app to rebuild, so widgets already on screen re-translate',
      (tester) async {
        // Loading the catalogue is only half the job. `Translator.setLocale`
        // notifies nothing, and `MagicAppWidget` reads `Lang.current` during
        // build, so a widget that already built keeps its old strings until
        // something requests a frame. On the phone that showed up as a Turkish
        // account logging in to a dashboard translated into Turkish under a
        // bottom tab bar still reading Home / Monitors / Incidents / Status.
        Auth.fake(
          user: User.fromMap({'id': 'u1', 'name': 'Alice', 'locale': 'tr'}),
        );

        // Drain anything the harness itself scheduled, so the assertion below
        // can only be satisfied by this service.
        await tester.pump();
        expect(WidgetsBinding.instance.hasScheduledFrame, isFalse);

        await LocaleApplicationService().syncLocaleWithAuthState();

        expect(Lang.current.languageCode, 'tr');
        expect(WidgetsBinding.instance.hasScheduledFrame, isTrue);
      },
    );

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
