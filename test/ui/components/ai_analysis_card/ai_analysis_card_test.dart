import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/ui/components/ai_analysis_card/ai_analysis_card.dart';
import 'package:uptizm/ui/components/ai_analysis_card/ai_analysis_card.preview.dart';
import 'package:uptizm/ui/components/ai_analysis_card/ai_analysis_card.recipe.dart';
import 'package:uptizm/ui/components/ai_confidence_badge/index.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    Magic.singleton('magic_starter', () => MagicStarterManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  Widget wrap(Widget widget, {Size size = const Size(1280, 1600)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Recipe assertions
  // ---------------------------------------------------------------------------

  group('aiAnalysisCard recipe', () {
    test('evidence dot resolves green for the FOR side', () {
      final cls = aiAnalysisCardDotRecipe(
        variants: {kAiEvidenceSideAxis: AiEvidenceSide.forSide.name},
      );
      expect(cls, contains('bg-up'));
      expect(cls, contains('rounded-full'));
    });

    test('evidence dot resolves red for the AGAINST side', () {
      final cls = aiAnalysisCardDotRecipe(
        variants: {kAiEvidenceSideAxis: AiEvidenceSide.against.name},
      );
      expect(cls, contains('bg-down'));
    });

    test('panel className carries the ai border + radius', () {
      expect(aiAnalysisCardPanelClassName, contains('border-ai'));
      expect(aiAnalysisCardPanelClassName, contains('rounded-xl'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  final IncidentAi sampleAi = incidents.first.ai!;

  testWidgets('renders the tl;dr text', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(texts.any((w) => w.data == sampleAi.tldr), isTrue);
  });

  testWidgets('renders the confidence badge', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));
    expect(find.byType(AiConfidenceBadge), findsOneWidget);
  });

  testWidgets('renders both evidence-for and evidence-against labels', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(texts.any((w) => w.data == trans('uptizm.ai.evidence')), isTrue);
    expect(
      texts.any((w) => w.data == trans('uptizm.ai.evidence_against')),
      isTrue,
    );
  });

  testWidgets('renders suggested-action titles', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final action in sampleAi.suggestedActions) {
      expect(texts.any((w) => w.data == action.title), isTrue);
    }
  });

  testWidgets('renders similar-incident titles', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final incident in sampleAi.similarIncidents) {
      expect(texts.any((w) => w.data == incident.title), isTrue);
    }
  });

  testWidgets('does not overflow at a mobile width', (tester) async {
    await tester.pumpWidget(
      wrap(AiAnalysisCard(ai: sampleAi), size: const Size(360, 2400)),
    );
    await tester.pump();
    expect(tester.takeException(), isNull);
  });

  testWidgets('onActionTap fires only on explicit tap', (tester) async {
    final List<AiSuggestedAction> tapped = [];
    await tester.pumpWidget(
      wrap(AiAnalysisCard(ai: sampleAi, onActionTap: tapped.add)),
    );
    expect(tapped, isEmpty, reason: 'must not auto-execute');

    // The first WButton in tree order is the first action card (actions render
    // before the footer feedback buttons).
    final action = find.byType(WButton).first;
    await tester.ensureVisible(action);
    await tester.tap(action);
    await tester.pump();

    expect(tapped.length, equals(1));
    expect(tapped.first, equals(sampleAi.suggestedActions.first));
  });

  testWidgets('renders without onActionTap (non-interactive)', (tester) async {
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: sampleAi)));
    await tester.pump();
    expect(find.byType(AiAnalysisCard), findsOneWidget);
  });

  testWidgets('onFeedback fires when a feedback button is tapped', (
    tester,
  ) async {
    bool? rated;
    await tester.pumpWidget(
      wrap(AiAnalysisCard(ai: sampleAi, onFeedback: (h) => rated = h)),
    );
    // Last WButton = "Not helpful" (footer is last).
    final notHelpful = find.byType(WButton).last;
    await tester.ensureVisible(notHelpful);
    await tester.tap(notHelpful);
    await tester.pump();
    expect(rated, isFalse);
  });

  testWidgets('omits similar-incidents section when empty', (tester) async {
    final IncidentAi minimalAi = incidents
        .firstWhere((i) => i.ai != null && i.ai!.similarIncidents.isEmpty)
        .ai!;
    await tester.pumpWidget(wrap(AiAnalysisCard(ai: minimalAi)));
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final incident in minimalAi.similarIncidents) {
      expect(texts.any((w) => w.data == incident.title), isFalse);
    }
  });

  testWidgets('AiAnalysisCardPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const AiAnalysisCardPreview()));
    await tester.pump();
    final aiIncidentCount = incidents.where((i) => i.ai != null).length;
    expect(find.byType(AiAnalysisCard), findsNWidgets(aiIncidentCount));
  });
}
