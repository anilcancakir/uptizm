import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/helpers/locale_list.dart';

void main() {
  group('Locale List', () {
    test('locale list contains english and turkish', () {
      final locales = localeOptions;

      expect(
        locales.any((loc) => loc.value == 'en'),
        isTrue,
        reason: 'Should contain English locale',
      );
      expect(
        locales.any((loc) => loc.value == 'tr'),
        isTrue,
        reason: 'Should contain Turkish locale',
      );
    });

    test('each locale has value and label', () {
      final locales = localeOptions;

      expect(locales, isNotEmpty, reason: 'Locale list should not be empty');

      for (final locale in locales) {
        expect(locale.value, isNotEmpty, reason: 'Locale value should not be empty');
        expect(locale.label, isNotEmpty, reason: 'Locale label should not be empty');
      }
    });
  });
}
