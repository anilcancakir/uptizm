import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/incident_timeline/incident_timeline.dart';
import 'package:uptizm/ui/components/incident_timeline/incident_timeline.recipe.dart';

void main() {
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  const sample = [
    TimelineEntry(
      actor: TimelineActor.human,
      author: 'Ada · on-call',
      status: 'Investigating',
      message: 'Rolling back the deploy.',
      time: '14:34',
      isPublic: true,
    ),
    TimelineEntry(
      actor: TimelineActor.ai,
      status: 'Analysis posted',
      message: 'Origin-side fault.',
      time: '14:33',
      autonomous: true,
    ),
    TimelineEntry(
      actor: TimelineActor.system,
      status: 'Threshold breach',
      message: '503 rate crossed the bound.',
      time: '14:32',
    ),
  ];

  // ---------------------------------------------------------------------------
  // Recipe assertions
  // ---------------------------------------------------------------------------

  group('incidentTimelineRecipe', () {
    test('ai actor tints the node and icon with the ai tone', () {
      final cls = incidentTimelineRecipe(variants: {kTimelineActorAxis: 'ai'});
      expect(cls['node'], contains('bg-ai-soft'));
      expect(cls['icon'], contains('text-ai'));
    });

    test('public visibility uses the info tone for the tag', () {
      final cls = incidentTimelineRecipe(
        variants: {kTimelineVisibilityAxis: 'public'},
      );
      expect(cls['tag'], contains('bg-info-soft'));
      expect(cls['tag'], contains('text-info-soft-foreground'));
    });

    test('internal visibility uses the muted tone for the tag', () {
      final cls = incidentTimelineRecipe(
        variants: {kTimelineVisibilityAxis: 'internal'},
      );
      expect(cls['tag'], contains('bg-surface-container'));
      expect(cls['tag'], contains('text-fg-muted'));
    });

    test('node is a size-8 rounded-full circle', () {
      final cls = incidentTimelineRecipe();
      expect(cls['node'], contains('size-8'));
      expect(cls['node'], contains('rounded-full'));
    });

    test('time slot is pushed to the trailing edge with ml-auto', () {
      final cls = incidentTimelineRecipe();
      expect(cls['time'], contains('ml-auto'));
      expect(cls['time'], contains('font-mono'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('renders one node icon per entry', (tester) async {
    await tester.pumpWidget(wrap(const IncidentTimeline(entries: sample)));
    await tester.pump();
    expect(find.byType(WIcon), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('renders each entry status, message, and time', (tester) async {
    await tester.pumpWidget(wrap(const IncidentTimeline(entries: sample)));
    await tester.pump();

    for (final entry in sample) {
      expect(find.text(entry.status), findsOneWidget);
      expect(find.text(entry.message), findsOneWidget);
      expect(find.text(entry.time), findsOneWidget);
    }
  });

  testWidgets('flags an autonomous AI entry with the Auto mode badge', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const IncidentTimeline(entries: sample)));
    await tester.pump();
    expect(find.text('Auto mode'), findsOneWidget);
  });

  testWidgets('tags entries Public or Internal by visibility', (tester) async {
    await tester.pumpWidget(wrap(const IncidentTimeline(entries: sample)));
    await tester.pump();
    // One public entry (human), two internal (ai, system); the `uppercase`
    // className transforms the rendered text, so match the uppercased value.
    expect(find.text('PUBLIC'), findsOneWidget);
    expect(find.text('INTERNAL'), findsNWidgets(2));
  });

  testWidgets('renders without error for a single entry', (tester) async {
    await tester.pumpWidget(wrap(IncidentTimeline(entries: [sample[0]])));
    await tester.pump();
    expect(find.byType(IncidentTimeline), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
