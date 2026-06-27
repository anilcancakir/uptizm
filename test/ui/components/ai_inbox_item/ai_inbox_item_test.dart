import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/ui/components/ai_confidence_badge/index.dart';
import 'package:uptizm/ui/components/ai_inbox_item/ai_inbox_item.dart';
import 'package:uptizm/ui/components/ai_inbox_item/ai_inbox_item.preview.dart';
import 'package:uptizm/ui/components/ai_inbox_item/ai_inbox_item.recipe.dart';

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

  group('aiInboxItemRecipe', () {
    test('base emits a neutral surface-container card (not an ai fill)', () {
      final cls = aiInboxItemRecipe();
      expect(cls, contains('bg-surface-container'));
      expect(cls, isNot(contains('bg-ai-soft')));
    });

    test(
      'base emits a neutral border (ai tone is the stripe, not the border)',
      () {
        final cls = aiInboxItemRecipe();
        expect(cls, contains('border-color-border'));
      },
    );

    test('base emits rounded-lg', () {
      final cls = aiInboxItemRecipe();
      expect(cls, contains('rounded-lg'));
    });

    test('base clips with overflow-hidden so the stripe corners round', () {
      final cls = aiInboxItemRecipe();
      expect(cls, contains('overflow-hidden'));
    });

    testWidgets('renders the ai stripe as a Positioned bar without overflow', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(AiInboxItem(incident: incidents.first)));
      await tester.pump();
      // A Positioned 4px bar paints the stripe; the pl-5 content clears it.
      expect(find.byType(Positioned), findsWidgets);
      expect(tester.takeException(), isNull);
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  // Use the first fixture incident which has a rich IncidentAi payload.
  final IncidentSummary sampleIncident = incidents.first;

  testWidgets('AiInboxItem renders the monitor name', (tester) async {
    await tester.pumpWidget(wrap(AiInboxItem(incident: sampleIncident)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == sampleIncident.monitorName),
      isTrue,
      reason: 'monitor name WText not found in tree',
    );
  });

  testWidgets('AiInboxItem composes AiConfidenceBadge', (tester) async {
    await tester.pumpWidget(wrap(AiInboxItem(incident: sampleIncident)));

    expect(
      find.byType(AiConfidenceBadge),
      findsOneWidget,
      reason: 'AiConfidenceBadge should be composed inside AiInboxItem',
    );
  });

  testWidgets('AiInboxItem passes correct confidence to badge', (tester) async {
    await tester.pumpWidget(wrap(AiInboxItem(incident: sampleIncident)));

    final badge = tester.widget<AiConfidenceBadge>(
      find.byType(AiConfidenceBadge),
    );
    expect(badge.level, equals(sampleIncident.ai!.confidence));
  });

  testWidgets('AiInboxItem fires onApprove only on explicit tap', (
    tester,
  ) async {
    var callCount = 0;
    await tester.pumpWidget(
      wrap(
        AiInboxItem(
          incident: sampleIncident,
          onApprove: () => callCount++,
          onDismiss: () {},
        ),
      ),
    );

    // Before any interaction the callback must not have fired.
    expect(callCount, equals(0), reason: 'onApprove must not auto-execute');

    // Tap the approve button (the WButton wrapping the open-incident label).
    // WAnchor and WButton both wrap children; find the first WButton.
    await tester.tap(find.byType(WButton).first);
    await tester.pump();

    expect(
      callCount,
      equals(1),
      reason: 'onApprove should fire on explicit tap',
    );
  });

  testWidgets('AiInboxItem fires onDismiss only on explicit tap', (
    tester,
  ) async {
    var callCount = 0;
    await tester.pumpWidget(
      wrap(
        AiInboxItem(
          incident: sampleIncident,
          onApprove: () {},
          onDismiss: () => callCount++,
        ),
      ),
    );

    // Before any interaction the callback must not have fired.
    expect(callCount, equals(0), reason: 'onDismiss must not auto-execute');

    // Tap the dismiss button (the second WButton in the row).
    await tester.tap(find.byType(WButton).last);
    await tester.pump();

    expect(
      callCount,
      equals(1),
      reason: 'onDismiss should fire on explicit tap',
    );
  });

  testWidgets('AiInboxItem renders without onApprove or onDismiss callbacks', (
    tester,
  ) async {
    // Null callbacks mean the buttons are inert, but the widget must not throw.
    await tester.pumpWidget(wrap(AiInboxItem(incident: sampleIncident)));
    await tester.pump();

    expect(find.byType(AiInboxItem), findsOneWidget);
  });

  testWidgets('AiInboxItem renders for medium-confidence fixture', (
    tester,
  ) async {
    final IncidentSummary mediumIncident = incidents[1];
    await tester.pumpWidget(wrap(AiInboxItem(incident: mediumIncident)));

    final badge = tester.widget<AiConfidenceBadge>(
      find.byType(AiConfidenceBadge),
    );
    expect(badge.level, equals(AiConfidence.medium));
  });

  testWidgets('AiInboxItemPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const AiInboxItemPreview()));
    await tester.pump();

    // All fixture AI incidents should produce an AiInboxItem widget.
    final aiIncidentCount = incidents.where((i) => i.ai != null).length;
    expect(find.byType(AiInboxItem), findsNWidgets(aiIncidentCount));
  });
}
