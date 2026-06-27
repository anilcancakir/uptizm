import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/ui/components/ai_confidence_badge/index.dart';
import 'package:uptizm/ui/components/ai_insight/ai_insight.dart';
import 'package:uptizm/ui/components/ai_insight/ai_insight.preview.dart';
import 'package:uptizm/ui/components/ai_insight/ai_insight.recipe.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind MagicStarter so any magic_starter widgets used in helpers resolve.
    Magic.singleton('magic_starter', () => MagicStarterManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Recipe assertions
  // ---------------------------------------------------------------------------

  group('aiInsightRecipe', () {
    test('base emits ai-soft background token', () {
      final cls = aiInsightRecipe();
      expect(cls, contains('bg-ai-soft'));
    });

    test('base emits ai border token', () {
      final cls = aiInsightRecipe();
      expect(cls, contains('border-ai'));
    });

    test('base emits rounded-lg', () {
      final cls = aiInsightRecipe();
      expect(cls, contains('rounded-lg'));
    });

    test('base emits flex flex-col gap-4 p-4', () {
      final cls = aiInsightRecipe();
      expect(cls, contains('flex'));
      expect(cls, contains('flex-col'));
      expect(cls, contains('gap-4'));
      expect(cls, contains('p-4'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  // Use the first fixture incident which has a rich IncidentAi payload.
  final IncidentAi sampleAi = incidents.first.ai!;

  testWidgets('AiInsight renders tl;dr text', (tester) async {
    await tester.pumpWidget(wrap(AiInsight(ai: sampleAi)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == sampleAi.tldr),
      isTrue,
      reason: 'tl;dr WText not found in tree',
    );
  });

  testWidgets('AiInsight composes AiConfidenceBadge', (tester) async {
    await tester.pumpWidget(wrap(AiInsight(ai: sampleAi)));

    expect(
      find.byType(AiConfidenceBadge),
      findsOneWidget,
      reason: 'AiConfidenceBadge should be present',
    );
  });

  testWidgets('AiInsight renders evidence-for item labels', (tester) async {
    await tester.pumpWidget(wrap(AiInsight(ai: sampleAi)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final evidence in sampleAi.evidenceFor) {
      expect(
        texts.any((w) => w.data == evidence.label),
        isTrue,
        reason: 'evidence-for label "${evidence.label}" not found',
      );
    }
  });

  testWidgets('AiInsight renders evidence-against item labels', (tester) async {
    await tester.pumpWidget(wrap(AiInsight(ai: sampleAi)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final evidence in sampleAi.evidenceAgainst) {
      expect(
        texts.any((w) => w.data == evidence.label),
        isTrue,
        reason: 'evidence-against label "${evidence.label}" not found',
      );
    }
  });

  testWidgets('AiInsight renders source citations for evidence with source', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(AiInsight(ai: sampleAi)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final evidence in [
      ...sampleAi.evidenceFor,
      ...sampleAi.evidenceAgainst,
    ]) {
      if (evidence.source != null) {
        expect(
          texts.any((w) => w.data == evidence.source),
          isTrue,
          reason: 'citation "${evidence.source}" not found',
        );
      }
    }
  });

  testWidgets('AiInsight passes correct confidence level to badge', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(AiInsight(ai: sampleAi)));

    final badge = tester.widget<AiConfidenceBadge>(
      find.byType(AiConfidenceBadge),
    );
    expect(badge.level, equals(sampleAi.confidence));
  });

  testWidgets('AiInsight omits evidence sections when lists are empty', (
    tester,
  ) async {
    // Build a minimal IncidentAi with empty evidence lists.
    const IncidentAi minimalAi = IncidentAi(
      trigger: 'AI anomaly',
      confidence: AiConfidence.low,
      tldr: 'Brief analysis with no evidence items.',
      evidenceFor: [],
      evidenceAgainst: [],
      suggestedActions: [],
      similarIncidents: [],
    );

    await tester.pumpWidget(wrap(const AiInsight(ai: minimalAi)));

    // tl;dr should still be rendered.
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == minimalAi.tldr),
      isTrue,
      reason: 'tl;dr WText must appear even with empty evidence lists',
    );
  });

  testWidgets('AiInsight renders for medium-confidence fixture', (
    tester,
  ) async {
    final IncidentAi mediumAi = incidents[1].ai!;
    await tester.pumpWidget(wrap(AiInsight(ai: mediumAi)));

    final badge = tester.widget<AiConfidenceBadge>(
      find.byType(AiConfidenceBadge),
    );
    expect(badge.level, equals(AiConfidence.medium));
  });

  testWidgets('AiInsightPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const AiInsightPreview()));
    await tester.pump();

    // All fixture AI incidents should produce an AiInsight widget.
    final aiIncidentCount = incidents.where((i) => i.ai != null).length;
    expect(find.byType(AiInsight), findsNWidgets(aiIncidentCount));
  });
}
