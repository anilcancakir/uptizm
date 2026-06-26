import 'package:flutter/widgets.dart';
import 'package:magic_starter/magic_starter.dart';

import 'preview_mock_harness.dart';

/// The navigation chrome a feature-screen preview renders inside.
///
/// The app shell (`layout.app`) decides sidebar-vs-bottom-nav off the window
/// width (`wScreenIs` reads `MediaQuery.size`), and the two layouts cannot show
/// at once. To preview each independently the shell modes OVERRIDE the ambient
/// `MediaQuery` width, so a single catalog pane can pin one or the other.
enum PreviewChrome {
  /// Bare: just the view content, vertically scrollable. Used for plain
  /// screen-content previews and guest screens (no app navigation).
  none,

  /// The authenticated app shell pinned to the DESKTOP layout: the sidebar
  /// navigation rail + header + user menu, full pane width.
  appDesktop,

  /// The authenticated app shell pinned to the MOBILE layout: the bottom
  /// navigation bar + drawer, rendered in a phone-width frame.
  appMobile,
}

/// Shared scaffold for feature-screen previews.
///
/// Installs the [PreviewMockHarness] for the requested [state] (so the wrapped
/// controller-backed view renders backend-free) and DEFERS the view mount by
/// one frame.
///
/// The deferral matters: the catalog builds a preview's body synchronously
/// inside its own `build()`. A magic_starter feature view is controller-backed
/// (`MagicStatefulView`), and binding its controller on mount notifies state
/// listeners; mounting it DURING the catalog build trips "setState() called
/// during build". Mounting it one frame later (after a post-frame callback)
/// moves the controller bind out of the build phase, so the preview is clean.
/// The placeholder shown for that single frame keeps the layout from jumping.
class ScreenPreviewScaffold extends StatefulWidget {
  /// Wraps [builder]'s output, installing the harness for [state] first.
  const ScreenPreviewScaffold({
    super.key,
    required this.state,
    required this.builder,
    this.chrome = PreviewChrome.none,
  });

  /// The state the harness should portray (success, loading, or error).
  final PreviewState state;

  /// Builds the feature view to preview.
  final WidgetBuilder builder;

  /// Which navigation chrome to render the view inside.
  final PreviewChrome chrome;

  @override
  State<ScreenPreviewScaffold> createState() => _ScreenPreviewScaffoldState();
}

class _ScreenPreviewScaffoldState extends State<ScreenPreviewScaffold> {
  /// Bounded height for the app-shell modes. The app layout is a Scaffold with
  /// `Expanded` regions and an anchored bottom nav, so it needs a finite
  /// height; the catalog pane scrolls vertically (unbounded), so the shell gets
  /// a representative viewport height and its content scrolls inside.
  static const double _shellHeight = 760;

  /// Phone-frame width for [PreviewChrome.appMobile] (a common logical width).
  static const double _phoneWidth = 390;

  /// Forced window width that keeps the shell on its desktop layout regardless
  /// of the real browser width (>= the `lg` breakpoint).
  static const double _desktopWidth = 1280;

  bool _mounted = false;

  @override
  void initState() {
    super.initState();
    // Bind the mock network + sample auth session for this state before the
    // view mounts. Idempotent per-state, so it does not churn the singleton.
    PreviewMockHarness.install(widget.state);
    // Mount the controller-backed view one frame later (see the class doc): it
    // must not build during the catalog's build phase.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) setState(() => _mounted = true);
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_mounted) {
      // First frame: a sized placeholder so the column does not jump when the
      // deferred view mounts.
      return const SizedBox(height: 320);
    }

    final Widget view = Builder(builder: widget.builder);

    switch (widget.chrome) {
      case PreviewChrome.appDesktop:
        // Sidebar layout, full pane width. The MediaQuery width is forced wide
        // so `wScreenIs('lg')` stays true even on a narrow browser.
        return SizedBox(
          height: _shellHeight,
          child: _withShell(context, width: _desktopWidth, child: view),
        );
      case PreviewChrome.appMobile:
        // Bottom-nav layout in a centered phone frame. The MediaQuery width is
        // forced narrow so `wScreenIs('lg')` is false -> bottom nav + drawer.
        return Center(
          child: SizedBox(
            width: _phoneWidth,
            height: _shellHeight,
            child: _withShell(context, width: _phoneWidth, child: view),
          ),
        );
      case PreviewChrome.none:
        // Bare content at the catalog pane's natural width. No inner scroll
        // view: the catalog page already scrolls vertically, and nesting a
        // second vertical SingleChildScrollView here breaks the outer scroll
        // geometry (and sidebar scroll-to-section).
        return view;
    }
  }

  /// Wraps [child] in the authenticated app shell (`layout.app`) under a
  /// [MediaQuery] whose width is overridden to [width], pinning the shell's
  /// responsive sidebar-vs-bottom-nav decision for this preview.
  Widget _withShell(
    BuildContext context, {
    required double width,
    required Widget child,
  }) {
    final MediaQueryData base = MediaQuery.of(context);
    return MediaQuery(
      data: base.copyWith(size: Size(width, _shellHeight)),
      child: MagicStarter.view.makeLayout('layout.app', child: child),
    );
  }
}
