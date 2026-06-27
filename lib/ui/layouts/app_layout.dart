import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

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

    return WDiv(
      className: 'w-full h-full bg-surface',
      child: isDesktop
          ? _buildDesktop(currentPath, keyedChild)
          : _buildMobile(context, currentPath, keyedChild),
    );
  }

  Widget _buildDesktop(String currentPath, Widget keyedChild) {
    // Sidebar beside a scrollable content column.
    return WDiv(
      className: 'flex flex-row w-full h-full',
      children: [
        Sidebar(currentPath: currentPath),
        WDiv(
          className: 'flex-1 flex flex-col h-full overflow-hidden',
          children: [
            WDiv(
              className: 'flex-1 overflow-y-auto',
              scrollPrimary: true,
              child: keyedChild,
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildMobile(
    BuildContext context,
    String currentPath,
    Widget keyedChild,
  ) {
    // Sticky top bar, scrollable content, fixed bottom tab bar. The bottom
    // padding clears the fixed BottomNav so a page's last row stays visible.
    return WDiv(
      className: 'flex flex-col w-full h-full',
      children: [
        const MobileTopBar(),
        Expanded(
          child: WDiv(
            className: 'overflow-y-auto pb-24',
            scrollPrimary: true,
            child: keyedChild,
          ),
        ),
        BottomNav(currentPath: currentPath),
      ],
    );
  }
}
