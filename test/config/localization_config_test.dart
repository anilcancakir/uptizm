import 'package:flutter/material.dart' show Locale;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/config/localization.dart';

/// Boot-level regression test for the localization config wiring.
///
/// A config map that is never registered in `main.dart`'s `configFactories`
/// is dead: magic only reads whatever ends up merged into its
/// [ConfigRepository]. This test proves `localizationConfig` is not merely a
/// file that exists, but a config that actually changes the runtime
/// [Translator] state once [LocalizationServiceProvider] boots on top of it.
void main() {
  setUp(() {
    MagicApp.reset();
    Translator.reset();
  });

  tearDown(() {
    MagicApp.reset();
    Translator.reset();
  });

  test(
    'localizationConfig registers tr as a supported locale and enables '
    'auto-detection at runtime',
    () async {
      // Mirrors the merge magic performs at boot: its own weak default
      // (`supported_locales: ['en']`, auto-detect off) merged first, then the
      // consumer config from `configFactories` layered on top.
      await MagicApp.init(configs: [localizationConfig]);
      // LocalizationServiceProvider.boot() logs through the `log` service; in
      // production `LogManager` is bound by `Magic.init` before providers
      // boot (see `lib/main.dart`), so this test mirrors that binding.
      Magic.singleton('log', () => LogManager());
      Magic.register(LocalizationServiceProvider(Magic.app));
      await Magic.boot();

      expect(Lang.supportedLocales, contains(const Locale('tr')));
      expect(Config.get<bool>('localization.auto_detect_locale'), isTrue);
      expect(Config.get<bool>('localization.auto_detect_timezone'), isTrue);
    },
  );
}
