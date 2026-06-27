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
    test('base emits ai-soft background token', () {
      final cls = aiInboxItemRecipe();
      expect(cls, contains('bg-ai-soft'));
    });

    test('base emits ai border token', () {
      final cls = aiInboxItemRecipe();
      expect(cls, contains('border-ai'));
    });

    test('base emits rounded-lg', () {
      final cls = aiInboxItemRecipe();
      expect(cls, contains('rounded-lg'));
    });

    test('base emits relative and overflow-hidden', () {
      final cls = aiInboxItemRecipe();
      expect(cls, contains('relative'));
      expect(cls, contains('overflow-hidden'));
    });

    test(
      'base uses side-specific padding tokens to avoid shorthand conflict',
      () {
        final cls = aiInboxItemRecipe();
        // pt-4/pr-4/pb-4/pl-5 instead of p-4+pl-5 to avoid the wind
        // shorthand+longhand same-family conflict warning.
        expect(cls, contains('pt-4'));
        expect(cls, contains('pl-5'));
      },
    );
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
