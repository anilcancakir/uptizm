import 'package:flutter/material.dart' show Material, MaterialType;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../components/assistant/index.dart';
import 'bottom_nav.dart';
import 'mobile_top_bar.dart';
import 'sidebar.dart';

/// **The Responsive Application Shell**
///
/// The single chrome wrapper every in-app route renders inside. Ported from the
/// design lab's `AppLayout`:
///
/// - **Desktop (`lg`+):** a persistent [Sidebar] beside the routed content.
/// - **Mobile:** a sticky glass [MobileTopBar] above the content and a fixed
///   glass [BottomNav] (4 tabs) below it. No hamburger drawer.
///
/// The active route highlight is derived from the current route path
/// ([GoRouterState]); the routed child is wrapped in a per-route
/// `KeyedSubtree(key: ValueKey(currentPath))` so each route mounts as a clean
/// subtree (the magic_starter teardown pattern, which prevents the go_router
/// shell from tearing render objects down in a confused order under accumulated
/// navigation).
///
/// The responsive split reads [MediaQuery] (via [wScreenIs]) rather than a
/// [LayoutBuilder]: a `LayoutBuilder` makes itself the build-scope root, so a
/// width-driven rebuild during a route transition can rebuild dirty widgets in
/// the wrong scope and cascade RenderFlex / GlobalKey errors at narrow widths.
@immutable
class AppLayout extends StatelessWidget {
  /// The routed page rendered inside the shell.
  final Widget child;

  /// Creates an [AppLayout] wrapping [child].
  const AppLayout({super.key, required this.child});

  /// Resolves the active route path, falling back to `/` outside a router
  /// (e.g. in widget tests that pump the shell without go_router).
  String _currentPath(BuildContext context) {
    try {
      return GoRouterState.of(context).uri.path;
    } catch (_) {
      return '/';
    }
  }

  @override
  Widget build(BuildContext context) {
    final currentPath = _currentPath(context);

    // The breakpoint matches the design lab's `lg:` hide/show: at and above
    // 1024px the sidebar shows and the mobile bars hide, and vice versa.
    final isDesktop = wScreenIs(context, 'lg');

    // Key the routed child by path so each route unmounts then mounts cleanly.
    final keyedChild = KeyedSubtree(key: ValueKey(currentPath), child: child);

    // The shell chrome (sidebar / top bar / bottom nav) is rendered by the
    // go_router ShellRoute builder, OUTSIDE the per-page Material that
    // magic_router wraps each routed page in. Without a Material ancestor, every
    // chrome WText inherits WidgetsApp's "missing Material" error DefaultTextStyle
    // (the debug yellow double underline). A transparent Material installs the
    // theme's clean text defaults for the whole shell while letting the WDiv
    // `bg-surface` paint show through.
    return Material(
      type: MaterialType.transparency,
      child: WDiv(
        className: 'w-full h-full bg-surface',
        child: isDesktop
            ? _buildDesktop(currentPath, keyedChild)
            : _buildMobile(context, currentPath, keyedChild),
      ),
    );
  }

  Widget _buildDesktop(String currentPath, Widget keyedChild) {
    // Sidebar beside a scrollable content column.
    return WDiv(
      className: 'flex flex-row w-full h-full',
      children: [
        Sidebar(currentPath: currentPath),
        WDiv(
          className: 'flex-1 h-full overflow-hidden',
          // Content scroll region with the floating AI assistant mounted on
          // top (StackFit.expand fills both). Overlaying the content region
          // (not the whole shell) keeps the FAB anchored bottom-right of the
          // viewport on desktop.
          child: Stack(
            fit: StackFit.expand,
            children: [
              WDiv(
                className: 'overflow-y-auto',
                scrollPrimary: true,
                child: keyedChild,
              ),
              const Assistant(),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildMobile(
    BuildContext context,
    String currentPath,
    Widget keyedChild,
  ) {
    // Sticky top bar, scrollable content, bottom tab bar. The BottomNav is a
    // flow child (not a fixed overlay), so the scroll area already ends above
    // it; no extra bottom padding is needed (an earlier `pb-24` left a black
    // gap between the last content row and the nav).
    return WDiv(
      className: 'flex flex-col w-full h-full',
      children: [
        const MobileTopBar(),
        Expanded(
          // Content scroll region with the floating AI assistant on top. The
          // Stack spans only the content (between top bar and bottom nav), so
          // the FAB anchors bottom-right ABOVE the bottom nav, never over it.
          //
          // The bottom system inset belongs to BottomNav, which reserves it
          // from `viewPadding.bottom` as a sibling below this region. Handing
          // that same inset down here lets a descendant reserve it a second
          // time: the Assistant's own SafeArea did, which lifted the FAB one
          // home-indicator height (34px on this phone) above what the page
          // container's `pb-24` clearance was sized for, and parked it on the
          // last row of every short list. Removing it here states the geometry
          // once. The desktop branch keeps the inset, because nothing sits
          // between its content column and the display edge.
          child: MediaQuery.removePadding(
            context: context,
            removeBottom: true,
            child: Stack(
              fit: StackFit.expand,
              children: [
                WDiv(
                  className: 'overflow-y-auto',
                  scrollPrimary: true,
                  child: keyedChild,
                ),
                const Assistant(),
              ],
            ),
          ),
        ),
        BottomNav(currentPath: currentPath),
      ],
    );
  }
}
