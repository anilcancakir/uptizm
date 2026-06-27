import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/ui/components/ai_analysis_card/ai_analysis_card.dart';
import 'package:uptizm/ui/components/ai_analysis_card/ai_analysis_card.preview.dart';
import 'package:uptizm/ui/components/ai_analysis_card/ai_analysis_card.recipe.dart';
import 'package:uptizm/ui/components/ai_insight/index.dart';

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

  group('aiAnalysisCardRecipe', () {
    test('base emits flex flex-col', () {
      final cls = aiAnalysisCardRecipe();
      expect(cls, contains('flex'));
      expect(cls, contains('flex-col'));
    });

    test('base emits gap-6', () {
      final cls = aiAnalysisCardRecipe();
      expect(cls, contains('gap-6'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  // Use the first fixture incident which has a rich IncidentAi payload.
  final IncidentAi sampleAi = incidents.first.ai!;

  testWidgets('AiAnalysisCard renders the tl;dr text', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == sampleAi.tldr),
      isTrue,
      reason: 'tl;dr WText not found in tree',
    );
  });

  testWidgets('AiAnalysisCard composes AiInsight', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));

    expect(
      find.byType(AiInsight),
      findsOneWidget,
      reason: 'AiInsight should be composed inside AiAnalysisCard',
    );
  });

  testWidgets('AiAnalysisCard passes correct ai data to AiInsight', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));

    final insight = tester.widget<AiInsight>(find.byType(AiInsight));
    expect(insight.ai, equals(sampleAi));
  });

  testWidgets('AiAnalysisCard renders suggested-action titles', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final action in sampleAi.suggestedActions) {
      expect(
        texts.any((w) => w.data == action.title),
        isTrue,
        reason: 'action title "${action.title}" not found',
      );
    }
  });

  testWidgets('AiAnalysisCard renders similar-incident titles', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final incident in sampleAi.similarIncidents) {
      expect(
        texts.any((w) => w.data == incident.title),
        isTrue,
        reason: 'similar incident title "${incident.title}" not found',
      );
    }
  });

  testWidgets('AiAnalysisCard onActionTap fires only on explicit tap', (
    tester,
  ) async {
    final List<AiSuggestedAction> tapped = [];

    await tester.pumpWidget(
      wrap(
        AiAnalysisCard(
          ai: sampleAi,
          onActionTap: (action) => tapped.add(action),
        ),
      ),
    );

    // Before any interaction the callback must not have fired.
    expect(tapped, isEmpty, reason: 'onActionTap must not auto-execute');

    // Scroll the action GestureDetector into view and tap it.
    final Finder actionFinder = find.byType(GestureDetector).first;
    await tester.ensureVisible(actionFinder);
    await tester.tap(actionFinder);
    await tester.pump();

    expect(tapped.length, equals(1), reason: 'onActionTap should fire once');
    expect(
      tapped.first,
      equals(sampleAi.suggestedActions.first),
      reason: 'tapped action should match the first suggested action',
    );
  });

  testWidgets('AiAnalysisCard onActionTap callback does not fire without tap', (
    tester,
  ) async {
    var callCount = 0;
    await tester.pumpWidget(
      wrap(AiAnalysisCard(ai: sampleAi, onActionTap: (_) => callCount++)),
    );

    // Pump without any interaction.
    await tester.pump();

    expect(callCount, equals(0), reason: 'no auto-execution on build');
  });

  testWidgets('AiAnalysisCard renders without onActionTap callback', (
    tester,
  ) async {
    // Null callback renders action rows as non-interactive; must not throw.
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));
    await tester.pump();

    expect(find.byType(AiAnalysisCard), findsOneWidget);
  });

  testWidgets('AiAnalysisCard renders for medium-confidence fixture', (
    tester,
  ) async {
    final IncidentAi mediumAi = incidents[1].ai!;
    await tester.pumpWidget(
      wrap(AiAnalysisCard(ai: mediumAi, onActionTap: (_) {})),
    );

    expect(find.byType(AiInsight), findsOneWidget);
  });

  testWidgets('AiAnalysisCard omits similar-incidents section when empty', (
    tester,
  ) async {
    // The docs-blip fixture has an empty similarIncidents list.
    final IncidentAi minimalAi = incidents
        .firstWhere((i) => i.ai != null && i.ai!.similarIncidents.isEmpty)
        .ai!;

    await tester.pumpWidget(wrap(AiAnalysisCard(ai: minimalAi)));

    // No similar-incident title from that fixture should appear.
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final incident in minimalAi.similarIncidents) {
      expect(texts.any((w) => w.data == incident.title), isFalse);
    }
  });

  testWidgets('AiAnalysisCardPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const AiAnalysisCardPreview()));
    await tester.pump();

    // All fixture AI incidents should produce an AiAnalysisCard widget.
    final aiIncidentCount = incidents.where((i) => i.ai != null).length;
    expect(find.byType(AiAnalysisCard), findsNWidgets(aiIncidentCount));
  });
}
