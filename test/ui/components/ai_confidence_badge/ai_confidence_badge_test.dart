import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/ai_confidence.dart';
import 'package:uptizm/ui/components/ai_confidence_badge/ai_confidence_badge.dart';

import '../../../support/bundled_lang.dart';

/// The badge renders one word, and that word is the whole component: a test
/// that only asserts the constructor kept its argument cannot tell a localized
/// badge from the hardcoded English one it replaced. Every case here therefore
/// pumps the widget and reads the text against the SHIPPED catalogue, so the
/// assertion moves when `assets/lang/*.json` moves rather than agreeing with a
/// literal the test author typed.
void main() {
  /// Feeds [trans] a shipped catalogue.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: widget),
      ),
    );
  }

  Future<void> useLocale(String locale) async {
    Translator.instance.setLoader(_BundledLoader(locale));
    await Translator.instance.setLocale(Locale(locale));
  }

  group('AiConfidenceBadge', () {
    testWidgets('renders the shipped Turkish word for every level', (
      tester,
    ) async {
      await useLocale('tr');
      final Map<String, dynamic> lang = readBundledLang('tr');

      for (final AiConfidence level in AiConfidence.values) {
        await tester.pumpWidget(wrap(AiConfidenceBadge(level)));
        await tester.pump();

        final Object? expected = lang['uptizm.ai.confidence_${level.name}'];
        expect(
          expected,
          isA<String>(),
          reason: 'the catalogue is missing a word for ${level.name}',
        );
        expect(find.text(expected! as String), findsOneWidget);
      }
    });

    testWidgets('renders the shipped English word for every level', (
      tester,
    ) async {
      await useLocale('en');
      final Map<String, dynamic> lang = readBundledLang('en');

      for (final AiConfidence level in AiConfidence.values) {
        await tester.pumpWidget(wrap(AiConfidenceBadge(level)));
        await tester.pump();

        expect(find.text(lang['uptizm.ai.confidence_${level.name}'] as String),
            findsOneWidget);
      }
    });

    testWidgets('never renders a raw catalogue key', (tester) async {
      // The failure mode of a missing key is the key itself laid out as text,
      // which reads as a typo rather than as a missing translation and has
      // overflowed a row here before.
      await useLocale('tr');

      for (final AiConfidence level in AiConfidence.values) {
        await tester.pumpWidget(wrap(AiConfidenceBadge(level)));
        await tester.pump();

        expect(find.textContaining('uptizm.ai.'), findsNothing);
        expect(tester.takeException(), isNull);
      }
    });

    testWidgets('the three words are distinct', (tester) async {
      // A key built from the enum name could resolve all three to one entry
      // through a copy-paste in the catalogue, and every assertion above would
      // still pass.
      await useLocale('tr');
      final Map<String, dynamic> lang = readBundledLang('tr');

      final Set<String> words = {
        for (final AiConfidence level in AiConfidence.values)
          lang['uptizm.ai.confidence_${level.name}'] as String,
      };

      expect(words.length, AiConfidence.values.length);
    });

    test('exposes the level it was given', () {
      for (final AiConfidence level in AiConfidence.values) {
        expect(AiConfidenceBadge(level).level, level);
      }
    });
  });
}

/// Serves one shipped locale to [trans].
class _BundledLoader implements TranslationLoader {
  final String locale;

  const _BundledLoader(this.locale);

  @override
  Future<Map<String, dynamic>> load(Locale locale) async =>
      readBundledLang(this.locale);
}
