import 'dart:ui' show Locale;

import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/support/formatters.dart'
    show formatDecimal, upperCase;

import '../../support/bundled_lang.dart';

/// Locks the two locale-dependent renderings a QA walk of the running app
/// caught, both of which read as noise to a Turkish operator.
///
/// The dashboard's KPI headings read `ÇALIŞAN IZLEYICILER` and `ÇALIŞMA SÜRESI`,
/// and its uptime read `11.77%`. Both had the same root cause in different
/// clothing: a Dart primitive that ignores the locale. `String.toUpperCase()`
/// maps `i` to `I` where Turkish needs `İ`, and `toStringAsFixed` writes a full
/// stop where Turkish writes a comma, which is the mark Turkish uses to group
/// THOUSANDS. `%99.9` is not a near-perfect SLO target in Turkish; it is a
/// number that means nothing.
///
/// Both assertions read the SHIPPED catalogue for the separator rather than an
/// inline map, matching `billing_usage_i18n_test.dart`, so they agree with the
/// product rather than with the test author.
void main() {
  Future<void> useLocale(String locale) async {
    Translator.instance.setLoader(_BundledLoader(locale));
    await Translator.instance.setLocale(Locale(locale));
  }

  group('in Turkish', () {
    setUp(() => useLocale('tr'));

    test('uppercasing keeps the dot on i and takes it off ı', () {
      // The four headings the walk actually caught, verbatim from the
      // catalogue's own wording.
      expect(upperCase('Çalışan izleyiciler'), 'ÇALIŞAN İZLEYİCİLER');
      expect(upperCase('Çalışma süresi (24s)'), 'ÇALIŞMA SÜRESİ (24S)');
      expect(upperCase('Bileşenler'), 'BİLEŞENLER');
      expect(upperCase('Abonelikler'), 'ABONELİKLER');
    });

    test('a dotless ı still uppercases to a dotless I', () {
      // The other half of the pair, and the reason this cannot be a blanket
      // `replaceAll('I', 'İ')`: `kullanılan` is correct as `KULLANILAN`.
      expect(upperCase('Kullanılan izleyiciler'), 'KULLANILAN İZLEYİCİLER');
      expect(upperCase('Açık olaylar'), 'AÇIK OLAYLAR');
    });

    test('the decimal mark is a comma', () {
      expect(formatDecimal(11.77), '11,77');
      expect(formatDecimal(99.9, places: 1), '99,9');
      expect(formatDecimal(-0.5), '-0,50');
    });

    test('a large decimal gets BOTH marks right, not just one', () {
      // Turkish groups thousands with the mark English uses for decimals, so a
      // formatter that fixed only one of the two produces `1.234,00` or
      // `1,234,56`, and both read as a different number.
      expect(formatDecimal(1234.5), '1.234,50');
    });

    test('places: 0 drops the mark rather than trailing it', () {
      expect(formatDecimal(99.0, places: 0), '99');
    });
  });

  group('in English', () {
    setUp(() => useLocale('en'));

    test('casing and the decimal mark are unchanged', () {
      expect(upperCase('Monitors up'), 'MONITORS UP');
      expect(formatDecimal(11.77), '11.77');
      expect(formatDecimal(1234.5), '1,234.50');
    });
  });
}

/// Serves the shipped catalogue for one locale, so every separator asserted
/// above is the one a user reads rather than a key.
class _BundledLoader implements TranslationLoader {
  _BundledLoader(this.locale);

  final String locale;

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}
