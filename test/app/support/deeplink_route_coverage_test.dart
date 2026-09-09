// Every path the router serves must be a path an external link can reach.
//
// The association files claim `/*` on app.uptizm.com, so the OS hands the app
// EVERY link on that host. `UptizmDeeplinkHandler` then decides which of them
// it will open, from a hand-written list of route families sitting next to a
// route table that grows. When the two diverge the link is refused SILENTLY:
// a false `canHandle` means the manager never calls `handle`, so there is no
// log and no screen, just a link that opens the app and does nothing.
//
// That already happened once. The list shipped as trailing-slash prefixes
// (`/incidents/`), which refuses `/incidents`, `/monitors` and `/status`, all
// three of which the router serves, and refuses `/auth/reset-password`, which
// is the link a locked-out customer receives by mail.
//
// So this test does not read the list. It reads the ROUTE TABLE, the same one
// `MagicRouter` builds its GoRouter from, and asserts the handler accepts
// every path in it. A new route family fails here rather than in production.
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/support/uptizm_deeplink_handler.dart';
import 'package:uptizm/config/deeplink.dart' show deeplinkConfig;
import 'package:uptizm/config/magic_starter.dart' show magicStarterConfig;
import 'package:uptizm/routes/app.dart';

void main() {
  late UptizmDeeplinkHandler handler;

  setUp(() {
    MagicApp.reset();
    Magic.flush();
    MagicRouter.reset();

    Config.set(
      'magic_starter',
      magicStarterConfig['magic_starter'] as Map<String, dynamic>,
    );
    Config.set('deeplink', deeplinkConfig['deeplink'] as Map<String, dynamic>);
    Magic.singleton('magic_starter', () => MagicStarterManager());

    // The whole surface an external link can land on: the account routes
    // magic_starter owns AND uptizm's own. Registering only one half is how a
    // coverage test misses the half that is actually emailed to customers.
    registerMagicStarterAuthRoutes();
    registerMagicStarterProfileRoutes();
    registerMagicStarterTeamRoutes();
    registerMagicStarterNotificationRoutes();
    registerAppRoutes();

    handler = UptizmDeeplinkHandler(switchTeam: (_) async => true);
  });

  tearDown(() {
    MagicRouter.reset();
    MagicApp.reset();
    Magic.flush();
  });

  /// Every registered path, from the standalone routes and from every layout
  /// group's children, with the catch-all itself removed: it exists precisely
  /// to swallow what nothing else matches, so asking whether the handler
  /// serves it is asking the wrong question.
  List<String> registeredPaths() {
    final MagicRouter router = MagicRouter.instance;

    // `fullPath`, not `path`: `MagicRoute.group(prefix:)` stores the prefix on
    // the definition and composes it only in `fullPath`, so a starter auth
    // route reads as `/login` through `path` and is really `/auth/login`.
    // Reading the raw one would have this test asserting against addresses
    // that do not exist.
    return <String>[
      for (final route in router.routes) route.fullPath,
      for (final layout in router.mergedLayouts)
        for (final child in layout.children) child.fullPath,
    ].where((String path) => !path.contains('(.*)')).toList();
  }

  /// A registered pattern with its parameters filled in, since a link carries
  /// values rather than `:id`.
  String concrete(String pattern) {
    return pattern
        .split('/')
        .map((String segment) => segment.startsWith(':') ? 'x1' : segment)
        .join('/');
  }

  test('the handler serves every path the router registers', () {
    final List<String> paths = registeredPaths();

    // A guard on the guard: if the route table came back empty the assertion
    // below would pass by vacuity and say nothing at all.
    expect(
      paths.length,
      greaterThan(20),
      reason: 'the route table should carry both halves of the app',
    );

    final List<String> refused = paths
        .where((String path) => !handler.canHandle(Uri.parse(concrete(path))))
        .toList();

    expect(
      refused,
      isEmpty,
      reason:
          'these routes exist but an external link naming one is refused, '
          'silently, because canHandle returns false and the manager then '
          'never calls handle: $refused',
    );
  });

  test('an absolute link on the configured host reaches them too', () {
    // The OS delivers the whole address it claimed, not a path, so the same
    // coverage has to hold for the shape a real Universal Link arrives in.
    final String domain = Config.get<String>('deeplink.domain', '') ?? '';
    expect(domain, isNotEmpty);

    final List<String> refused = registeredPaths()
        .where(
          (String path) =>
              !handler.canHandle(Uri.parse('https://$domain${concrete(path)}')),
        )
        .toList();

    expect(refused, isEmpty, reason: 'refused as absolute URIs: $refused');
  });

  test('a path the router does not serve is still refused', () {
    // The other direction, so widening the families cannot quietly become
    // "accept everything". These are real paths the web build serves as files.
    for (final String path in <String>[
      '/main.dart.js',
      '/assets/.env',
      '/flutter_service_worker.js',
      '/.well-known/apple-app-site-association',
    ]) {
      expect(
        handler.canHandle(Uri.parse(path)),
        isFalse,
        reason: '$path is a static asset, not a screen',
      );
    }
  });
}
