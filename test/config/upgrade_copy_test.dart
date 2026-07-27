import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// Boot-level regression test for the upgrade-wall copy contract.
///
/// The plan wall is rendered by magic_starter ([MSUpgradeNudge],
/// [MSUpgradeDialog]), but the copy lives in THIS app's bundled lang files. The
/// widgets read `common.upgrade`, `common.upgrade_available_on` and
/// `common.upgrade_dialog_not_now`; a missing key does not throw, it renders the
/// raw dotted key, which ships a 20-plus-character token where a button label
/// belongs. So the seam is asserted here rather than trusted to review.
void main() {
  /// Flattens a nested lang map into the dotted keys [Translator] caches.
  ///
  /// Mirrors `JsonAssetLoader`: flattening is the loader's job, the translator
  /// stores whatever the loader returns verbatim.
  Map<String, dynamic> flatten(Map<String, dynamic> source, [String prefix = '']) {
    final Map<String, dynamic> flat = {};
    source.forEach((String key, Object? value) {
      final String path = prefix.isEmpty ? key : '$prefix.$key';
      if (value is Map<String, dynamic>) {
        flat.addAll(flatten(value, path));
      } else {
        flat[path] = value;
      }
    });

    return flat;
  }

  /// Reads the app's real bundled lang file for [locale], pre-flattened.
  Map<String, dynamic> readBundledLang(String locale) {
    final File file = File('assets/lang/$locale.json');

    return flatten(json.decode(file.readAsStringSync()) as Map<String, dynamic>);
  }

  /// Serves a pre-flattened lang map, standing in for the bundled asset loader.
  Future<Map<String, dynamic>> useLang(Map<String, dynamic> keys) async {
    Translator.instance.setLoader(_MapLangLoader(keys));
    await Translator.instance.setLocale(const Locale('en'));

    return keys;
  }

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  tearDown(Translator.reset);

  testWidgets('en.json carries the nudge copy MSUpgradeNudge asks for', (
    tester,
  ) async {
    final Map<String, dynamic> lang = await useLang(readBundledLang('en'));

    await tester.pumpWidget(
      wrap(
        const MSUpgradeNudge(
          message: 'You have reached your 3-responder limit.',
          requiredPlan: 'Business',
        ),
      ),
    );

    final String planLine = (lang['common.upgrade_available_on'] as String)
        .replaceAll(':plan', 'Business');
    expect(find.text(planLine), findsOneWidget);
    expect(find.text(lang['common.upgrade'] as String), findsOneWidget);
    // The raw key would render verbatim if the app stopped shipping it.
    expect(find.text('common.upgrade'), findsNothing);
    expect(find.text('common.upgrade_available_on'), findsNothing);
  });

  testWidgets('tr.json carries the same nudge copy, translated', (
    tester,
  ) async {
    final Map<String, dynamic> lang = await useLang(readBundledLang('tr'));

    await tester.pumpWidget(
      wrap(
        const MSUpgradeNudge(
          message: 'Yanıtlayıcı sınırınıza ulaştınız.',
          requiredPlan: 'Business',
        ),
      ),
    );

    final String planLine = (lang['common.upgrade_available_on'] as String)
        .replaceAll(':plan', 'Business');
    expect(find.text(planLine), findsOneWidget);
    expect(find.text(lang['common.upgrade'] as String), findsOneWidget);
    expect(find.text('common.upgrade'), findsNothing);
    expect(find.text('common.upgrade_available_on'), findsNothing);
  });

  testWidgets('en.json carries the dialog dismiss + upgrade labels', (
    tester,
  ) async {
    final Map<String, dynamic> lang = await useLang(readBundledLang('en'));

    await tester.pumpWidget(
      wrap(
        MSUpgradeDialog(
          message: 'AI monitor analysis is available on the Pro plan and up.',
          requiredPlan: 'Pro',
          onUpgrade: () {},
          onDismiss: () {},
        ),
      ),
    );

    expect(
      find.text(lang['common.upgrade_dialog_not_now'] as String),
      findsOneWidget,
    );
    expect(find.text(lang['common.upgrade'] as String), findsOneWidget);
    expect(find.text('common.upgrade_dialog_not_now'), findsNothing);
  });
}

/// Feeds a pre-flattened lang map to [Translator], mirroring the bundled
/// `JsonAssetLoader` without touching the asset bundle.
class _MapLangLoader implements TranslationLoader {
  /// Creates a loader serving [keys] for every locale.
  const _MapLangLoader(this.keys);

  /// The pre-flattened dotted-key translations.
  final Map<String, dynamic> keys;

  @override
  Future<Map<String, dynamic>> load(Locale locale) async => keys;
}
