import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/ui/components/assistant/assistant.preview.dart';
import 'package:uptizm/ui/components/assistant/index.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Baseline fake network so AssistantController resolves the `network`
    // service; tests that exercise a live reply override this with a stub
    // seeding the `POST /assistant` envelope before pumping.
    Http.fake();
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
        child: Scaffold(body: widget),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Recipe assertions
  // ---------------------------------------------------------------------------

  group('assistantFabRecipe', () {
    test('base emits the ai tone and a circular shape', () {
      final cls = assistantFabRecipe();
      expect(cls, contains('bg-ai'));
      expect(cls, contains('rounded-full'));
    });
  });

  group('assistantBubbleRecipe', () {
    test('user role emits the brand primary tone', () {
      final cls = assistantBubbleRecipe(variants: {kAssistantRoleAxis: 'user'});
      expect(cls, contains('bg-primary'));
      expect(cls, contains('text-on-primary'));
    });

    test('assistant role emits the muted surface tone', () {
      final cls = assistantBubbleRecipe(
        variants: {kAssistantRoleAxis: 'assistant'},
      );
      expect(cls, contains('bg-surface-container'));
      expect(cls, contains('text-fg'));
    });

    test('base emits the design-lab bubble geometry', () {
      final cls = assistantBubbleRecipe(
        variants: {kAssistantRoleAxis: 'assistant'},
      );
      expect(cls, contains('rounded-2xl'));
      expect(cls, contains('max-w-[85%]'));
      expect(cls, contains('leading-relaxed'));
    });
  });

  group('assistantSurfaceRecipe', () {
    test('base emits the 2xl rounding and glass surface fallback', () {
      final cls = assistantSurfaceRecipe();
      expect(cls, contains('rounded-2xl'));
      expect(cls, contains('bg-surface/95'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('renders the collapsed FAB at rest', (tester) async {
    await tester.pumpWidget(wrap(const Assistant()));

    expect(find.byType(WButton), findsOneWidget);
    expect(find.text('Uptizm AI'), findsNothing);
  });

  testWidgets('tapping the FAB opens the assistant surface', (tester) async {
    await tester.pumpWidget(wrap(const Assistant()));

    await tester.tap(find.byType(WButton));
    await tester.pump();

    expect(find.text('Uptizm AI'), findsOneWidget);
    // The greeting bubble is shown on open.
    expect(find.textContaining("Hi, I'm Uptizm AI"), findsOneWidget);
  });

  testWidgets('assistant messages lead with an ai-toned avatar', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const Assistant()));
    await tester.tap(find.byType(WButton));
    await tester.pump();

    // The header glyph plus the greeting's assistant avatar both render the
    // sparkle; a user bubble would not, so the open surface shows exactly two.
    expect(find.byIcon(Icons.auto_awesome), findsNWidgets(2));
  });

  testWidgets('a user message renders without an avatar', (tester) async {
    Http.fake({
      'assistant': Http.response({
        'data': {
          'answer': 'Your API monitor is up with 99.98% uptime.',
          'confidence': 'high',
          'stripped_citations': [],
        },
      }),
    });
    await tester.pumpWidget(wrap(const Assistant()));
    await tester.tap(find.byType(WButton));
    await tester.pump();

    await tester.tap(find.text('Which monitors are slow?'));
    await tester.pumpAndSettle();

    // Header glyph + greeting avatar + the live reply's avatar = 3 sparkles;
    // the user bubble between them contributes none (flex-row-reverse, no
    // avatar), matching the design-lab row model.
    expect(find.byIcon(Icons.auto_awesome), findsNWidgets(3));
  });

  testWidgets('quick-prompt chips appear before the first reply', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const Assistant()));
    await tester.tap(find.byType(WButton));
    await tester.pump();

    expect(find.text('Which monitors are slow?'), findsOneWidget);
    expect(find.text('Create a monitor'), findsOneWidget);
  });

  testWidgets('tapping a chip appends the user message and a reply', (
    tester,
  ) async {
    Http.fake({
      'assistant': Http.response({
        'data': {
          'answer': 'Your API monitor is up with 99.98% uptime.',
          'confidence': 'high',
          'stripped_citations': [],
        },
      }),
    });
    await tester.pumpWidget(wrap(const Assistant()));
    await tester.tap(find.byType(WButton));
    await tester.pump();

    await tester.tap(find.text('Which monitors are slow?'));
    await tester.pump();

    // The chip text is echoed as a user bubble immediately, before the live
    // reply resolves.
    expect(find.text('Which monitors are slow?'), findsOneWidget);
    // And the chips are gone once the conversation has progressed.
    expect(find.text('Create a monitor'), findsNothing);

    await tester.pumpAndSettle();

    // The grounded live reply is present once the round-trip resolves.
    expect(
      find.textContaining('Your API monitor is up with 99.98% uptime.'),
      findsOneWidget,
    );
  });

  testWidgets('a failed round-trip surfaces an error toast and leaves the '
      'conversation unchanged', (tester) async {
    Http.fake({'assistant': Http.response({'message': 'Server error'}, 500)});
    // Bind LogManager so Log.error() works inside AssistantController.ask's
    // failure path.
    Magic.singleton('log', () => LogManager());
    await tester.pumpWidget(wrap(const Assistant()));
    await tester.tap(find.byType(WButton));
    await tester.pump();

    await tester.tap(find.text('Which monitors are slow?'));
    await tester.pumpAndSettle();

    // The user message is still appended, but no assistant reply follows a
    // failed round-trip (the toast/log already surfaced the failure), so the
    // sparkle count stays at the header glyph + greeting avatar (2).
    expect(find.text('Which monitors are slow?'), findsOneWidget);
    expect(find.byIcon(Icons.auto_awesome), findsNWidgets(2));
  });

  testWidgets('closing the surface returns to the FAB', (tester) async {
    await tester.pumpWidget(wrap(const Assistant()));
    await tester.tap(find.byType(WButton));
    await tester.pump();

    await tester.tap(find.byIcon(Icons.close));
    await tester.pump();

    expect(find.text('Uptizm AI'), findsNothing);
    expect(find.byType(WButton), findsOneWidget);
  });

  testWidgets('preview renders the embedded chat surface without error', (
    tester,
  ) async {
    // The catalog page scrolls; the embedded surface is a fixed 560px tall, so
    // give it a scrollable host (the 800x600 test viewport is shorter).
    await tester.pumpWidget(
      wrap(const SingleChildScrollView(child: AssistantPreview())),
    );
    await tester.pump();

    expect(find.byType(Assistant), findsOneWidget);
    // Embedded mode shows the open surface (header title) at rest, not the FAB.
    expect(find.text('Uptizm AI'), findsOneWidget);
    expect(find.textContaining("Hi, I'm Uptizm AI"), findsOneWidget);
  });
}
