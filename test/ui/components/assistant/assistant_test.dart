import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/ui/components/assistant/assistant.preview.dart';
import 'package:uptizm/ui/components/assistant/index.dart';

/// Feeds the assistant's greeting, quick-prompt chips, and chrome copy so
/// [trans] returns real English prose instead of the raw key tokens.
class _AssistantLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.assistant.greeting':
        "Hi, I'm Uptizm AI. I reason from your own checks, regions, response "
        'times, and custom metrics, and I can set things up for you. How '
        'can I help?',
    'uptizm.assistant.prompt_slow_monitors': 'Which monitors are slow?',
    'uptizm.assistant.prompt_create_monitor': 'Create a monitor',
    'uptizm.assistant.prompt_declare_incident': 'Declare an incident',
    'uptizm.assistant.prompt_new_status_page': 'New status page',
    'uptizm.assistant.open_label': 'Open Uptizm AI',
    'uptizm.assistant.subtitle': 'Ask, or tell me what to set up',
    'uptizm.assistant.close_label': 'Close assistant',
    'uptizm.assistant.composer_placeholder': 'Message Uptizm AI…',
    'uptizm.assistant.send_label': 'Send',
  };
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Baseline fake network so AssistantController resolves the `network`
    // service; tests that exercise a live reply override this with a stub
    // seeding the `POST /assistant` envelope before pumping.
    Http.fake();
    Translator.instance.setLoader(_AssistantLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
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
      // Not `bg-surface/95`: an opacity modifier on an alias token resolves to
      // no fill at all, so the panel shipped with no background. The alias
      // carries the alpha baked into its hex instead. The guard that this
      // token actually paints lives in test/config/uptizm_theme_test.dart,
      // since a string assertion here cannot tell a live token from a dead one.
      expect(cls, contains('bg-surface-glass-95'));
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
    Http.fake({
      'assistant': Http.response({'message': 'Server error'}, 500),
    });
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

  testWidgets('the dismiss backdrop announces itself by name', (tester) async {
    // The backdrop carries a tap, so wind publishes a button node for it, and
    // its child is a blur with no text for `MergeSemantics` to absorb. Measured
    // in Chrome with the panel open: one 390x724 tappable node with no
    // accessible name, covering the whole screen. Flutter's own `ModalBarrier`
    // labels the equivalent node for the same reason.
    final SemanticsHandle handle = tester.ensureSemantics();

    // The floating mode, opened: the backdrop only exists there. The preview
    // renders the EMBEDDED surface, which has no backdrop at all, so a walk
    // over it passes whether or not the label is set.
    await tester.pumpWidget(wrap(const Assistant()));
    await tester.tap(find.byType(WButton));
    await tester.pump();

    final List<SemanticsNode> nameless = <SemanticsNode>[];
    void walk(SemanticsNode node) {
      final SemanticsData data = node.getSemanticsData();
      if (!node.isMergedIntoParent &&
          data.flagsCollection.isButton &&
          data.label.trim().isEmpty) {
        nameless.add(node);
      }
      node.visitChildren((SemanticsNode child) {
        walk(child);
        return true;
      });
    }

    walk(tester.getSemantics(find.byType(MaterialApp)));

    expect(
      nameless,
      isEmpty,
      reason: 'a button node with no accessible name reached the platform',
    );
    handle.dispose();
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
