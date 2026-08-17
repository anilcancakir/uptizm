import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/enums/ai_confidence.dart' show AiConfidence;
import 'package:uptizm/app/models/incident.dart';
import 'package:uptizm/ui/components/ai_confidence_badge/index.dart';
import 'package:uptizm/ui/components/ai_inbox_item/ai_inbox_item.dart';
import 'package:uptizm/ui/components/ai_inbox_item/ai_inbox_item.preview.dart';
import 'package:uptizm/ui/components/ai_inbox_item/ai_inbox_item.recipe.dart';

import '../../../support/bundled_lang.dart';
import '../../../support/incident_fixtures.dart';

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
      await tester.pumpWidget(wrap(AiInboxItem(incident: incidentFixtures.first)));
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
  final Incident sampleIncident = incidentFixtures.first;

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
    final Incident mediumIncident = incidentFixtures[1];
    await tester.pumpWidget(wrap(AiInboxItem(incident: mediumIncident)));

    final badge = tester.widget<AiConfidenceBadge>(
      find.byType(AiConfidenceBadge),
    );
    expect(badge.level, equals(AiConfidence.medium));
  });

  testWidgets('AiInboxItemPreview covers both verdict states', (tester) async {
    await tester.pumpWidget(wrap(const AiInboxItemPreview()));
    await tester.pump();

    // The preview catalog is the component contract's variant matrix, so the
    // assertion is about what it COVERS, not about how many rows it happens to
    // hold: every row renders, and the disputed state is one of them. The count
    // used to be derived from `incidentFixtures`, a list the preview does not
    // read, so adding a row here failed a test about something else.
    expect(find.byType(AiInboxItem), findsWidgets);
    expect(
      find.byIcon(Icons.info_outline),
      findsOneWidget,
      reason: 'the catalog must show the caveat line, and show it on exactly '
          'the row that carries a negative verdict',
    );
    expect(tester.takeException(), isNull);
  });

  // ---------------------------------------------------------------------------
  // The model's verdict line
  // ---------------------------------------------------------------------------

  /// One inbox row whose `ai.confirmed` is whatever the wire carried.
  ///
  /// Built through [Incident.fromMap] rather than a hand-made [IncidentAi], so
  /// the decode step is part of what is under test: the field travels as a JSON
  /// tri-state and an `as bool?` that silently defaulted would pass a fixture
  /// that named the value directly.
  Incident rowWithVerdict(Object? confirmed) {
    return Incident.fromMap({
      'id': 'anomaly-1',
      'signal_source': 'ai_anomaly',
      'ai_owned': true,
      'started_at': '2026-08-15T21:04:00Z',
      'primary_monitor_id': 'm0',
      'monitors': const [
        {'monitor_id': 'm0', 'name': 'fluttersdk.com'},
      ],
      'ai': {
        'trigger': 'anomaly',
        'confidence': 'low',
        'tldr':
            'Response time was flagged by the EWMA detector: observed 53ms '
            'against a 120.8ms baseline.',
        'confirmed': confirmed,
      },
    });
  }

  testWidgets('AiInboxItem states the caveat when the model disputed the row', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(AiInboxItem(incident: rowWithVerdict(false))));
    await tester.pump();

    expect(
      find.byIcon(Icons.info_outline),
      findsOneWidget,
      reason: 'a disputed anomaly must say so, or it reads as one the model '
          'stood behind',
    );
  });

  testWidgets('AiInboxItem stays silent when the model confirmed the row', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(AiInboxItem(incident: rowWithVerdict(true))));
    await tester.pump();

    expect(find.byIcon(Icons.info_outline), findsNothing);
  });

  testWidgets('AiInboxItem stays silent when no model answered at all', (
    tester,
  ) async {
    // The statistical degrade path (over budget, or a gateway failure) writes a
    // suggestion with no verdict. Marking that as disputed would put words in
    // the mouth of a model that never read the evidence, so null is NOT false.
    await tester.pumpWidget(wrap(AiInboxItem(incident: rowWithVerdict(null))));
    await tester.pump();

    expect(find.byIcon(Icons.info_outline), findsNothing);
  });

  testWidgets('AiInboxItem lays the real caveat sentence out on a phone width', (
    tester,
  ) async {
    // The SHIPPED Turkish string, not a fixture: the caveat is a full sentence
    // beside a glyph, and the raw i18n key it falls back to is one short
    // unbreakable token that would prove nothing about wrapping.
    Translator.instance.setLoader(_BundledTurkishLoader());
    await Translator.instance.setLocale(const Locale('tr'));

    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(wrap(AiInboxItem(incident: rowWithVerdict(false))));
    await tester.pump();

    expect(
      find.text(readBundledLang('tr')['uptizm.ai.unconfirmed'] as String),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });
}

/// Feeds [trans] the app's shipped Turkish catalogue, so a layout assertion is
/// made against the sentence an operator actually reads.
class _BundledTurkishLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async =>
      readBundledLang('tr');
}
