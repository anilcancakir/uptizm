import 'dart:ui' show Locale;

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/support/formatters.dart' show formatCount;
import 'package:uptizm/app/support/team_types.dart' show UsageStat;

import '../../support/bundled_lang.dart';

/// Locks the billing usage row against the language it is read in.
///
/// The page rendered "Monitors", "Responders", "Checks this month", "checks" and
/// `83,365` inside an otherwise fully Turkish page: three labels and a unit were
/// hardcoded English literals in `UsageStat.fromWireMap`, and the thousands
/// separator was a hardcoded comma in two byte-identical private copies of the
/// same formatter.
///
/// Both assertions read the SHIPPED catalogue rather than an inline map, so they
/// agree with the product rather than with the test author.
void main() {
  Future<void> useLocale(String locale) async {
    Translator.instance.setLoader(_BundledLoader(locale));
    await Translator.instance.setLocale(Locale(locale));
  }

  const Map<String, dynamic> wire = {
    'monitors': {'used': 2, 'limit': 1},
    'responders': {'used': 1, 'limit': 1},
    'checks_this_month': {'used': 83365, 'limit': null},
  };

  /// The field names `BillingController::usage()` sends, in the decoder's order.
  const List<String> wireKeys = ['monitors', 'responders', 'checks_this_month'];

  group('in Turkish', () {
    setUp(() => useLocale('tr'));

    test('the usage labels and unit come from the catalogue', () {
      final List<UsageStat> stats = UsageStat.fromWireMap(wire);

      expect(stats.map((s) => s.label), [
        'İzleyiciler',
        'Yanıtlayıcılar',
        'Bu ayki kontroller',
      ]);
      expect(stats.last.unit, 'kontrol');
    });

    test('the keys stay the untranslated wire keys', () {
      // The other half of the same defect: the labels above are correct BECAUSE
      // they are translated, so nothing may key logic on them. `key` is what a
      // gate looks a resource up by, and it must not move with the language.
      expect(UsageStat.fromWireMap(wire).map((s) => s.key), wireKeys);
    });

    test('the thousands separator is a period', () {
      expect(formatCount(83365), '83.365');
      expect(formatCount(1000), '1.000');
      expect(formatCount(999), '999');
    });
  });

  group('in English', () {
    setUp(() => useLocale('en'));

    test('the usage labels and unit come from the catalogue', () {
      final List<UsageStat> stats = UsageStat.fromWireMap(wire);

      expect(stats.map((s) => s.label), [
        'Monitors',
        'Responders',
        'Checks this month',
      ]);
      expect(stats.last.unit, 'checks');
    });

    test('the keys stay the untranslated wire keys', () {
      // Asserted in both locales deliberately: one locale cannot show that the
      // keys are language-independent, and English is the locale where a
      // label-keyed lookup passes by accident.
      expect(UsageStat.fromWireMap(wire).map((s) => s.key), wireKeys);
    });

    test('the thousands separator is a comma', () {
      expect(formatCount(83365), '83,365');
      expect(formatCount(-1234567), '-1,234,567');
    });
  });
}

/// Serves the shipped catalogue for one locale, so every string asserted above
/// is the one a user reads rather than a key.
class _BundledLoader implements TranslationLoader {
  _BundledLoader(this.locale);

  final String locale;

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}
