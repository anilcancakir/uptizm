// The one entry point every link from outside the app goes through.
//
// This group used to live in `test/app/providers/app_service_provider_test.dart`
// as "a tapped push opens the incident it names", against a hand-rolled push-tap
// subscription. That subscription is gone: a push tap and an operating-system
// link now reach the same `UptizmDeeplinkHandler` through `magic_deeplink`'s
// manager, so the cases move with the behaviour and three arrive with them.
//
// 1. The team switch is a SERVER WRITE that moves the scoping and the paying
//    subject, so it is reachable only from a source that authored its payload.
//    The first two cases are the pair that says so, and they are the reason this
//    file exists rather than a rename of the old group.
// 2. What is validated and what is navigated are the same value.
// 3. A path this app does not serve is refused rather than swallowed, so the
//    manager can answer honestly for it.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_deeplink/magic_deeplink.dart'
    show DeeplinkManager, DeeplinkSource;
import 'package:magic_starter/magic_starter.dart' show MagicStarterManager;
import 'package:uptizm/app/models/user.dart' show User;
import 'package:uptizm/app/providers/app_service_provider.dart'
    show AppServiceProvider;
import 'package:uptizm/app/support/uptizm_deeplink_handler.dart';
import 'package:uptizm/config/deeplink.dart' show deeplinkConfig;
import 'package:uptizm/config/magic_starter.dart' show magicStarterConfig;
import 'package:uptizm/config/uptizm_status_tokens.dart'
    show uptizmStatusAliases;

import '../../support/bundled_lang.dart';

/// Serves uptizm's own shipped English catalogue, whatever locale is asked for.
///
/// The toasts a cross-team switch raises are real `trans()` keys, and reading
/// the shipped asset rather than an inline map keeps a missing key visible here
/// instead of rendering as itself.
class _BundledLangLoader implements TranslationLoader {
  const _BundledLangLoader();

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang('en');
}

