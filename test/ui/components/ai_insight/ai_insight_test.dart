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
    test('inline tone root emits flex items-start', () {
      final classes = aiInsightRecipe(variants: {kAiInsightToneAxis: 'inline'});
      expect(classes['root'], contains('flex'));
      expect(classes['root'], contains('items-start'));
    });

    test(
      'banner tone root emits ai-soft background, border, rounded-xl, p-4',
      () {
        final classes = aiInsightRecipe(
          variants: {kAiInsightToneAxis: 'banner'},
        );
        expect(classes['root'], contains('bg-ai-soft'));
        expect(classes['root'], contains('border-ai-soft'));
        expect(classes['root'], contains('rounded-xl'));
        expect(classes['root'], contains('p-4'));
      },
    );

    test('glyph wrap always emits text-ai', () {
      final inlineClasses = aiInsightRecipe(
        variants: {kAiInsightToneAxis: 'inline'},
      );
      final bannerClasses = aiInsightRecipe(
        variants: {kAiInsightToneAxis: 'banner'},
      );
      expect(inlineClasses['glyphWrap'], contains('text-ai'));
      expect(bannerClasses['glyphWrap'], contains('text-ai'));
    });

    test('banner glyphWrap gets size-8 and rounded-lg tile', () {
      final classes = aiInsightRecipe(variants: {kAiInsightToneAxis: 'banner'});
      expect(classes['glyphWrap'], contains('size-8'));
      expect(classes['glyphWrap'], contains('rounded-lg'));
      expect(classes['glyphWrap'], contains('bg-ai-soft'));
    });

    test('inline glyphWrap gets mt-0.5 nudge', () {
      final classes = aiInsightRecipe(variants: {kAiInsightToneAxis: 'inline'});
      expect(classes['glyphWrap'], contains('mt-0.5'));
    });

    test('banner text slot emits text-fg', () {
      final classes = aiInsightRecipe(variants: {kAiInsightToneAxis: 'banner'});
      expect(classes['text'], contains('text-fg'));
    });

    test('inline text slot emits text-fg-muted', () {
      final classes = aiInsightRecipe(variants: {kAiInsightToneAxis: 'inline'});
      expect(classes['text'], contains('text-fg-muted'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('AiInsight renders child text', (tester) async {
    await tester.pumpWidget(
      wrap(const AiInsight(child: WText('Based on 7 days of checks.'))),
    );

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == 'Based on 7 days of checks.'),
      isTrue,
      reason: 'child WText not found in tree',
    );
  });

  testWidgets('AiInsight renders sparkle glyph', (tester) async {
    await tester.pumpWidget(
      wrap(const AiInsight(child: WText('Any insight text.'))),
    );

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == '✦'),
      isTrue,
      reason: 'Sparkle glyph WText not found',
    );
  });

  testWidgets('AiInsight shows AiConfidenceBadge when confidence is set', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        const AiInsight(
          confidence: AiConfidence.high,
          child: WText('Insight with confidence.'),
        ),
      ),
    );

    expect(
      find.byType(AiConfidenceBadge),
      findsOneWidget,
      reason: 'AiConfidenceBadge should be present when confidence is set',
    );
  });

  testWidgets('AiInsight omits AiConfidenceBadge when confidence is null', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const AiInsight(child: WText('Insight without confidence.'))),
    );

    expect(
      find.byType(AiConfidenceBadge),
      findsNothing,
      reason: 'AiConfidenceBadge must be absent when confidence is null',
    );
  });

  testWidgets('AiInsight passes correct confidence level to badge', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        const AiInsight(
          confidence: AiConfidence.medium,
          child: WText('Medium confidence insight.'),
        ),
      ),
    );

    final badge = tester.widget<AiConfidenceBadge>(
      find.byType(AiConfidenceBadge),
    );
    expect(badge.level, equals(AiConfidence.medium));
  });

  testWidgets('AiInsight renders label before child text', (tester) async {
    await tester.pumpWidget(
      wrap(
        const AiInsight(
          label: 'Uptizm AI',
          child: WText('Something interesting.'),
        ),
      ),
    );

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == 'Uptizm AI '),
      isTrue,
      reason: 'Label WText not found',
    );
    expect(
      texts.any((w) => w.data == 'Something interesting.'),
      isTrue,
      reason: 'Child WText not found',
    );
  });

  testWidgets('AiInsight renders action widget when provided', (tester) async {
    await tester.pumpWidget(
      wrap(
        AiInsight(
          action: const WText('View report'),
          child: const WText('Insight with action.'),
        ),
      ),
    );

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == 'View report'),
      isTrue,
      reason: 'Action WText not found',
    );
  });

  testWidgets('AiInsight omits meta row when confidence and action are null', (
    tester,
  ) async {
    // Minimal AiInsight: no confidence, no action. Only child + sparkle.
    await tester.pumpWidget(
      wrap(const AiInsight(child: WText('Minimal insight.'))),
    );

    // Only the child WText and the sparkle glyph should be present.
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts,
      hasLength(2),
      reason: 'Expected exactly 2 WTexts: sparkle glyph + child',
    );
    expect(find.byType(AiConfidenceBadge), findsNothing);
  });

  testWidgets('AiInsightPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const AiInsightPreview()));
    await tester.pump();

    // The preview always renders exactly 3 AiInsight widgets.
    expect(find.byType(AiInsight), findsNWidgets(3));
  });
}
