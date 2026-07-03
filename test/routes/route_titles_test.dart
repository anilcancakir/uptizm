import 'dart:convert';
import 'dart:io';

import 'package:flutter/widgets.dart' show Locale;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

/// In-memory loader feeding the `uptizm.titles.*` family so [TitleManager]
/// resolves real English labels instead of falling back to the raw key.
///
/// Mirrors the pattern in `test/resources/views/dashboard_view_test.dart`:
/// the `Translator` caches whatever a loader returns verbatim, so this loader
/// pre-flattens the subset of `assets/lang/en.json` that route titles read.
class _TitlesLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.titles.dashboard': 'Dashboard',
      'uptizm.titles.billing': 'Plan & billing',
      'uptizm.titles.monitor': 'Monitor',
    };
  }
}

void main() {
  setUp(() {
    TitleManager.reset();
    Magic.flush();
  });

  tearDown(() {
    TitleManager.reset();
    Magic.flush();
  });

  group('route title keys', () {
    test('every uptizm.titles.* key referenced in app.dart exists in en.json', () {
      // 1. Extract every `uptizm.titles.<key>` literal referenced by
      //    `.title(...)` calls in the route table.
      final appDartFile = File('lib/routes/app.dart');
      final appDartSource = appDartFile.readAsStringSync();
      final titleKeyPattern = RegExp(r"uptizm\.titles\.([a-zA-Z0-9_]+)");
      final referencedKeys = titleKeyPattern
          .allMatches(appDartSource)
          .map((match) => match.group(1)!)
          .toSet();

      // Guard against a rotted test: app.dart must still reference titles.
      expect(referencedKeys, isNotEmpty);

      // 2. Load the shipped English translations and read the `uptizm.titles`
      //    namespace out of the raw JSON tree (no Translator involved here,
      //    this check is purely about asset content).
      final enJsonFile = File('assets/lang/en.json');
      final enJson = jsonDecode(enJsonFile.readAsStringSync()) as Map<String, dynamic>;
      final uptizmSection = enJson['uptizm'] as Map<String, dynamic>;
      final titlesSection = uptizmSection['titles'] as Map<String, dynamic>;
      final definedKeys = titlesSection.keys.toSet();

      // 3. Every key the routes reference must exist in en.json, otherwise a
      //    route would render its raw translation key as a page title.
      final missingKeys = referencedKeys.difference(definedKeys);
      expect(
        missingKeys,
        isEmpty,
        reason: 'Route titles reference keys missing from en.json: $missingKeys',
      );
    });
  });

  group('TitleManager composition with the en loader', () {
    late String? capturedTitle;

    setUp(() async {
      capturedTitle = null;
      TitleManager.configure(
        onTitleChanged: (String title, int? primaryColor) => capturedTitle = title,
      );

      Translator.instance.setLoader(_TitlesLangLoader());
      await Translator.instance.setLocale(const Locale('en'));
    });

    test('dashboard route composes "Dashboard | Uptizm"', () {
      TitleManager.instance
        ..setSuffix('Uptizm')
        ..setSeparator(' | ')
        ..setRouteTitle('uptizm.titles.dashboard');

      expect(capturedTitle, 'Dashboard | Uptizm');
      expect(TitleManager.instance.effectiveTitle, 'Dashboard | Uptizm');
    });

    test('billing route composes "Plan & billing | Uptizm"', () {
      TitleManager.instance
        ..setSuffix('Uptizm')
        ..setSeparator(' | ')
        ..setRouteTitle('uptizm.titles.billing');

      expect(capturedTitle, 'Plan & billing | Uptizm');
      expect(TitleManager.instance.effectiveTitle, 'Plan & billing | Uptizm');
    });

    test('monitor route composes "Monitor | Uptizm"', () {
      TitleManager.instance
        ..setSuffix('Uptizm')
        ..setSeparator(' | ')
        ..setRouteTitle('uptizm.titles.monitor');

      expect(capturedTitle, 'Monitor | Uptizm');
      expect(TitleManager.instance.effectiveTitle, 'Monitor | Uptizm');
    });
  });
}
