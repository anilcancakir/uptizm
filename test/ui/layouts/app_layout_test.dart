import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/ui/components/assistant/index.dart';
import 'package:uptizm/ui/layouts/app_layout.dart';
import 'package:uptizm/ui/layouts/bottom_nav.dart';
import 'package:uptizm/ui/layouts/mobile_top_bar.dart';
import 'package:uptizm/ui/layouts/sidebar.dart';

/// Feeds the floating assistant's greeting + chrome so [trans] returns real
/// English prose instead of the raw key tokens when the shell mounts it.
class _AppLayoutLangLoader implements TranslationLoader {
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
    'uptizm.a11y.notifications': 'Notifications',
    'uptizm.a11y.team_switcher': 'Switch team',
    'uptizm.a11y.account_menu': 'Account',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_AppLayoutLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] so the
  /// W-widgets can resolve Wind styles (and breakpoints) without a running
  /// Magic app or go_router.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: widget),
      ),
    );
  }

  /// Drives the root [MediaQuery] width by sizing the test view, then restores
  /// it after the body runs so each case is isolated.
  Future<void> pumpAtWidth(
    WidgetTester tester,
    double width,
    Widget widget, {
    double bottomInset = 0,
  }) async {
    tester.view.devicePixelRatio = 1.0;
    tester.view.physicalSize = Size(width, 900);
    // Both readings matter and they are read by different owners: BottomNav
    // reserves the strip from `viewPadding.bottom`, SafeArea reacts to
    // `padding.bottom`.
    tester.view.padding = FakeViewPadding(bottom: bottomInset);
    tester.view.viewPadding = FakeViewPadding(bottom: bottomInset);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    addTearDown(tester.view.resetPadding);
    addTearDown(tester.view.resetViewPadding);

    await tester.pumpWidget(wrap(widget));
    await tester.pump();
  }

  // ---------------------------------------------------------------------------
  // Responsive switch: the Done-when criterion
  // ---------------------------------------------------------------------------

  group('AppLayout responsive switch', () {
    const layout = AppLayout(child: MSPageContainer(child: WText('content')));

    testWidgets('renders the desktop Sidebar at wide width (>= lg)', (
      tester,
    ) async {
      // 1200px is above the lg breakpoint (1024) so the sidebar takes over.
      await pumpAtWidth(tester, 1200, layout);

      expect(find.byType(Sidebar), findsOneWidget);
      expect(find.byType(BottomNav), findsNothing);
      expect(find.byType(MobileTopBar), findsNothing);
    });

    testWidgets('renders the mobile bars at narrow width (< lg)', (
      tester,
    ) async {
      // 390px is a phone width, below lg, so the mobile chrome shows instead.
      await pumpAtWidth(tester, 390, layout);

      expect(find.byType(BottomNav), findsOneWidget);
      expect(find.byType(MobileTopBar), findsOneWidget);
      expect(find.byType(Sidebar), findsNothing);
    });

    testWidgets('always renders the routed child', (tester) async {
      await pumpAtWidth(tester, 1200, layout);
      expect(find.text('content'), findsOneWidget);

      await pumpAtWidth(tester, 390, layout);
      expect(find.text('content'), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  // BottomNav: 4 primary tabs, Settings is NOT among them
  // ---------------------------------------------------------------------------

  group('BottomNav', () {
    testWidgets('shows exactly the 4 primary destinations', (tester) async {
      await pumpAtWidth(tester, 390, const BottomNav(currentPath: '/'));

      // trans() returns the raw key when no lang file is loaded (test env), so
      // assert on the i18n keys the labels resolve from.
      expect(find.text('uptizm.nav.home'), findsOneWidget);
      expect(find.text('uptizm.nav.monitors'), findsOneWidget);
      expect(find.text('uptizm.nav.incidents'), findsOneWidget);
      expect(find.text('uptizm.nav.status'), findsOneWidget);
      // Settings is in the top-bar menu, never a bottom tab.
      expect(find.text('uptizm.nav.settings'), findsNothing);
    });

    testWidgets(
      'clamps its label scaling, because four fixed cells cannot reflow',
      (tester) async {
        // iOS accessibility sizes carry a text scale well past 2x. The tab row
        // is four equal cells on a 402pt phone with no room to reflow, so at
        // that scale "Monitors" wrapped to "Monitor" + "s" and ran into
        // "Inciden" + "ts" beside it: the bar grew over the content and the
        // labels became one unreadable run. The icons are fixed-size and were
        // never the problem.
        tester.view.devicePixelRatio = 1.0;
        tester.view.physicalSize = const Size(390, 900);
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await tester.pumpWidget(
          MaterialApp(
            home: MediaQuery(
              data: const MediaQueryData(textScaler: TextScaler.linear(3)),
              child: WindTheme(
                data: WindThemeData(),
                child: const Scaffold(body: BottomNav(currentPath: '/')),
              ),
            ),
          ),
        );
        await tester.pump();

        final BuildContext label = tester.element(
          find.text('uptizm.nav.monitors'),
        );

        expect(
          MediaQuery.textScalerOf(label).scale(10),
          lessThanOrEqualTo(13.0),
          reason: 'a 3x scale must not reach the tab labels unclamped',
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // Sidebar: 5 destinations including Settings
  // ---------------------------------------------------------------------------

  group('Sidebar', () {
    testWidgets('shows all 5 primary destinations including Settings', (
      tester,
    ) async {
      await pumpAtWidth(tester, 1200, const Sidebar(currentPath: '/monitors'));

      // trans() returns the raw key when no lang file is loaded (test env).
      // The sidebar's dashboard destination uses `uptizm.nav.dashboard` (not
      // `uptizm.nav.home`, which only the mobile BottomNav uses).
      expect(find.text('uptizm.nav.dashboard'), findsOneWidget);
      expect(find.text('uptizm.nav.monitors'), findsOneWidget);
      expect(find.text('uptizm.nav.incidents'), findsOneWidget);
      // The sidebar's status-page destination uses `uptizm.nav.status_page`
      // (not the mobile BottomNav's shorter `uptizm.nav.status`).
      expect(find.text('uptizm.nav.status_page'), findsOneWidget);
      // Settings IS a sidebar item on desktop (it appears in the nav and the
      // account menu, hence at least one match).
      expect(find.text('uptizm.nav.settings'), findsWidgets);
    });
  });

  // ---------------------------------------------------------------------------
  // Floating AI assistant: mounted once by the shell (the "Step 9" wire-up)
  // ---------------------------------------------------------------------------

  group('AppLayout floating AI assistant', () {
    const layout = AppLayout(child: MSPageContainer(child: WText('content')));

    testWidgets('mounts exactly one Assistant at desktop and mobile widths', (
      tester,
    ) async {
      await pumpAtWidth(tester, 1200, layout);
      expect(find.byType(Assistant), findsOneWidget);

      await pumpAtWidth(tester, 390, layout);
      expect(find.byType(Assistant), findsOneWidget);
    });

    testWidgets('the collapsed FAB opens the chat surface on tap', (
      tester,
    ) async {
      await pumpAtWidth(tester, 1200, layout);

      // Collapsed: the FAB glyph is present, the conversation is not.
      expect(find.byIcon(Icons.auto_awesome), findsOneWidget);
      expect(
        find.textContaining('I reason from your own checks'),
        findsNothing,
      );

      await tester.tap(find.byIcon(Icons.auto_awesome));
      await tester.pumpAndSettle();

      // Open: the greeting and the close affordance are shown.
      expect(
        find.textContaining('I reason from your own checks'),
        findsOneWidget,
      );
      expect(find.byIcon(Icons.close), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  // Bottom system inset: owned by the BottomNav, not by the content region
  // ---------------------------------------------------------------------------

  group('AppLayout bottom inset ownership', () {
    const layout = AppLayout(child: MSPageContainer(child: WText('content')));

    testWidgets(
      'the mobile content region carries no bottom inset, because the '
      'BottomNav below it already reserves the home indicator',
      (tester) async {
        await pumpAtWidth(tester, 390, layout, bottomInset: 34);

        final BuildContext region = tester.element(find.byType(Assistant));

        // Counting it twice is what parked the FAB one home-indicator height
        // higher than the page container's pb-24 clearance was sized for, so it
        // came to rest on the last row of a list instead of below it.
        expect(MediaQuery.of(region).padding.bottom, 0);
      },
    );

    testWidgets(
      'the mobile FAB clears the content region by its own 16px margin alone',
      (tester) async {
        await pumpAtWidth(tester, 390, layout, bottomInset: 34);

        final double regionBottom = tester.getRect(find.byType(BottomNav)).top;
        final Finder fab = find.ancestor(
          of: find.byIcon(Icons.auto_awesome),
          matching: find.byType(WButton),
        );

        expect(
          regionBottom - tester.getRect(fab).bottom,
          closeTo(16, 0.5),
        );
      },
    );

    testWidgets(
      'the desktop content region keeps the bottom inset, because no nav bar '
      'sits between it and the display edge',
      (tester) async {
        await pumpAtWidth(tester, 1200, layout, bottomInset: 34);

        final BuildContext region = tester.element(find.byType(Assistant));

        expect(MediaQuery.of(region).padding.bottom, 34);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // The shell's popover controls, as assistive technology hears them
  // ---------------------------------------------------------------------------

  group('shell control semantics', () {
    const layout = AppLayout(child: MSPageContainer(child: WText('content')));

    /// `WPopover` wraps its trigger in an unlabelled `Semantics(button: true)`,
    /// because its toggle runs on a raw `Listener` that assistive technology
    /// cannot see. The trigger content produces a node of its own, so each of
    /// these three controls rendered TWO overlapping buttons and none of them
    /// carried a name: measured in the DOM at 1280px, the bell's only accessible
    /// name was the unread COUNT.
    ///
    /// One node each, and named, at both widths.
    for (final (String label, double width) in <(String, double)>[
      ('Switch team', 1200),
      ('Notifications', 1200),
      ('Account', 1200),
      ('Switch team', 390),
      ('Notifications', 390),
      ('Account', 390),
    ]) {
      testWidgets('$label is one named button at ${width.toInt()}px', (
        tester,
      ) async {
        // The semantics tree is only built while something is listening to it.
        // Disposed inside the body, not in a tearDown: the framework checks for
        // a leaked handle before tear-downs run.
        final SemanticsHandle handle = tester.ensureSemantics();

        await pumpAtWidth(tester, width, layout);

        expect(find.bySemanticsLabel(RegExp(label)), findsOneWidget);

        handle.dispose();
      });
    }
  });
}
