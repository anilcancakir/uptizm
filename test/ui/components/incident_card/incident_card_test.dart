import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/ui/components/incident_card/index.dart';
import 'package:uptizm/ui/components/incident_card/incident_card.preview.dart';
import 'package:uptizm/ui/components/status_badge/index.dart';

import '../../../support/incident_fixtures.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card can resolve its recipe via
    // MagicStarter.cardTheme without a full app boot.
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
  // Recipe variant-class assertions
  // ---------------------------------------------------------------------------

  group('incidentCardRecipe', () {
    test('down impact emits bg-down token on stripe', () {
      final cls = incidentCardRecipe(
        variants: {kIncidentCardImpactAxis: 'down'},
      );
      expect(cls, contains('bg-down'));
    });

    test('degraded impact emits bg-degraded token on stripe', () {
      final cls = incidentCardRecipe(
        variants: {kIncidentCardImpactAxis: 'degraded'},
      );
      expect(cls, contains('bg-degraded'));
    });

    test('info impact emits bg-info token on stripe', () {
      final cls = incidentCardRecipe(
        variants: {kIncidentCardImpactAxis: 'info'},
      );
      expect(cls, contains('bg-info'));
    });

    test('default variant resolves to down (bg-down)', () {
      final cls = incidentCardRecipe();
      expect(cls, contains('bg-down'));
    });

    test('base classes are present on every impact variant', () {
      for (final impact in ['down', 'degraded', 'info']) {
        final cls = incidentCardRecipe(
          variants: {kIncidentCardImpactAxis: impact},
        );
        expect(cls, contains('absolute'), reason: '$impact missing absolute');
        expect(cls, contains('top-0'), reason: '$impact missing top-0');
        expect(cls, contains('w-1.5'), reason: '$impact missing w-1.5');
      }
    });

    test('emission order: base precedes variant classes', () {
      final cls = incidentCardRecipe(
        variants: {kIncidentCardImpactAxis: 'down'},
      );
      final baseIdx = cls.indexOf('absolute');
      final variantIdx = cls.indexOf('bg-down');
      expect(baseIdx, lessThan(variantIdx));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('IncidentCard renders incident title as WText', (tester) async {
    final incident = incidentFixtures[0]; // checkout-503 outage
    await tester.pumpWidget(wrap(IncidentCard(incident: incident)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == incident.title),
      isTrue,
      reason: 'title WText not found',
    );
  });

  testWidgets('IncidentCard renders monitor name in meta row', (tester) async {
    final incident = incidentFixtures[0];
    await tester.pumpWidget(wrap(IncidentCard(incident: incident)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == incident.monitorName),
      isTrue,
      reason: 'monitorName WText not found',
    );
  });

  testWidgets('IncidentCard renders startedAt in meta row', (tester) async {
    final incident = incidentFixtures[0];
    await tester.pumpWidget(wrap(IncidentCard(incident: incident)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == incident.startedAt),
      isTrue,
      reason: 'startedAt WText not found',
    );
  });

  testWidgets('IncidentCard renders severity label in meta row', (
    tester,
  ) async {
    final incident = incidentFixtures[0]; // critical severity
    await tester.pumpWidget(wrap(IncidentCard(incident: incident)));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == incident.severity.label),
      isTrue,
      reason: 'severity label WText not found',
    );
  });

  testWidgets('IncidentCard composes magic_starter Card shell', (tester) async {
    await tester.pumpWidget(wrap(IncidentCard(incident: incidentFixtures[0])));
    expect(find.byType(MSCard), findsOneWidget);
  });

  testWidgets('IncidentCard composes StatusBadge for impact', (tester) async {
    // incidentFixtures[3] is non-AI-owned (info/monitoring) — exactly one StatusBadge
    // for the impact; no AI badge.
    await tester.pumpWidget(wrap(IncidentCard(incident: incidentFixtures[3])));
    expect(find.byType(StatusBadge), findsOneWidget);
  });

  testWidgets('IncidentCard invokes onTap when tapped', (tester) async {
    var tapped = false;
    await tester.pumpWidget(
      wrap(IncidentCard(incident: incidentFixtures[0], onTap: () => tapped = true)),
    );
    await tester.tap(find.byType(GestureDetector));
    expect(tapped, isTrue);
  });

  testWidgets('IncidentCard without onTap renders no GestureDetector', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(IncidentCard(incident: incidentFixtures[0])));
    expect(find.byType(GestureDetector), findsNothing);
  });

  testWidgets(
    'IncidentCard shows more StatusBadges for AI-owned than non-AI-owned',
    (tester) async {
      // AI-owned: impact StatusBadge + AI StatusBadge = 2 StatusBadges.
      await tester.pumpWidget(
        wrap(IncidentCard(incident: incidentFixtures[0])), // aiOwned: true
      );
      final aiOwnedBadges = tester
          .widgetList<StatusBadge>(find.byType(StatusBadge))
          .toList();

      // Non-AI-owned: impact StatusBadge only = 1 StatusBadge.
      await tester.pumpWidget(
        wrap(IncidentCard(incident: incidentFixtures[2])), // aiOwned: false
      );
      final nonAiOwnedBadges = tester
          .widgetList<StatusBadge>(find.byType(StatusBadge))
          .toList();

      expect(
        aiOwnedBadges.length,
        greaterThan(nonAiOwnedBadges.length),
        reason: 'AI-owned incident should have one extra AI StatusBadge',
      );
    },
  );

  testWidgets('IncidentCard renders lifecycle label as WBadge', (tester) async {
    final incident = incidentFixtures[0]; // lifecycle: investigating
    await tester.pumpWidget(wrap(IncidentCard(incident: incident)));

    final badges = tester.widgetList<WBadge>(find.byType(WBadge)).toList();
    expect(
      badges.any((b) => b.label == incident.lifecycle.label),
      isTrue,
      reason: 'lifecycle WBadge not found',
    );
  });

  testWidgets('IncidentCardPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const IncidentCardPreview()));
    await tester.pump();
    expect(find.byType(IncidentCard), findsNWidgets(incidentFixtures.length));
  });
}
