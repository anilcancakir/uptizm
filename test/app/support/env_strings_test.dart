import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/support/env_strings.dart';

void main() {
  /// Seeds the environment with [values] through the seam `Env.load` documents
  /// for tests, pointing at a file that does not exist so the loader takes its
  /// fallback path and keeps only what is passed here.
  Future<void> seedEnv(Map<String, String> values) async {
    Env.reset();
    await Env.load(fileName: '.env.does-not-exist', mergeWith: values);
  }

  setUp(() {
    MagicApp.reset();
    Magic.flush();
    Env.reset();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
    Env.reset();
  });

  group('envClean', () {
    test('strips a wrapping quote pair and surrounding whitespace', () {
      // What the `.env` parser hands back for `KEY="value"` is the text after
      // the `=`, quotes included.
      expect(envClean('"Uptizm"'), 'Uptizm');
      expect(envClean("'ws'"), 'ws');
      expect(envClean('  https://uptizm.com  '), 'https://uptizm.com');
    });

    test('keeps an apostrophe inside the value', () {
      // Stripping every quote instead of the wrapping pair rewrote a legitimate
      // name: `APP_NAME="Anıl's Monitor"` became `Anıls Monitor`.
      expect(envClean('"Anıl\'s Monitor"'), "Anıl's Monitor");
      expect(envClean("Anıl's Monitor"), "Anıl's Monitor");
    });

    test('leaves an unbalanced quote alone', () {
      // A malformed line stays visibly malformed rather than half-repaired.
      expect(envClean('"Uptizm'), '"Uptizm');
      expect(envClean('Uptizm"'), 'Uptizm"');
    });

    test('a quote-only value cleans to empty, which is the whole point', () {
      // `APP_NAME=""` reached the app as the literal two-character string `""`
      // and put `Monitor | ""` in the browser tab.
      expect(envClean('""'), isEmpty);
      expect(envClean('   '), isEmpty);
      expect(envClean(null), isEmpty);
    });
  });

  group('envString', () {
    test('a missing key falls back', () {
      expect(envString('UPTIZM_NOT_SET_ANYWHERE', 'fallback'), 'fallback');
    });

    test('a present but blank key falls back, where env() would not', () async {
      // The defect this closes: `env('KEY', fallback)` only fires its default
      // when the key is ABSENT, so a deployed `KEY=` resolves to '' and the
      // fallback never runs.
      await seedEnv({'UPTIZM_BLANK': ''});
      expect(env<String?>('UPTIZM_BLANK'), isEmpty);
      expect(envString('UPTIZM_BLANK', 'fallback'), 'fallback');
    });

    test('a quoted-empty key falls back too', () async {
      await seedEnv({'UPTIZM_QUOTED_BLANK': '""'});
      expect(envString('UPTIZM_QUOTED_BLANK', 'fallback'), 'fallback');
    });

    test('a real value wins, cleaned', () async {
      await seedEnv({'UPTIZM_REAL': '"https://uptizm.com"'});
      expect(envString('UPTIZM_REAL', 'fallback'), 'https://uptizm.com');
    });

    test('a blank fallback stays blank rather than becoming a quote string', () {
      // REVERB_APP_KEY legitimately defaults to empty.
      expect(envString('UPTIZM_NOT_SET_ANYWHERE', ''), isEmpty);
    });
  });
}
