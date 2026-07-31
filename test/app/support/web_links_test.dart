import 'dart:ui' show Locale;

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/support/web_links.dart';

/// Stub translation loader.
///
/// [WebLinks] never reads a translated string, only the ACTIVE locale, so an
/// empty sentence map is everything the [Translator] needs to switch language
/// here (a real asset loader would need a bundled `assets/lang/*.json`).
class _EmptyLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => const {};
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    Translator.instance.setLoader(_EmptyLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() async {
    await Translator.instance.setLocale(const Locale('en'));
    MagicApp.reset();
    Magic.flush();
  });

  /// Writes the three config values [WebLinks] derives every URL from.
  void configure({
    String webUrl = 'https://uptizm.com',
    String defaultLocale = 'en',
    List<String> supportedLocales = const ['en', 'tr'],
  }) {
    Config.set('app.web_url', webUrl);
    Config.set('localization.locale', defaultLocale);
    Config.set('localization.supported_locales', supportedLocales);
  }

  // ---------------------------------------------------------------------------
  // Locale prefixing
  // ---------------------------------------------------------------------------

  group('locale prefixing', () {
    test('the default locale takes no path prefix', () {
      configure();

      expect(WebLinks.terms, 'https://uptizm.com/terms');
      expect(WebLinks.privacy, 'https://uptizm.com/privacy');
      expect(WebLinks.contact, 'https://uptizm.com/contact');
    });

    test('a non-default supported locale is prefixed with its code', () async {
      configure();
      await Translator.instance.setLocale(const Locale('tr'));

      expect(WebLinks.terms, 'https://uptizm.com/tr/terms');
      expect(WebLinks.privacy, 'https://uptizm.com/tr/privacy');
      expect(WebLinks.contact, 'https://uptizm.com/tr/contact');
    });

    test('the prefix follows the configured default, not a hardcoded one', () async {
      configure(defaultLocale: 'tr');
      await Translator.instance.setLocale(const Locale('tr'));

      // With `tr` as the default language the Turkish document lives on the
      // bare path and English is the prefixed one, mirroring the website's own
      // rule rather than assuming English is always the default.
      expect(WebLinks.terms, 'https://uptizm.com/terms');

      await Translator.instance.setLocale(const Locale('en'));
      expect(WebLinks.terms, 'https://uptizm.com/en/terms');
    });

    test('an unsupported active locale degrades to the bare path', () async {
      configure();
      await Translator.instance.setLocale(const Locale('de'));

      // The website answers an unlisted language with a 404 by design, so the
      // client must not compose one.
      expect(WebLinks.terms, 'https://uptizm.com/terms');
    });

    test('an empty supported list degrades to the bare path', () async {
      configure(supportedLocales: const []);
      await Translator.instance.setLocale(const Locale('tr'));

      expect(WebLinks.terms, 'https://uptizm.com/terms');
    });
  });

  // ---------------------------------------------------------------------------
  // Origin resolution
  // ---------------------------------------------------------------------------

  group('origin resolution', () {
    test('a trailing slash on the configured origin is dropped', () {
      configure(webUrl: 'https://uptizm.com/');

      expect(WebLinks.terms, 'https://uptizm.com/terms');
    });

    test('a quoted or padded origin value is cleaned', () {
      configure(webUrl: '  "https://uptizm.com"  ');

      expect(WebLinks.terms, 'https://uptizm.com/terms');
    });

    test('a blank origin falls back to the packaged default', () {
      configure(webUrl: '');

      expect(WebLinks.terms, '$kDefaultWebUrl/terms');
    });

    test('an unconfigured origin falls back to the packaged default', () {
      // No `configure()` call: this is the state a config FACTORY reads in,
      // since `Magic.init` evaluates the factories before merging them into
      // the repository.
      expect(WebLinks.terms, '$kDefaultWebUrl/terms');
    });
  });

  // ---------------------------------------------------------------------------
  // magic_starter legal block
  // ---------------------------------------------------------------------------

  group('legalConfig', () {
    test('carries the two keys magic_starter reads, in the active locale', () async {
      configure();
      await Translator.instance.setLocale(const Locale('tr'));

      expect(WebLinks.legalConfig, {
        'terms_url': 'https://uptizm.com/tr/terms',
        'privacy_url': 'https://uptizm.com/tr/privacy',
      });
    });
  });
}
