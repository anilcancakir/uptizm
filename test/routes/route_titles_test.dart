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

/// The `magic_starter.titles.*` keys the RESOLVED magic_starter ships.
///
/// Located through `.dart_tool/package_config.json` rather than a hardcoded
/// `../magic_starter`, so it follows whatever the solver actually picked: the
/// sibling working tree locally, the cloned default branch in CI, or a hosted
/// version once the package is published.
Set<String> _starterTitleKeys() {
  final config =
      jsonDecode(File('.dart_tool/package_config.json').readAsStringSync())
          as Map<String, dynamic>;

  final package = (config['packages'] as List<dynamic>)
      .cast<Map<String, dynamic>>()
      .firstWhere(
        (entry) => entry['name'] == 'magic_starter',
        orElse: () => throw StateError(
          'magic_starter is not in the resolved package graph. Run '
          '`flutter pub get` before this test.',
        ),
      );

  // `rootUri` is relative to `.dart_tool/` (`../../magic_starter` for a sibling
  // checkout) and absolute for a hosted package. The TRAILING SLASH is
  // load-bearing: without it `resolve()` treats the last segment as a file name
  // and replaces it, so `assets/...` lands one directory too high. The first
  // draft of this dropped it and the test reported "magic_starter ships no
  // titles" about a package that shipped twenty.
  final raw = package['rootUri'] as String;
  final root = Uri.parse(raw.endsWith('/') ? raw : '$raw/');
  final resolved = root.hasScheme
      ? root
      : Directory('.dart_tool').uri.resolveUri(root);

  final stub = File.fromUri(resolved.resolve('assets/stubs/install/en.stub'));

  // Not a graceful degrade: a stub we cannot read is a broken lookup, and
  // returning an empty set here would report it as a magic_starter that
  // predates the titles, which is a different problem with a different fix.
  if (!stub.existsSync()) {
    throw StateError(
      'magic_starter resolved to ${resolved.toFilePath()} but its install stub '
      'is not there. The package layout moved, or this lookup is wrong.',
    );
  }

  final decoded = jsonDecode(stub.readAsStringSync()) as Map<String, dynamic>;
  final titles =
      (decoded['magic_starter'] as Map<String, dynamic>?)?['titles']
          as Map<String, dynamic>?;

  return titles?.keys.toSet() ?? const {};
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

    test('every magic_starter title key it ships exists in en.json', () {
      // The account surface (login, register, the Settings hub and its
      // sub-pages, teams, notification preferences) is magic_starter's, and so
      // are its `.title()` calls. The CATALOGUE is ours: `trans()` returns the
      // key itself when we have no entry, so a starter route we have not
      // supplied renders `magic_starter.titles.login` in the browser tab.
      //
      // Read from the package we actually resolve rather than from a copy, so
      // a starter release that adds a route fails here instead of shipping a
      // raw key. All 20 of its routes were untitled until magic_starter#115,
      // and every tab on that surface read the bare app name.
      final shipped = _starterTitleKeys();

      expect(
        shipped,
        isNotEmpty,
        reason:
            'The resolved magic_starter ships no `magic_starter.titles` block. '
            'It predates the release that titled its routes, so every account '
            'and settings page shows the bare app name in the tab.',
      );

      final enJson =
          jsonDecode(File('assets/lang/en.json').readAsStringSync())
              as Map<String, dynamic>;
      final ours =
          ((enJson['magic_starter'] as Map<String, dynamic>)['titles']
                  as Map<String, dynamic>?) ??
              const <String, dynamic>{};

      final missing = shipped.difference(ours.keys.toSet());
      expect(
        missing,
        isEmpty,
        reason:
            'magic_starter titles its routes with keys our catalogue does not '
            'carry, so each renders as a raw key in the tab: $missing',
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
