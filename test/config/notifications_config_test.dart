import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/config/notifications.dart';

/// Boot-level regression test for the `notifications` config wiring.
///
/// Mirrors `test/config/localization_config_test.dart`'s shape: a config map
/// that is never registered in `main.dart`'s `configFactories` is dead, so
/// this proves `notificationsConfig` is reachable through `Magic.init`
/// rather than merely a file that exists.
///
/// The two `Magic.init` cases below pass the factory themselves, which is what
/// lets them exercise the map's env resolution in isolation, but it also means
/// neither can see whether `main.dart` registers it: they would both stay green
/// with the registration deleted. The registration therefore gets its own case,
/// asserted against the source the way `app_service_provider_test.dart` asserts
/// its own boot seams. That is not belt-and-braces: with the factory missing,
/// `notifications.push.app_id` is absent, `NotificationServiceProvider` returns
/// early at its `appId == null || appId.isEmpty` guard, and the app ships with
/// no push driver initialised on any platform, silently.
void main() {
  /// Seeds the environment with [values] through the seam `Env.load`
  /// documents for tests, pointing at a file that does not exist so the
  /// loader takes its fallback path and keeps only what is passed here.
  Future<void> seedEnv(Map<String, String> values) async {
    Env.reset();
    await Env.load(fileName: '.env.does-not-exist', mergeWith: values);
  }

  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
    Env.reset();
  });

  test(
    'notificationsConfig registers the onesignal driver by default',
    () async {
      await seedEnv({});

      await Magic.init(configFactories: [() => notificationsConfig]);

      expect(
        Config.get<String>('notifications.push.driver'),
        'onesignal',
      );
    },
  );

  test(
    'a quote-only ONESIGNAL_APP_ID resolves to the empty fallback, not the '
    'literal two-quote-character string a raw env() read would leave',
    () async {
      // Reproduces this repo's own incident shape (`APP_NAME=""`, see
      // env_strings.dart): a deployed value that is present but blank still
      // has to resolve to the fallback rather than to `env()`'s raw text.
      await seedEnv({'ONESIGNAL_APP_ID': '""'});

      await Magic.init(configFactories: [() => notificationsConfig]);

      final String? appId = Config.get<String>('notifications.push.app_id');

      expect(appId, isNotNull);
      expect(appId, isEmpty);
      expect(appId, isNot('""'));
    },
  );

  test('main.dart registers notificationsConfig in its configFactories', () {
    // A source read rather than a boot, because booting the real app is what
    // this suite cannot do: `main()` calls `runApp`. The assertion is still
    // able to fail for the reason it claims, which is the whole point of the
    // case: deleting the line from `main.dart` turns it red, and nothing else
    // in the suite would notice.
    final String source = File('lib/main.dart').readAsStringSync();

    expect(source, contains('() => notificationsConfig,'));
  });
}
