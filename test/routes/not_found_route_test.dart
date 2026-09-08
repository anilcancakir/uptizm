import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/routes/app.dart';

/// Pins the load-bearing registration-order invariant named in the
/// `not-found` step: `MagicRouter._buildRoutes()` appends every STANDALONE
/// route to the go_router tree before any layout's `ShellRoute`, so a
/// catch-all registered outside the [AppLayout] group would match `/` (and
/// every other in-app route) before the group's own `ShellRoute` is even
/// consulted, silently breaking the whole app. The catch-all must instead be
/// the LAST child registered inside the group.
void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    MagicRouter.reset();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
    MagicRouter.reset();
  });

  test(
    'the not-found catch-all is the LAST child of the AppLayout group, never a standalone route',
    () {
      registerAppRoutes();

      // 1. It must not exist as a standalone route: a standalone catch-all
      //    would shadow every AppLayout route (including `/`) because
      //    `_buildRoutes()` adds every standalone route to the go_router tree
      //    before any layout's ShellRoute.
      final List<String> standalonePaths = MagicRouter.instance.routes
          .map((route) => route.path)
          .toList();
      expect(
        standalonePaths.where((path) => path.contains('(.*)')),
        isEmpty,
        reason:
            'A standalone catch-all route shadows every AppLayout route '
            'because _buildRoutes() adds standalone routes first.',
      );

      // 2. It must exist as the LAST child of the (single) AppLayout group,
      //    so every specific route registered above it still wins go_router's
      //    first-match resolution.
      final List<LayoutDefinition> layouts = MagicRouter.instance.mergedLayouts;
      expect(layouts, hasLength(1));

      final List<RouteDefinition> children = layouts.single.children;
      expect(children, isNotEmpty);
      expect(children.first.path, '/');
      expect(
        children.last.path.contains('(.*)'),
        isTrue,
        reason:
            'The catch-all must be the last child so every specific route '
            'above it wins the first-match resolution.',
      );
    },
  );
}