void main() {
  late UptizmDeeplinkHandler handler;

  /// The route table an external link navigates inside.
  void registerRoutes() {
    MagicRouter.reset();
    MagicRoute.page('/', () => const SizedBox());
    MagicRoute.page('/incidents/:id', () => const SizedBox());
    MagicRoute.page('/monitors/:id', () => const SizedBox());
  }

  /// A signed-in session on a team the payload describes with
  /// [teamAttributes].
  void signIn(Map<String, dynamic> teamAttributes) {
    Auth.fake(
      user: User.fromMap(<String, dynamic>{
        'id': 'u1',
        'name': 'Ada',
        'current_team': teamAttributes,
      }),
    );
  }

  /// Mounts the router so a navigation has somewhere to go, under a [WindTheme]
  /// because the cross-team toasts render into the navigator's own overlay and
  /// their W-widgets resolve their tokens from an ancestor.
  Future<void> mountRouter(WidgetTester tester) async {
    await tester.pumpWidget(
      WindTheme(
        data: WindThemeData(aliases: uptizmStatusAliases),
        child: MaterialApp.router(
          routerConfig: MagicRouter.instance.routerConfig,
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  /// Hands [uri] to the handler and lets it travel the switch, the navigation
  /// and the toast's auto-dismiss timer before answering.
  ///
  /// The future is held rather than awaited straight away: the switch resolves
  /// through the fake driver inside the tester's zone, and a bare `await` on it
  /// would suspend the test body with nothing pumping frames behind it.
  Future<bool> route(
    WidgetTester tester,
    String uri, {
    required DeeplinkSource source,
    Map<String, dynamic>? payload,
  }) async {
    final Future<bool> handled = handler.handle(
      Uri.parse(uri),
      source: source,
      payload: payload,
    );

    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 5));

    return handled;
  }

  /// Every team-switch write [network] recorded, which is the request the
  /// source gate exists to keep an untrusted link away from.
  List<MagicRequest> switchWrites(FakeNetworkDriver network) {
    return network.recorded
        .map((entry) => entry.$1)
        .where(
          (MagicRequest request) =>
              request.method == 'PUT' && request.url == '/user/current-team',
        )
        .toList();
  }

  setUp(() async {
    MagicApp.reset();
    Magic.flush();

    // The manager is a process singleton and outlives both resets above, so a
    // handler registered by one test would otherwise still be walking the chain
    // inside the next one.
    DeeplinkManager().reset();

    // The handler reads `deeplink.domain` to decide whether an absolute URI
    // addresses THIS app, which is the shape a mobile driver publishes for a
    // Universal Link. Loaded from the app's own config rather than an inline
    // literal, so a domain change there is a change here.
    Config.set(
      'deeplink',
      deeplinkConfig['deeplink'] as Map<String, dynamic>,
    );

    Magic.singleton('log', () => LogManager());
    Config.set('logging', <String, dynamic>{
      'default': 'console',
      'channels': <String, dynamic>{
        'console': <String, dynamic>{'driver': 'console', 'level': 'debug'},
      },
    });

    // `switchTeamAndIdentifyStore` runs through `MagicStarterTeamController`,
    // which resolves its theming and its copy through the starter's manager.
    Config.set(
      'magic_starter',
      magicStarterConfig['magic_starter'] as Map<String, dynamic>,
    );
    Magic.singleton('magic_starter', () => MagicStarterManager());

    Http.fake();

    Translator.instance.setLoader(const _BundledLangLoader());
    await Translator.instance.setLocale(const Locale('en'));

    registerRoutes();
    handler = UptizmDeeplinkHandler(
      switchTeam: AppServiceProvider.switchTeamAndIdentifyStore,
    );
  });

  tearDown(() {
    DeeplinkManager().reset();
    MagicRouter.reset();
    MagicApp.reset();
    Magic.flush();
  });

  group('the team switch is reachable only from a source that authored it', () {
    testWidgets('an OS link naming another team navigates and switches '
        'nothing', (WidgetTester tester) async {
      // The whole reason the source travels with the URI. Anyone who can send
      // this device a link can craft one, and a switch is a server write that
      // moves both the scoping subject and the paying subject. Refusing to
      // navigate instead would be the other failure: the link names a page this
      // app serves, so it opens, and the backend answers its deliberate 404 for
      // a team the session is not on.
      final FakeNetworkDriver network = Http.fake();
      signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
      await mountRouter(tester);

      final bool handled = await route(
        tester,
        '/incidents/1?team_id=other',
        source: DeeplinkSource.osLink,
        payload: null,
      );

      expect(handled, isTrue);
      network.assertNotSent(
        (MagicRequest request) =>
            request.method == 'PUT' && request.url == '/user/current-team',
      );
      expect(MagicRouter.instance.currentPath, '/incidents/1');
    });

    testWidgets('an OS link carrying a whole payload still switches nothing', (
      WidgetTester tester,
    ) async {
      // The case above cannot fail for the reason it claims on its own: it
      // arrives with no payload, so an implementation with no source gate at
      // all still switches nothing there. This one hands the OS link exactly
      // the payload a push would carry, which leaves the SOURCE as the only
      // thing between a crafted link and the server write. Nothing delivers
      // this shape today (the driver subscription passes no payload), and that
      // is the point: the gate is on where the instruction came from, not on
      // whether the caller happened to bring a payload.
      final FakeNetworkDriver network = Http.fake();
      signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
      await mountRouter(tester);

      final bool handled = await route(
        tester,
        '/incidents/1',
        source: DeeplinkSource.osLink,
        payload: <String, dynamic>{
          'deep_link': '/incidents/1',
          'team_id': 'other',
        },
      );

      expect(handled, isTrue);
      expect(switchWrites(network), isEmpty);
      expect(MagicRouter.instance.currentPath, '/incidents/1');
    });

    testWidgets('a tapped push naming another team switches once, then '
        'navigates', (WidgetTester tester) async {
      // The twin of the case above, and the port of the old group's "a tap for
      // another team switches to it, then lands on the incident": the rota that
      // paged this responder is team-scoped, so without the switch the tap
      // reaches `authorizeTeam`'s 404 for the incident they were just woken up
      // for. Counted rather than asserted present, because a second switch
      // would be a second server write nobody asked for.
      final FakeNetworkDriver network = Http.fake();
      signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
      await mountRouter(tester);

      final bool handled = await route(
        tester,
        '/incidents/1',
        source: DeeplinkSource.push,
        payload: <String, dynamic>{
          'deep_link': '/incidents/1',
          'team_id': 'other',
        },
      );

      expect(handled, isTrue);
      expect(switchWrites(network), hasLength(1));
      expect(switchWrites(network).single.data, <String, dynamic>{
        'team_id': 'other',
      });
      expect(MagicRouter.instance.currentPath, '/incidents/1');
    });

    testWidgets('a push whose payload names this team navigates without '
        'switching', (WidgetTester tester) async {
      final FakeNetworkDriver network = Http.fake();
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);

      final bool handled = await route(
        tester,
        '/incidents/inc-1',
        source: DeeplinkSource.push,
        payload: <String, dynamic>{
          'type': 'incident_opened',
          'team_id': 't1',
          'deep_link': '/incidents/inc-1',
        },
      );

      expect(handled, isTrue);
      expect(switchWrites(network), isEmpty);
      expect(MagicRouter.instance.currentPath, '/incidents/inc-1');
    });

    testWidgets('a push naming no team navigates rather than refusing', (
      WidgetTester tester,
    ) async {
      // The same principle the notification manager applies to `subject`: an
      // absent field is not evidence of a mismatch, so a server older than the
      // `team_id` key must not leave the responder on whatever screen the app
      // was showing.
      final FakeNetworkDriver network = Http.fake();
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);

      final bool handled = await route(
        tester,
        '/incidents/inc-2',
        source: DeeplinkSource.push,
        payload: <String, dynamic>{'deep_link': '/incidents/inc-2'},
      );

      expect(handled, isTrue);
      expect(switchWrites(network), isEmpty);
      expect(MagicRouter.instance.currentPath, '/incidents/inc-2');
    });

    testWidgets('a link arriving while the local team is unresolved navigates '
        'without attempting a switch', (WidgetTester tester) async {
      // The local mirror of the case above: a restored session whose
      // `currentTeam` has not resolved yet must not read as a mismatch just
      // because an empty-string fallback never equals a real owner id.
      final FakeNetworkDriver network = Http.fake();
      Auth.fake(
        user: User.fromMap(<String, dynamic>{'id': 'u1', 'name': 'Ada'}),
      );
      await mountRouter(tester);

      final bool handled = await route(
        tester,
        '/incidents/inc-6',
        source: DeeplinkSource.push,
        payload: <String, dynamic>{
          'team_id': 't9',
          'deep_link': '/incidents/inc-6',
        },
      );

      expect(handled, isTrue);
      expect(switchWrites(network), isEmpty);
      expect(MagicRouter.instance.currentPath, '/incidents/inc-6');
    });

    testWidgets('a refused switch keeps the responder where they were, not on '
        'a 404', (WidgetTester tester) async {
      // Navigating anyway would be the defect with an extra step: the backend
      // still resolves the incident against `current_team_id`, so a switch that
      // did not take lands on the same 404.
      Http.fake(<String, MagicResponse>{
        'user/current-team': Http.response(<String, dynamic>{
          'message': 'That team is not yours.',
        }, 403),
      });
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);

      final bool handled = await route(
        tester,
        '/incidents/inc-4',
        source: DeeplinkSource.push,
        payload: <String, dynamic>{
          'team_id': 't9',
          'deep_link': '/incidents/inc-4',
        },
      );

      expect(handled, isFalse);
      expect(MagicRouter.instance.currentPath, '/');
    });
  });

  group('only a destination this app serves is navigated', () {
    testWidgets('a link carrying a host is refused, not navigated', (
      WidgetTester tester,
    ) async {
      // `startsWith('/')` alone does not say "an in-app path":
      // `//evil.example/incidents/inc-9` passes it while parsing to a URI whose
      // authority is somebody else's host, and `MagicRoute.to` hands its
      // argument straight to `GoRouter.go` with no sanitising of its own.
      final FakeLogManager log = Log.fake();
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);

      final String? before = MagicRouter.instance.currentPath;
      final bool handled = await route(
        tester,
        '//evil.example/incidents/inc-9',
        source: DeeplinkSource.push,
        payload: <String, dynamic>{
          'team_id': 't1',
          'deep_link': '//evil.example/incidents/inc-9',
        },
      );

      expect(handled, isFalse);
      expect(
        MagicRouter.instance.currentPath,
        before,
        reason: 'the link moved the app nowhere',
      );
      expect(
        log.entries
            .where(
              (FakeLogEntry entry) =>
                  entry.message.contains('names no in-app destination'),
            )
            .length,
        1,
      );
    });

    testWidgets('a path this app does not serve is refused, and the manager '
        'says so', (WidgetTester tester) async {
      // The case the push-tap guard had no reason to carry: `/main.dart.js`
      // clears every part of the old guard (no scheme, no authority, a leading
      // slash) and is not a route. `canHandle` answering false is what lets the
      // manager walk past this handler rather than reporting a link it never
      // opened as handled.
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);
      DeeplinkManager().registerHandler(handler);

      final String? before = MagicRouter.instance.currentPath;

      expect(handler.canHandle(Uri.parse('/main.dart.js')), isFalse);
      expect(
        await route(tester, '/main.dart.js', source: DeeplinkSource.osLink),
        isFalse,
      );
      expect(
        await DeeplinkManager().handleUri(
          Uri.parse('/main.dart.js'),
          source: DeeplinkSource.osLink,
        ),
        isFalse,
      );
      expect(MagicRouter.instance.currentPath, before);
    });

    testWidgets('the destination navigated is the one that was validated', (
      WidgetTester tester,
    ) async {
      // Validating one value and handing a different one to the router is the
      // shape every future bypass takes. A traversal is what makes the two
      // visibly different: `Uri` normalises `/incidents/../monitors/m-1` to
      // `/monitors/m-1` at parse time, so the allowlist reads the normalised
      // path and the router has to be given that same value. Handing over the
      // raw string would leave the app on a path no route matches.
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);

      final bool handled = await route(
        tester,
        '/incidents/../monitors/m-1?tab=checks',
        source: DeeplinkSource.osLink,
      );

      expect(handled, isTrue);
      expect(MagicRouter.instance.currentPath, '/monitors/m-1');
      expect(MagicRouter.instance.currentLocation, '/monitors/m-1?tab=checks');
    });

    test('canHandle answers for the routes this app serves', () {
      for (final String path in <String>[
        '/',
        '/incidents/inc-1',
        '/monitors/m-1',
        '/status/sp-1',
        '/teams/t-1',
        '/invitations/token',
        '/settings/notifications',
      ]) {
        expect(handler.canHandle(Uri.parse(path)), isTrue, reason: path);
      }

      // A foreign host is refused whether or not it wears a scheme, and a
      // relative reference is refused because it names no path at all. The
      // app's OWN absolute address is deliberately not asserted either way
      // here; see the deep-link handler's note on the Universal Link a mobile
      // driver delivers.
      for (final String path in <String>[
        '/main.dart.js',
        '/assets/fonts/Geist.woff2',
        'https://evil.example/incidents/inc-1',
        '//evil.example/incidents/inc-1',
        r'/\evil.example/incidents/inc-1',
        'incidents/inc-1',
      ]) {
        expect(handler.canHandle(Uri.parse(path)), isFalse, reason: path);
      }
    });
  });

  testWidgets('one instruction is handled once, however often the app '
      'registers the handler', (WidgetTester tester) async {
    // The port of the old group's "a second boot replaces the subscription
    // instead of stacking a second one". The subscription it guarded is gone,
    // and what replaced it is a handler registered on the manager from
    // `AppServiceProvider.boot()`, which a widget test boots repeatedly.
    // Counted on the switch write because it is the one observable a duplicate
    // run would produce twice: two `go()` calls to one location are one
    // navigation, so a second handler run would hide behind it.
    final FakeNetworkDriver network = Http.fake();
    signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
    await mountRouter(tester);

    DeeplinkManager().registerHandler(handler);
    DeeplinkManager().registerHandler(handler);

    final Future<bool> handled = DeeplinkManager().handleUri(
      Uri.parse('/incidents/inc-5'),
      source: DeeplinkSource.push,
      payload: <String, dynamic>{
        'team_id': 'other',
        'deep_link': '/incidents/inc-5',
      },
    );
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 5));

    expect(await handled, isTrue);
    expect(
      switchWrites(network),
      hasLength(1),
      reason: 'one instruction reaches the handler once',
    );
    expect(MagicRouter.instance.currentPath, '/incidents/inc-5');
  });

  group('an absolute URI is served only when it addresses this app', () {
    // A mobile driver does NOT hand this handler a path. `AppLinksDriver`
    // publishes `app_links`' `uriLinkStream` unchanged and the provider passes
    // it verbatim, so a Universal Link arrives as the whole address the OS
    // claimed. A guard that refuses every authority refuses every real deep
    // link, silently, while every push test stays green: the push payload
    // carries a relative path and cannot see the gap.
    //
    // Accepting our own host is not a hole. The OS only delivers a link for a
    // host in the entitlement or the manifest, and it only claims that host
    // after fetching and verifying the association file from it, so the
    // authority is checked before this app ever runs. Any OTHER authority is
    // refused exactly as before.

    testWidgets('a Universal Link on the configured host opens its page',
        (WidgetTester tester) async {
      signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
      await mountRouter(tester);

      expect(
        await route(
          tester,
          'https://app.uptizm.com/incidents/inc-7',
          source: DeeplinkSource.osLink,
        ),
        isTrue,
      );
      expect(MagicRouter.instance.currentPath, '/incidents/inc-7');
    });

    testWidgets('a link on any other host is refused',
        (WidgetTester tester) async {
      signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
      await mountRouter(tester);

      expect(
        await route(
          tester,
          'https://evil.example/incidents/inc-7',
          source: DeeplinkSource.osLink,
        ),
        isFalse,
      );
      expect(MagicRouter.instance.currentPath, '/');
    });

    testWidgets('a non-http scheme on the configured host is refused',
        (WidgetTester tester) async {
      // A private scheme is a surface this app never claimed, so a URI carrying
      // one did not come from the association files and is not evidence of
      // anything the OS verified.
      signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
      await mountRouter(tester);

      expect(
        await route(
          tester,
          'uptizm://app.uptizm.com/incidents/inc-7',
          source: DeeplinkSource.osLink,
        ),
        isFalse,
      );
      expect(MagicRouter.instance.currentPath, '/');
    });

    testWidgets('a host that merely ends with the configured one is refused',
        (WidgetTester tester) async {
      // `notapp.uptizm.com` and `app.uptizm.com.evil.test` both pass a naive
      // `contains` or `endsWith`, which is why the check is an equality.
      signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
      await mountRouter(tester);

      for (final String host in <String>[
        'notapp.uptizm.com',
        'app.uptizm.com.evil.test',
      ]) {
        expect(
          await route(
            tester,
            'https://$host/incidents/inc-7',
            source: DeeplinkSource.osLink,
          ),
          isFalse,
          reason: '$host is not app.uptizm.com',
        );
        expect(MagicRouter.instance.currentPath, '/');
      }
    });

    testWidgets('an absolute URI still cannot switch teams',
        (WidgetTester tester) async {
      // The source gate is what protects the server write, and widening what
      // counts as an in-app address must not widen that. A crafted link on our
      // own host carrying a team in its query is the case to pin.
      final FakeNetworkDriver network = Http.fake();
      signIn(<String, dynamic>{'id': 'mine', 'name': 'Mine'});
      await mountRouter(tester);

      expect(
        await route(
          tester,
          'https://app.uptizm.com/incidents/inc-7?team_id=other',
          source: DeeplinkSource.osLink,
        ),
        isTrue,
      );
      expect(switchWrites(network), isEmpty);
      expect(MagicRouter.instance.currentPath, '/incidents/inc-7');
    });
  });
}
