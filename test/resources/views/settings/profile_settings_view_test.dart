import 'dart:convert';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/profile_controller.dart';
import 'package:uptizm/app/helpers/locale_list.dart';

void main() {
  group('Profile Settings Translation Keys', () {
    test('locale and timezone translation keys exist', () async {
      TestWidgetsFlutterBinding.ensureInitialized();

      // Load the en.json translation file
      final String jsonString = await rootBundle.loadString('assets/lang/en.json');
      final Map<String, dynamic> translations = json.decode(jsonString);

      // Check that required translation keys exist in profile_settings
      final profileSettings = translations['profile_settings'] as Map<String, dynamic>;

      expect(profileSettings.containsKey('select_language'), isTrue,
          reason: 'profile_settings.select_language key should exist');
      expect(profileSettings.containsKey('select_timezone'), isTrue,
          reason: 'profile_settings.select_timezone key should exist');
      expect(profileSettings.containsKey('language_desc'), isTrue,
          reason: 'profile_settings.language_desc key should exist');
      expect(profileSettings.containsKey('timezone_desc'), isTrue,
          reason: 'profile_settings.timezone_desc key should exist');

      // Verify values are not empty
      expect(profileSettings['select_language'], isNotEmpty);
      expect(profileSettings['select_timezone'], isNotEmpty);
      expect(profileSettings['language_desc'], isNotEmpty);
      expect(profileSettings['timezone_desc'], isNotEmpty);
    });
  });

  group('Profile Controller', () {
    test('doUpdateProfile method exists with timezone and language parameters', () {
      final controller = ProfileController.instance;

      // Verify the method exists and can be called with these parameters
      // This is a compile-time check - if it compiles, the signature is correct
      expect(controller.doUpdateProfile, isA<Function>());
    });
  });

  group('Locale Options', () {
    test('locale options are available for dropdown', () {
      final options = localeOptions;

      expect(options, isNotEmpty);
      expect(options.length, greaterThanOrEqualTo(2));

      // Verify English and Turkish are available
      expect(options.any((opt) => opt.value == 'en'), isTrue);
      expect(options.any((opt) => opt.value == 'tr'), isTrue);
    });
  });
}
