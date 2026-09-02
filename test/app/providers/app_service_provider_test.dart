// The uptizm half of magic_starter's billing screen.
//
// The package ships the whole screen and none of the product. Everything it
// deliberately does not know arrives through
// `AppServiceProvider.registerBillingSurface`, and this file is the cover for
// that wiring:
//
// 1. The route resolves the PACKAGE view by key, not uptizm's old screen.
// 2. `readTeamOwnership` answers all three states, and `null` for anything
//    unresolved rather than `false`.
// 3. The `plan_card_highlight` slot renders BOTH product lines from `plan.raw`,
//    and nothing at all for a tier carrying neither.
// 4. `withUsageCopy` and `formatCount` reach the meters, so a named resource
//    renders uptizm's word and a Turkish session reads `83.365`.
// 5. `web_origin` is configured, so the checkout call to action is reachable.
//
// What this file cannot prove is what a browser does with the result: the app
// shell swaps widget trees at `lg` (1024px), and a widget test drives one
// surface size at a time with no real navigator, no real rail and no scroll
// physics. That belongs to the dusk walk.
import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show
        Notify,
        PushDriver,
        PushIdentityChange,
        PushNotificationEvent,
        PushPermissionState;
import 'package:magic_payments/magic_payments.dart'
    show
        BillingCheckoutSession,
        BillingCycle,
        BillingEntitlement,
        BillingInvoicesPage,
        BillingService,
        Invoice,
        PaymentMethod,
        UsageStat,
        WebBillingService;
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/models/user.dart' show User;
import 'package:uptizm/app/providers/app_service_provider.dart';
import 'package:uptizm/app/support/formatters.dart' show formatCount;
import 'package:uptizm/app/support/team_types.dart' show withUsageCopy;
import 'package:uptizm/config/magic_starter.dart' show magicStarterConfig;
import 'package:uptizm/config/notifications.dart' show notificationsConfig;
import 'package:uptizm/config/uptizm_status_tokens.dart'
    show uptizmStatusAliases;

import '../../support/bundled_lang.dart';

/// Serves uptizm's own shipped catalogue, whatever locale the translator asks
/// for.
///
/// The locale argument is ignored on purpose: which catalogue a session gets is
/// the subject of the separator case below, so the test drives it directly.
/// Reading the shipped asset rather than an inline map is the point, since a
/// fixture would have the assertion agree with the test author rather than with
/// the product.
class _BundledLangLoader implements TranslationLoader {
  const _BundledLangLoader(this.locale);

  /// The catalogue to serve.
  final String locale;

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}

/// The five entitlement reads, with no purchase rail at all.
///
/// The rail arrives as its RAW WIRE WORD, because that is the only thing the
/// real decoder ever sees; a fake taking an already-decoded case could pass
/// while `ManageVia.fromWire` silently fell into its fallback.
class _ReadsBillingService implements BillingService {
  _ReadsBillingService({this.usage = const <UsageStat>[]});

  /// The wire value for `plan`.
  ///
  /// Fixed at the cheapest tier rather than injectable: every case in this file
  /// is about a collaborator uptizm supplies, and holding the cheapest tier is
  /// what makes the two tiers above it resolve an Upgrade call to action, which
  /// is the affordance the web-origin cases assert on.
  static const String plan = 'free';

  /// The wire word for `manage_via`, fixed for the same reason: none of these
  /// cases is about which surface manages the subscription.
  static const String manageVia = 'none';

  /// The metered usage the usage read resolves to, LABEL-FREE, exactly as
  /// `magic_payments` decodes it: pairing the display copy on is uptizm's job,
  /// and a fixture that pre-labelled these would assert its own words.
  final List<UsageStat> usage;

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    return BillingEntitlement.fromMap(<String, dynamic>{
      'plan': plan,
      'plan_status': 'active',
      'subscribed': true,
      'renews': true,
      'provider': 'stripe',
      'manage_via': manageVia,
      'manage_url': null,
      'ai_analysis_trials_remaining': null,
    });
  }

  @override
  Future<List<Map<String, dynamic>>> getPlans() async => _planWireRows;

  @override
  Future<List<UsageStat>> getUsage() async => usage;

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) async {
    return const BillingInvoicesPage(invoices: <Invoice>[], nextCursor: null);
  }

  @override
  Future<PaymentMethod> getPaymentMethod() async {
    return const PaymentMethod(available: true);
  }
}

/// A build that can serve WEB checkout, which is what gates the purchase call
/// to action alongside the configured origin.
class _WebRailBillingService extends _ReadsBillingService
    implements WebBillingService {
  _WebRailBillingService({super.usage});

  /// Every `successUrl` passed to [checkout], so the origin under test can be
  /// asserted where it is spent rather than only where it is read.
  final List<String> successUrls = <String>[];

  /// Every cycle passed to [checkout], so a test can assert the customer is
  /// charged on the cycle whose figure the card showed them.
  final List<BillingCycle> checkoutCycles = <BillingCycle>[];

  @override
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required BillingCycle cycle,
    required String successUrl,
    required String cancelUrl,
  }) async {
    checkoutCycles.add(cycle);
    successUrls.add(successUrl);

    return const BillingCheckoutSession(
      checkoutUrl: 'https://checkout.example.test/session',
      sessionId: 'session_test',
    );
  }

  @override
  Future<void> swap({required String plan, required BillingCycle cycle}) async {}

  @override
  Future<void> cancel() async {}

  @override
  Future<String> openPortal({String? returnUrl}) async =>
      'https://portal.example.test/session';
}

/// Three catalogue rows, the first two copied from `backend/config/plans.php`.
///
/// `getPlans()` answers rows verbatim, so real rows rather than a minimal
/// hand-written map: a fixture invented here would agree with whatever the
/// decoder happens to read. The order is cheapest-first, which is what decides
/// the Upgrade/Downgrade direction.
///
/// The third row is NOT a shipping tier. Every tier uptizm sells carries an
/// `ai_line`, so the "renders nothing" arm of the highlight has no fixture in
/// the live catalogue, and a branch no fixture reaches is not covered by
/// however many tests pass.
const List<Map<String, dynamic>> _planWireRows = <Map<String, dynamic>>[
  <String, dynamic>{
    'id': 'free',
    'name': 'Free',
    'tagline': 'Kick the tires, solo projects.',
    'monthly': 0,
    'annual': 0,
    'currency': 'usd',
    'ai_line': 'AI anomaly inbox, plus 3 free AI monitor setups.',
    'features': <String>['1 monitor, 3-minute checks, 1 region'],
    'responder_add_on': null,
    'recommended': false,
  },
  <String, dynamic>{
    'id': 'pro',
    'name': 'Pro',
    'tagline': 'Startups and small teams that page.',
    'monthly': 34,
    'annual': 29,
    'currency': 'usd',
    'ai_line':
        'Full AI incident analysis: evidence, confidence, citations, '
        'drafted updates.',
    'features': <String>['50 monitors, 30-second checks'],
    'responder_add_on': r'+$9/mo per extra responder',
    'recommended': true,
  },
  <String, dynamic>{
    'id': 'silent',
    'name': 'Silent',
    'tagline': 'A tier the product does not sell.',
    'monthly': 9,
    'annual': 9,
    'currency': 'usd',
    'features': <String>['Nothing to say about it'],
    'recommended': false,
  },
];

/// A push driver whose tap stream this file drives by hand.
///
/// Registered through `Notify.manager.setPushDriver`, so a tap travels the real
/// path an SDK tap does: the driver's stream, the manager's own subject guard,
/// then the `onPushClicked` stream the app subscribes to. A test that pushed an
/// event straight into the app's handler would pass on a build that never
/// subscribed to anything, which is the exact defect this group covers.
class _TappablePushDriver extends PushDriver {
  /// The tap stream, broadcast because the manager attaches to it eagerly.
  final StreamController<PushNotificationEvent> _clicked =
      StreamController<PushNotificationEvent>.broadcast();

  /// Reports a tap on a push carrying [data].
  void tap(Map<String, dynamic> data) {
    _clicked.add(PushNotificationEvent(data));
  }

  @override
  String get name => 'onesignal';

  @override
  bool get isSupported => true;

  @override
  bool get isOptedIn => true;

  @override
  Future<PushPermissionState> permissionState() async {
    return PushPermissionState.authorized;
  }

  @override
  Future<void> initialize(Map<String, dynamic> config) async {}

  @override
  Future<void> login(String externalId) async {}

  @override
  Future<void> logout() async {}

  @override
  Future<String?> currentExternalId() async => null;

  @override
  Future<String?> currentSubscriptionId() async => 'subscription-1';

  @override
  Future<bool> requestPermission() async => true;

  @override
  Future<void> optIn() async {}

  @override
  Future<void> optOut() async {}

  @override
  Future<void> setTags(Map<String, String> tags) async {}

  @override
  Future<void> removeTag(String key) async {}

  @override
  Stream<PushNotificationEvent> get onNotificationReceived =>
      const Stream<PushNotificationEvent>.empty();

  @override
  Stream<PushNotificationEvent> get onNotificationClicked => _clicked.stream;

  @override
  Stream<PushPermissionState> get onPermissionChanged =>
      const Stream<PushPermissionState>.empty();

  @override
  Stream<PushIdentityChange> get onIdentityChanged =>
      const Stream<PushIdentityChange>.empty();
}

/// A push driver whose reachability inputs and change streams this file drives
/// by hand.
///
/// It overrides no `reachability()`: the base class derives that from
/// `isSupported`, `permissionState()`, `isOptedIn` and `currentSubscriptionId()`
/// exactly as a real SDK driver does, so the four states these tests report are
/// the package's own derivation rather than a value a fake handed over.
class _ReportingPushDriver extends PushDriver {
  /// The two streams the app watches for a change worth reporting. Broadcast
  /// because the manager attaches to them at registration and the app attaches
  /// beside it.
  final StreamController<PushPermissionState> _permissionChanges =
      StreamController<PushPermissionState>.broadcast();
  final StreamController<PushIdentityChange> _identityChanges =
      StreamController<PushIdentityChange>.broadcast();

  /// The platform permission this device currently holds.
  PushPermissionState permission = PushPermissionState.authorized;

  /// Whether this device is opted in to push.
  bool optedIn = true;

  /// The subscription id the platform holds, or null for a device with no
  /// address at all.
  String? subscriptionId = 'sub-phone';

  /// The external id this fake device carries, held and read back the way a
  /// real SDK does so the manager's reconcile is not fooled.
  String? _externalId;

  /// Reports a permission change the way the OS does when a user revokes it in
  /// system settings, with the app already running.
  void changePermission(PushPermissionState next) {
    permission = next;
    _permissionChanges.add(next);
  }

  /// Reports the SDK swapping this device's push subscription, which is what a
  /// re-registration or a restored backup looks like from here.
  void changeSubscription(String? next) {
    subscriptionId = next;
    _identityChanges.add(PushIdentityChange(subscriptionId: next));
  }

  /// Closes both controllers, so a stream does not outlive the test that made
  /// it.
  Future<void> dispose() async {
    await _permissionChanges.close();
    await _identityChanges.close();
  }

  @override
  String get name => 'onesignal';

  @override
  bool get isSupported => true;

  @override
  bool get isOptedIn => optedIn;

  @override
  Future<PushPermissionState> permissionState() async => permission;

  @override
  Future<void> initialize(Map<String, dynamic> config) async {}

  @override
  Future<void> login(String externalId) async {
    _externalId = externalId;
  }

  @override
  Future<void> logout() async {
    _externalId = null;
  }

  @override
  Future<String?> currentExternalId() async => _externalId;

  @override
  Future<String?> currentSubscriptionId() async => subscriptionId;

  @override
  Future<bool> requestPermission() async => true;

  @override
  Future<void> optIn() async {}

  @override
  Future<void> optOut() async {}

  @override
  Future<void> setTags(Map<String, String> tags) async {}

  @override
  Future<void> removeTag(String key) async {}

  @override
  Stream<PushNotificationEvent> get onNotificationReceived =>
      const Stream<PushNotificationEvent>.empty();

  @override
  Stream<PushNotificationEvent> get onNotificationClicked =>
      const Stream<PushNotificationEvent>.empty();

  @override
  Stream<PushPermissionState> get onPermissionChanged =>
      _permissionChanges.stream;

  @override
  Stream<PushIdentityChange> get onIdentityChanged => _identityChanges.stream;
}

/// The AI line the `pro` row carries, as one string.
const String _proAiLine =
    'Full AI incident analysis: evidence, confidence, citations, '
    'drafted updates.';

/// The responder surcharge the `pro` row carries. A recurring CHARGE, asserted
/// by its exact text.
const String _responderAddOn = r'+$9/mo per extra responder';

void main() {
  /// Loads uptizm's OWN `lib/config/magic_starter.dart` the way `Magic.init`
  /// does, so the feature flag and the origin under test are the shipped values
  /// rather than something this file set.
  void loadShippedConfig() {
    final Map<String, dynamic> block =
        magicStarterConfig['magic_starter'] as Map<String, dynamic>;

    Config.set('magic_starter', block);
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

  /// A signed-in OWNER, which is the membership every rendering case below runs
  /// as: a known non-owner would hide the affordances those cases are about.
  void signInAsOwner() {
    signIn(<String, dynamic>{
      'id': 't1',
      'name': 'Alpha',
      'user_role': 'owner',
    });
  }

  setUp(() async {
    MagicApp.reset();
    Magic.flush();

    // Card / Button / Badge / PageHeader resolve their themes through
    // MagicStarter.*, and the feature flag decides whether the view is
    // registered at all, so the config is loaded BEFORE the manager singleton
    // is ever resolved (the manager registers its defaults in its constructor).
    Magic.singleton('log', () => LogManager());
    Config.set('logging', <String, dynamic>{
      'default': 'console',
      'channels': <String, dynamic>{
        'console': <String, dynamic>{'driver': 'console', 'level': 'debug'},
      },
    });
    loadShippedConfig();
    Magic.singleton('magic_starter', () => MagicStarterManager());

    Http.fake();

    Translator.instance.setLoader(const _BundledLangLoader('en'));
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] under a viewport tall enough for the whole screen.
  ///
  /// The theme carries uptizm's status supplement, because the AI tile in the
  /// slot renders on `bg-ai-soft`, which is exactly the token that could not
  /// travel into the package.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: MediaQuery(
        data: const MediaQueryData(size: Size(1280, 12000)),
        child: WindTheme(
          data: WindThemeData(aliases: uptizmStatusAliases),
          child: Scaffold(body: widget),
        ),
      ),
    );
  }

  /// Registers uptizm's real billing surface, swaps the controller for one
  /// reading [billing], mounts the view THROUGH THE REGISTRY and settles the
  /// mount-time reads.
  ///
  /// The registration call is the real one, so the slot under test is the slot
  /// the app ships. Only the controller is replaced, because the read contract
  /// is the one collaborator this app resolves from a live rail.
  Future<void> mount(WidgetTester tester, BillingService billing) async {
    AppServiceProvider.registerBillingSurface();
    Magic.put(
      MagicStarterBillingController(
        usageCopy: withUsageCopy,
        formatNumber: formatCount,
        isOwnerReader: AppServiceProvider.readTeamOwnership,
        storeFundedTeamReader: AppServiceProvider.readStoreFundedTeam,
        billingService: billing,
      ),
    );

    await tester.binding.setSurfaceSize(const Size(1280, 12000));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(MagicStarter.view.make('teams.billing')));
    await tester.pump();
    await tester.pump();
  }

  // ---------------------------------------------------------------------------
  // The push permission posture, and where the app admits push is off
  // ---------------------------------------------------------------------------

  group('the shipped push permission posture', () {
    /// The `notifications.push` block as `Magic.init` composes it.
    Map<String, dynamic> pushConfig() {
      final Map<String, dynamic> block =
          notificationsConfig['notifications'] as Map<String, dynamic>;

      return block['push'] as Map<String, dynamic>;
    }

    test('a declined device is asked again, roughly daily', () {
      // `0`, absent, and a negative value all mean NEVER in
      // `NotificationManager._remindersDue`, so an omitted key is not a neutral
      // default: it is the posture of an app that asks once and gives up. On a
      // product that pages people, a device nobody can reach has to keep saying
      // so.
      final Object? hours = pushConfig()['reprompt_after_hours'];

      expect(hours, isA<int>());
      expect(hours as int, greaterThanOrEqualTo(12));
      expect(hours, lessThanOrEqualTo(36));
    });

    test('a blocked mobile device keeps a route back', () {
      // `fallback_to_settings` doubles as the driver's
      // `canOpenPlatformSettings`, so turning it off would turn the blocked
      // row from a control into a sentence on the one platform that has a way
      // back at all.
      expect(pushConfig()['fallback_to_settings'], isTrue);
    });

    test('the automatic request is raised only where the ask is a real dialog', () {
      // Web browsers want a user gesture, and a login is not one, so the
      // package's automatic request can spend the single ask without rendering
      // anything. This app leans on the reminder there, whose button IS a tap.
      // `flutter test` runs on the VM, so only the non-web half of the pair is
      // measurable here; the reasoning for both lives in
      // `lib/config/notifications.dart`.
      expect(
        pushConfig()['auto_request_on_login'],
        kIsWeb ? isFalse : isTrue,
        reason: 'the OS dialog is honest on mobile and a coin flip on the web',
      );
    });
  });

  group('the shell admits push is off', () {
    /// Both shells the app swaps between at `lg`, as source.
    ///
    /// A source read rather than a mount: the two are separate widget trees by
    /// design, and this repo has already shipped a fix applied to one of them
    /// and not the other (`teamMenuDestinations` exists because the mobile menu
    /// was three rows short). What renders is covered by the widget tests in
    /// `test/ui/components/push_prompt/`; what this pins is that BOTH shells
    /// mount it at all.
    const List<String> shells = <String>[
      'lib/ui/layouts/sidebar.dart',
      'lib/ui/layouts/mobile_top_bar.dart',
    ];

    for (final String shell in shells) {
      test('$shell mounts the push-off notice', () {
        expect(
          File(shell).readAsStringSync(),
          contains('PushOffNotice'),
          reason: 'an engineer whose device cannot be paged has to see it '
              'without opening notification settings',
        );
      });
    }
  });

  group('the route points at the package view', () {
    test('the /teams/billing body resolves the registry key', () {
      // Asserted against the route table's SOURCE, because the routes are
      // registered inside a `MagicRoute.group(layout:)` and a grouped route
      // definition is not reachable from `MagicRouter.instance.routes`.
      final String source = File('lib/routes/app.dart').readAsStringSync();
      final RegExpMatch? billingRoute = RegExp(
        r"MagicRoute\.page\(\s*'/teams/billing',\s*\(\)\s*=>\s*([^,\n]+),",
      ).firstMatch(source);

      expect(billingRoute, isNotNull);
      expect(billingRoute!.group(1), "MagicStarter.view.make('teams.billing')");
      expect(
        source.contains('PlanBillingView'),
        isFalse,
        reason: "the route table must not reach uptizm's old screen",
      );
    });

    test('the key answers the package view, under the shipped config', () {
      // The other half, and the half a consumer actually depends on: a view
      // that exists but is not registered is a screen nobody can open. The
      // feature flag is read from the shipped config file, so an app that
      // forgot to turn `billing` on fails HERE.
      expect(MagicStarterConfig.hasBillingFeatures(), isTrue);
      expect(MagicStarter.view.has('teams.billing'), isTrue);
      expect(
        MagicStarter.view.make('teams.billing'),
        isA<MagicStarterBillingView>(),
      );
    });

    test('the registered controller carries uptizm own collaborators', () {
      AppServiceProvider.registerBillingSurface();

      final MagicStarterBillingController controller =
          Magic.find<MagicStarterBillingController>();

      // Identity, not shape: a pass-through usage copy or a comma-hardcoding
      // formatter would satisfy any behavioural assertion made through a
      // substitute, and both are the defects these parameters are required for.
      expect(controller.usageCopy, same(withUsageCopy));
      expect(controller.formatNumber, same(formatCount));
      expect(
        controller.storeFundedTeamReader,
        same(AppServiceProvider.readStoreFundedTeam),
      );
      expect(
        controller.isOwnerReader,
        same(AppServiceProvider.readTeamOwnership),
      );
      expect(
        controller.storeCheckRegistered,
        isTrue,
        reason:
            'an unregistered cross-team check refuses the store purchase '
            'outright, so leaving it out is not a neutral omission',
      );
    });
  });

  group('readTeamOwnership answers three states', () {
    test('a role of owner is a known owner', () {
      signInAsOwner();

      expect(AppServiceProvider.readTeamOwnership(), isTrue);
    });

    test('any other role is a known non-owner', () {
      signIn(<String, dynamic>{
        'id': 't1',
        'name': 'Alpha',
        'user_role': 'member',
      });

      expect(AppServiceProvider.readTeamOwnership(), isFalse);
    });

    test('a payload with no role falls back to the owner id', () {
      // The fallback arm, for a team payload the server sent without a role.
      // Every fallback right-hand side is unvisited code until a fixture
      // reaches it, and this one decides whether an owner sees a purchase
      // button.
      signIn(<String, dynamic>{
        'id': 't1',
        'name': 'Alpha',
        'owner_id': 'u1',
      });

      expect(AppServiceProvider.readTeamOwnership(), isTrue);
    });

    test('no auth container is UNRESOLVED, not a refusal', () {
      // The state a widget test mounting the screen without a container is in,
      // and the one that must not throw: this is read during build, and an
      // exception out of a gate takes down a screen whose whole design is that
      // no single read can.
      expect(Magic.bound('auth'), isFalse);
      expect(AppServiceProvider.readTeamOwnership(), isNull);
    });

    test('a signed-out session is UNRESOLVED', () {
      Auth.fake();

      expect(AppServiceProvider.readTeamOwnership(), isNull);
    });

    test('a team naming neither a role nor an owner is UNRESOLVED', () {
      // `null` rather than `false`, and that is the decision: an unresolved
      // membership must not stand between an owner and paying us, while the
      // server refuses a real non-owner regardless.
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});

      expect(AppServiceProvider.readTeamOwnership(), isNull);
    });
  });

  group('the plan card highlight renders two product lines', () {
    testWidgets('a tier carrying both renders both', (
      WidgetTester tester,
    ) async {
      signInAsOwner();

      await mount(tester, _WebRailBillingService());

      expect(tester.takeException(), isNull);
      expect(find.text(_proAiLine), findsOneWidget);
      // By its exact text, because it is a recurring CHARGE: dropping it takes
      // money out of a purchase decision, which is a different class of harm
      // from dropping the value claim above it.
      expect(find.text(_responderAddOn), findsOneWidget);
    });

    testWidgets('a tier carrying neither renders no line at all', (
      WidgetTester tester,
    ) async {
      signInAsOwner();

      await mount(tester, _WebRailBillingService());

      expect(tester.takeException(), isNull);
      // The `silent` row's card is on screen (its name and its feature bullet
      // render), and it carries no highlight: three tiers, and only two AI
      // tiles between them.
      expect(find.text('Silent'), findsOneWidget);
      expect(find.text('Nothing to say about it'), findsOneWidget);
      expect(find.byIcon(Icons.auto_awesome), findsNWidgets(2));
      expect(find.text(_responderAddOn), findsOneWidget);
    });
  });

  group('the usage meters carry uptizm words and uptizm digits', () {
    /// Two resources this app names and one it does not, in the order the
    /// producer sent them.
    const List<UsageStat> usage = <UsageStat>[
      UsageStat(key: 'monitors', used: 7, limit: 50),
      UsageStat(key: 'checks_this_month', used: 83365, limit: 100000),
      UsageStat(key: 'widgets_provisioned', used: 3, limit: 10),
    ];

    testWidgets('a named resource renders its word, an unnamed one renders no '
        'meter', (WidgetTester tester) async {
      signInAsOwner();

      await mount(tester, _WebRailBillingService(usage: usage));

      expect(tester.takeException(), isNull);
      expect(find.text('Monitors'), findsOneWidget);
      expect(find.text('Checks this month'), findsOneWidget);
      // NOT its wire key: a meter labelled `widgets_provisioned` is a raw key
      // on a customer's screen, so the grid skips a stat the copy cannot name.
      expect(find.textContaining('widgets_provisioned'), findsNothing);
      // The English separator, through `formatCount` rather than a hardcoded
      // comma inside the component.
      expect(find.text('83,365 checks / 100,000 checks'), findsOneWidget);
    });

    testWidgets('a Turkish session reads 83.365, not 83,365', (
      WidgetTester tester,
    ) async {
      // The defect this repo has already shipped once. The separator is locale
      // DATA and it comes from the catalogue, so a formatter that travelled
      // into the package would have carried an English comma onto a fully
      // Turkish billing page.
      Translator.instance.setLoader(const _BundledLangLoader('tr'));
      await Translator.instance.setLocale(const Locale('tr'));
      signInAsOwner();

      await mount(tester, _WebRailBillingService(usage: usage));

      expect(tester.takeException(), isNull);
      expect(find.text('İzleyiciler'), findsOneWidget);
      expect(
        find.text('83.365 kontrol / 100.000 kontrol'),
        findsOneWidget,
        reason: 'the thousands separator follows the active catalogue',
      );
    });
  });

  group('the configured web origin makes checkout reachable', () {
    testWidgets('the shipped default is the APP host, and the CTA renders', (
      WidgetTester tester,
    ) async {
      // The default `CHECKOUT_WEB_ORIGIN` falls back to, since `flutter test`
      // loads no `.env`.
      //
      // It used to be `https://uptizm.com`, asserted here as "the shipped
      // origin", and that was the marketing site: production serves the landing
      // page and `/s/{slug}` status pages there and the Flutter build at
      // `app.uptizm.com`, so there is no `/teams/billing` on that host. A
      // customer completing a checkout would have been returned to a 404. This
      // assertion pinned it as correct.
      //
      // Named rather than merely non-empty, because the negative control below
      // only proves the CTA is gated on the key being SET, and an origin can be
      // set, absolute, and still point at the wrong host.
      expect(MagicStarterConfig.billingWebOrigin(), 'https://app.uptizm.com');

      signInAsOwner();

      await mount(tester, _WebRailBillingService());

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('magic_starter.billing.plan_button_upgrade')),
        findsWidgets,
      );
    });

    testWidgets('and an unset origin would hide it, so the case above is a '
        'gate rather than an always-on button', (WidgetTester tester) async {
      // The negative control. Without it the assertion above passes on a
      // screen that renders the CTA regardless, which is exactly the state
      // this config key exists to prevent: Stripe needs an absolute url, and
      // the refusal for a relative one reaches the log rather than the
      // customer.
      Config.set('magic_starter.billing.web_origin', '');
      signInAsOwner();

      await mount(tester, _WebRailBillingService());

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('magic_starter.billing.plan_button_upgrade')),
        findsNothing,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // The tap half of a page
  // ---------------------------------------------------------------------------
  //
  // A push that wakes an on-call responder is only half a page: the other half
  // is what the tap does. The backend puts the incident's path in `deep_link`
  // and the incident's owner in `team_id`, and nothing in this app read either
  // one, so a tap opened the app wherever it happened to be. The team half is
  // the sharper defect: `IncidentController::authorizeTeam` answers 404 for an
  // incident outside the caller's `current_team_id`, and the on-call rota is
  // team-scoped with no relation to that column, so a responder paged for team
  // A while sitting on team B would have landed on an error during an outage.
  // ---------------------------------------------------------------------------
  // The device tells the server whether it can be paged
  // ---------------------------------------------------------------------------

  group('the device reports whether a push can reach it', () {
    /// The endpoint the report is posted to, relative to `API_URL`, which
    /// already ends in `/api/v1`.
    const String path = 'devices/push-state';

    /// Registers a driver and leaves the manager and the app's own reporting
    /// state as they were found.
    ///
    /// Registered inside the test BODY rather than in `setUp` for the reason
    /// the tap group gives: the app subscribes to the driver's streams, and a
    /// subscription created outside the test's own zone delivers its events
    /// where no amount of pumping reaches them.
    _ReportingPushDriver useDriver() {
      final _ReportingPushDriver driver = _ReportingPushDriver();
      Notify.manager.setPushDriver(driver);
      addTearDown(AppServiceProvider.resetPushDeliveryReporting);
      addTearDown(Notify.forgetDrivers);
      addTearDown(driver.dispose);

      return driver;
    }

    /// The endpoint a sign-out releases this device through.
    const String releasePath = 'devices/push-state/release';

    /// Every report this device has posted, in order.
    ///
    /// Matched on the END of the url rather than anywhere in it, because the
    /// release path below extends this one and is a different verb entirely.
    List<Map<String, dynamic>> reports(FakeNetworkDriver network) {
      return network.recorded
          .where((entry) => entry.$1.url.endsWith(path))
          .map((entry) => entry.$1.data as Map<String, dynamic>)
          .toList();
    }

    /// Every release this device has posted, in order.
    List<Map<String, dynamic>> releases(FakeNetworkDriver network) {
      return network.recorded
          .where((entry) => entry.$1.url.endsWith(releasePath))
          .map((entry) => entry.$1.data as Map<String, dynamic>)
          .toList();
    }

    /// Signs a user in and lets the identity sync settle, which is the moment
    /// the app has both an identity to report as and a device to read.
    Future<void> declareIdentity() async {
      Auth.fake(
        user: User.fromMap(<String, dynamic>{'id': 'u1', 'name': 'Ada'}),
      );
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();
    }

    tearDown(Notify.forgetDrivers);

    test('a signed-in device posts the package shape, not a second one', () async {
      // The payload is `PushDeliverySnapshot.toMap()` verbatim. Inventing a
      // second spelling of the same fact is how the two halves of a contract
      // start to drift, and the package owns this one precisely so they cannot.
      final FakeNetworkDriver network = Http.fake();
      useDriver();

      await declareIdentity();

      expect(reports(network), hasLength(1));
      final Map<String, dynamic> body = reports(network).single;
      expect(body.keys.toSet(), <String>{
        'external_id',
        'subscription_id',
        'reachability',
        'captured_at',
      });
      expect(body['reachability'], 'on');
      expect(body['external_id'], 'user_u1');
      expect(body['subscription_id'], 'sub-phone');
      expect(DateTime.tryParse(body['captured_at'] as String), isNotNull);
    });

    test('a launch whose driver arrives late is not left reporting unavailable',
        () async {
      // The app's own boot order, reproduced. `lib/config/app.dart` boots this
      // provider (45), then `AuthServiceProvider` (46), then
      // `NotificationServiceProvider` (48), and 48 is the only place a push
      // driver is ever resolved. Provider 46's boot awaits `Auth.restore()`,
      // which bumps `Auth.stateNotifier`, so on every cold boot that restores a
      // stored session (the ordinary launch for a signed-in operator) the
      // identity is declared, and the report chained behind it made, before any
      // driver exists. That is the provider order, not a race, and no fixture
      // in either repository could express it: every one of them installed a
      // driver first.
      final FakeNetworkDriver network = Http.fake();
      AppServiceProvider.syncPushIdentityWhenDriverArrives();
      addTearDown(AppServiceProvider.resetPushDeliveryReporting);
      addTearDown(Notify.forgetDrivers);

      await declareIdentity();

      // What the device can honestly say with nothing to ask. It is posted
      // rather than withheld because the alternative leaves whatever the server
      // already held standing, and on a device that has since lost its driver
      // that is a stale `on` promising a page nobody receives.
      expect(reports(network).single['reachability'], 'unavailable');

      // ... and now the notifications provider boots and resolves a driver.
      final _ReportingPushDriver driver = _ReportingPushDriver();
      addTearDown(driver.dispose);
      Notify.manager.setPushDriver(driver);
      await pumpEventQueue();

      // Uncorrected, the server holds `unavailable` for this responder for the
      // whole session: the only re-entries are `syncPushIdentity` and two
      // driver streams nothing ever attached. Every escalation rung that
      // reaches only them is then recorded as having reached nobody, while
      // their phone would in fact have rung.
      expect(reports(network), hasLength(2));
      expect(reports(network).last['reachability'], 'on');
      // The identity half of the same correction: the report describes the
      // device AS THIS PERSON, and on that launch nothing had logged it in yet,
      // so a report that only re-read the device would post a subscription
      // subscribed as nobody, which the server refuses just as flatly.
      expect(reports(network).last['external_id'], 'user_u1');
      expect(reports(network).last['subscription_id'], 'sub-phone');
    });

    test('boot arms the late-driver report', () {
      // The seam above is correct code that does nothing unless [boot] reaches
      // it, and a widget test cannot boot this provider: its boot wires Sentry,
      // the starter bootstrap and six auth listeners. A source read is what is
      // left, and it earns its line, because this repo has already shipped a
      // write path whose only defect was that nothing called it.
      final String source = File(
        'lib/app/providers/app_service_provider.dart',
      ).readAsStringSync();

      expect(source, contains('syncPushIdentityWhenDriverArrives();'));
    });

    test('a device whose state has not moved does not post again', () async {
      // `Auth.stateNotifier` bumps on every restore, every token refresh and
      // every team switch, and none of those change what the device can
      // receive. Posting on each of them would be a timer with extra steps.
      final FakeNetworkDriver network = Http.fake();
      useDriver();

      await declareIdentity();
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(reports(network), hasLength(1));
    });

    test('a revoked permission reports itself with nobody asking', () async {
      // The case the whole feature exists for: the responder turns
      // notifications off in system settings while the app is open, and the
      // server has to learn it BEFORE the next outage rather than by sending a
      // page into the dark.
      final FakeNetworkDriver network = Http.fake();
      final _ReportingPushDriver driver = useDriver();
      await declareIdentity();

      driver.changePermission(PushPermissionState.denied);
      await pumpEventQueue();

      expect(reports(network), hasLength(2));
      expect(reports(network).last['reachability'], 'blocked');
    });

    test('a swapped subscription reports itself', () async {
      // A re-registration mints a new subscription id, and the old one stops
      // being deliverable. Without this the server keeps a row vouching for an
      // address that no longer exists.
      final FakeNetworkDriver network = Http.fake();
      final _ReportingPushDriver driver = useDriver();
      await declareIdentity();

      driver.changeSubscription('sub-reinstalled');
      await pumpEventQueue();

      expect(reports(network), hasLength(2));
      expect(reports(network).last['subscription_id'], 'sub-reinstalled');
    });

    test('the reporter creates no periodic timer', () {
      // The explicit non-requirement. A poll would put one write per device per
      // interval on a monitoring backend for a fact that changes a handful of
      // times a year, and the server's staleness horizon is sized for a client
      // that reports on lifecycle events instead.
      //
      // This is a STRUCTURAL assertion, and deliberately so. Proving the
      // absence of a timer by simulating time does not work here: advancing a
      // clock needs `testWidgets`, whose fake-async zone never drains the real
      // event queue that `pumpEventQueue()` (and therefore every helper in this
      // group) waits on, so the behavioural version of this test hangs until
      // the ten minute timeout rather than failing or passing. What it would
      // have measured is covered anyway: every test above declares one
      // lifecycle event and asserts exactly one report, so a second report
      // arriving from anywhere else would already be visible there.
      //
      // What is left for this test is the thing a future edit would actually
      // write, and that is worth catching cheaply.
      // Only `Timer.periodic` is asserted against. `startPolling` appears in
      // this file's prose and is not a violation: that is the notification
      // FEED poller, which polls a backend for rows a human reads, and it is
      // lifecycle-bound on purpose. The thing forbidden here is polling the
      // DEVICE for a state that changes when the OS says so.
      final String source = File(
        'lib/app/providers/app_service_provider.dart',
      ).readAsStringSync();

      expect(source, isNot(contains('Timer.periodic')));
    });

    test('a signed-out device reports nothing', () async {
      // There is no session to attribute the report to, and the endpoint is
      // behind `auth:sanctum`, so posting here would be a guaranteed 401 on
      // every sign-out.
      final FakeNetworkDriver network = Http.fake();
      useDriver();
      await declareIdentity();

      await Auth.logout();
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(reports(network), hasLength(1));
    });

    test('a sign-out names this device, and nothing else about it', () async {
      // The defect this closes: nothing was posted for the sign-out transition
      // at all, so `PushDevice::canReachByPush()` kept answering true under the
      // previous person's alias for a day, and every escalation rung whose only
      // outward channel is push was recorded as having woken somebody.
      final FakeNetworkDriver network = Http.fake();
      useDriver();
      await declareIdentity();

      await AppServiceProvider.releasePushDevice();

      // The subscription id and nothing else: the person comes from the SESSION
      // on the server side, so a body naming one would be a second, weaker
      // answer to a question the token already settles.
      expect(releases(network), hasLength(1));
      expect(releases(network).single, <String, dynamic>{
        'subscription_id': 'sub-phone',
      });
    });

    test('a sign-out with no device to name posts nothing', () async {
      // A build with no push at all (web, desktop) reports `unavailable` with no
      // subscription id and has therefore never vouched for anybody: a row
      // without one fails `canReachByPush()` on its own, so there is nothing for
      // a release to remove and no request worth spending on the way out.
      final FakeNetworkDriver network = Http.fake();
      AppServiceProvider.syncPushIdentityWhenDriverArrives();
      addTearDown(AppServiceProvider.resetPushDeliveryReporting);
      await declareIdentity();

      await AppServiceProvider.releasePushDevice();

      expect(releases(network), isEmpty);
    });

    test('a driver that has stopped answering still releases its row', () async {
      // The row the server holds was created by the last ACCEPTED report, so
      // that report is what names it. A device whose driver has since gone
      // quiet reads no subscription id, and naming nothing there would leave
      // this handset's own stale `on` standing for the rest of the window,
      // which is the exact defect being fixed.
      final FakeNetworkDriver network = Http.fake();
      final _ReportingPushDriver driver = useDriver();
      await declareIdentity();

      // Assigned rather than announced through `changeSubscription`, which would
      // fire a report of its own and describe a different case.
      driver.subscriptionId = null;

      await AppServiceProvider.releasePushDevice();

      expect(releases(network).single['subscription_id'], 'sub-phone');
    });

    test('a signed-out session releases nothing', () async {
      // The guard `reportPushDeliveryState` carries, for the same reason: there
      // is no token left, so this would be a guaranteed 401. It is also why the
      // release cannot live on the auth-state listener at all.
      final FakeNetworkDriver network = Http.fake();
      useDriver();
      await declareIdentity();
      await Auth.logout();

      await AppServiceProvider.releasePushDevice();

      expect(releases(network), isEmpty);
    });

    test('a released device is reported from scratch, not remembered',
        () async {
      // The memo says what the SERVER holds, and after an accepted release it
      // holds nothing for this device. Left standing, the next person signing in
      // on this handset with an identical device state would never be reported
      // at all, and the server would have no row for a phone that rings.
      final FakeNetworkDriver network = Http.fake();
      useDriver();
      await declareIdentity();

      await AppServiceProvider.releasePushDevice();
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(reports(network), hasLength(2));
      expect(reports(network).last['subscription_id'], 'sub-phone');
    });

    test('a refused release leaves the memo describing what the server has',
        () async {
      // The other side of the same rule. A refused release changed nothing on
      // the server, so forgetting the report it still holds would make the next
      // identical report a second write of one fact. What is left is a stale
      // `on` bounded by the server's freshness horizon, which is the one shape
      // this device cannot distinguish itself from: a client that tried to
      // speak and was not heard looks exactly like one that went quiet.
      final FakeNetworkDriver network = Http.fake(<String, MagicResponse>{
        releasePath: Http.response(<String, dynamic>{'message': 'nope'}, 500),
      });
      useDriver();
      await declareIdentity();

      await AppServiceProvider.releasePushDevice();
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(releases(network), hasLength(1));
      expect(reports(network), hasLength(1));
    });

    test('the sign-out path releases this device before it drops the token',
        () {
      // The seam, and it is the whole reason this defect existed: every other
      // push write in this file hangs off `Auth.stateNotifier`, and that
      // listener structurally CANNOT do this one. `reportPushDeliveryState`
      // returns early on a signed-out session (rightly: the endpoint is behind
      // `auth:sanctum`), and by the time the notifier bumps, `Auth.logout()` has
      // already dropped the token. The only place with a live session and the
      // knowledge that it is ending is the `onLogout` handler, and until this
      // call landed there, nothing anywhere told the server: the row kept its
      // `on` and the previous person's alias for the whole freshness window,
      // and every escalation rung whose only outward channel is push was
      // recorded as having woken somebody.
      //
      // Asserted off the source for the reason the late-driver test gives: a
      // widget test cannot boot this provider, and this repo has already
      // shipped a write path whose only defect was that nothing called it. The
      // ORDER is asserted too, because a release posted after the token is gone
      // is a guaranteed 401.
      final String source = File(
        'lib/app/providers/app_service_provider.dart',
      ).readAsStringSync();

      final int release = source.indexOf('releasePushDevice()');
      final int logout = source.indexOf('await Auth.logout();');

      expect(release, isNot(-1));
      expect(logout, isNot(-1));
      expect(release, lessThan(logout));
    });

    test('a report the server refused is made again, not remembered', () async {
      // The memo exists to stop repeat posts of a state the server ALREADY
      // HAS. A refused post left nothing on the server, so remembering it would
      // mean one failed request silences this device until the state changes
      // again, which on a device that is off is never.
      final FakeNetworkDriver network = Http.fake(<String, MagicResponse>{
        path: Http.response(<String, dynamic>{'message': 'nope'}, 500),
      });
      useDriver();

      await declareIdentity();
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(reports(network), hasLength(2));
    });

    test('the next person on a shared device reports for themselves', () async {
      // The memo is per process, and a sign-out clears it. Without that, a
      // second person signing in on the same handset whose device state happens
      // to match would never be reported at all, and the server would hold a
      // reachable device attributed to the person who left.
      final FakeNetworkDriver network = Http.fake();
      useDriver();
      await declareIdentity();

      await Auth.logout();
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      Auth.fake(
        user: User.fromMap(<String, dynamic>{'id': 'u2', 'name': 'Grace'}),
      );
      AppServiceProvider.syncPushIdentity();
      await pumpEventQueue();

      expect(reports(network), hasLength(2));
      expect(reports(network).last['external_id'], 'user_u2');
    });
  });

  group('a tapped push opens the incident it names', () {
    /// The route table the tap navigates inside: a landing page plus the
    /// incident detail route the deep link resolves to.
    void registerRoutes() {
      MagicRouter.reset();
      MagicRoute.page('/', () => const SizedBox());
      MagicRoute.page('/incidents/:id', () => const SizedBox());
    }

    /// Mounts the router so `MagicRoute.to` has somewhere to go, under a
    /// [WindTheme] because the cross-team toast renders into the navigator's
    /// own overlay and its W-widgets resolve their tokens from an ancestor.
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

    /// Registers the driver a tap arrives on, and subscribes the app's real tap
    /// handler to it.
    ///
    /// Both happen inside the test BODY rather than in `setUp`, because the
    /// manager attaches to the driver's stream at registration and a
    /// subscription created outside the tester's fake-async zone delivers its
    /// events on the real event loop, where no amount of pumping reaches them.
    /// The teardown leaves the manager as it was found, so one test's
    /// subscription cannot fire inside the next one's.
    _TappablePushDriver listen() {
      final _TappablePushDriver driver = _TappablePushDriver();
      Notify.manager.setPushDriver(driver);
      AppServiceProvider.listenForPushTaps();
      addTearDown(AppServiceProvider.stopListeningForPushTaps);

      return driver;
    }

    /// Lets the tap travel the stream, the switch and the navigation, then
    /// drains the toast's auto-dismiss timer so it does not outlive the test.
    Future<void> settleTap(WidgetTester tester) async {
      await tester.pumpAndSettle();
      await tester.pump(const Duration(seconds: 5));
    }

    setUp(registerRoutes);

    tearDown(() {
      Notify.forgetDrivers();
      MagicRouter.reset();
    });

    testWidgets('a tap on this team incident lands on the incident', (
      WidgetTester tester,
    ) async {
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);
      final _TappablePushDriver driver = listen();

      driver.tap(<String, dynamic>{
        'type': 'incident_opened',
        'incident_id': 'inc-1',
        'kind': 'incident',
        'team_id': 't1',
        'deep_link': '/incidents/inc-1',
      });
      await settleTap(tester);

      expect(tester.takeException(), isNull);
      expect(MagicRouter.instance.currentPath, '/incidents/inc-1');
    });

    testWidgets('a payload naming no team navigates rather than refusing', (
      WidgetTester tester,
    ) async {
      // The same principle the manager applies to `subject`: an absent field is
      // not evidence of misaddressing, so a server older than the `team_id` key
      // must not leave the responder on whatever screen the app was showing.
      final FakeNetworkDriver network = Http.fake();
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);
      final _TappablePushDriver driver = listen();

      driver.tap(<String, dynamic>{
        'type': 'incident_opened',
        'incident_id': 'inc-2',
        'kind': 'incident',
        'deep_link': '/incidents/inc-2',
      });
      await settleTap(tester);

      expect(tester.takeException(), isNull);
      expect(MagicRouter.instance.currentPath, '/incidents/inc-2');
      network.assertNotSent(
        (MagicRequest request) => request.url.contains('user/current-team'),
      );
    });

    testWidgets('a deep link carrying a host is refused, not navigated', (
      WidgetTester tester,
    ) async {
      // The guard's own comment says a push "can also be composed by hand in
      // the OneSignal dashboard, and handing an arbitrary string to the router
      // is not a navigation this app should perform". `startsWith('/')` alone
      // does not say that: `//evil.example/incidents/inc-9` passes it while
      // parsing to a URI whose authority is somebody else's host, and
      // `MagicRoute.to` hands its argument straight to `GoRouter.go` with no
      // sanitising of its own.
      final FakeLogManager log = Log.fake();
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);
      final _TappablePushDriver driver = listen();

      final String? before = MagicRouter.instance.currentPath;

      driver.tap(<String, dynamic>{
        'type': 'incident_opened',
        'incident_id': 'inc-9',
        'kind': 'incident',
        'team_id': 't1',
        'deep_link': '//evil.example/incidents/inc-9',
      });
      await settleTap(tester);

      expect(tester.takeException(), isNull);
      expect(
        MagicRouter.instance.currentPath,
        before,
        reason: 'the tap moved the app nowhere',
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

    testWidgets('a tap arriving while the local team is unresolved navigates '
        'without attempting a switch', (WidgetTester tester) async {
      // The local mirror of the absent-payload-team case above: a restored
      // session whose `currentTeam` has not resolved yet must not read as a
      // mismatch just because the empty-string fallback never equals a real
      // owner id.
      final FakeNetworkDriver network = Http.fake();
      Auth.fake(
        user: User.fromMap(<String, dynamic>{'id': 'u1', 'name': 'Ada'}),
      );
      await mountRouter(tester);
      final _TappablePushDriver driver = listen();

      driver.tap(<String, dynamic>{
        'type': 'incident_opened',
        'incident_id': 'inc-6',
        'kind': 'incident',
        'team_id': 't9',
        'deep_link': '/incidents/inc-6',
      });
      await settleTap(tester);

      expect(tester.takeException(), isNull);
      expect(MagicRouter.instance.currentPath, '/incidents/inc-6');
      network.assertNotSent(
        (MagicRequest request) => request.url.contains('user/current-team'),
      );
    });

    testWidgets('a tap for another team switches to it, then lands on the '
        'incident', (WidgetTester tester) async {
      // The 404 case. The rota that paged this responder is team-scoped and
      // says nothing about the team they are currently on, so without the
      // switch the tap reaches `authorizeTeam`'s deliberate 404 for the
      // incident they were just woken up for.
      final FakeNetworkDriver network = Http.fake();
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);
      final _TappablePushDriver driver = listen();

      driver.tap(<String, dynamic>{
        'type': 'incident_opened',
        'incident_id': 'inc-3',
        'kind': 'incident',
        'team_id': 't9',
        'deep_link': '/incidents/inc-3',
      });
      await settleTap(tester);

      expect(tester.takeException(), isNull);
      network.assertSent(
        (MagicRequest request) =>
            request.url.contains('user/current-team') &&
            (request.data as Map<String, dynamic>?)?['team_id'] == 't9',
      );
      expect(MagicRouter.instance.currentPath, '/incidents/inc-3');
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
      final _TappablePushDriver driver = listen();

      driver.tap(<String, dynamic>{
        'type': 'incident_opened',
        'incident_id': 'inc-4',
        'kind': 'incident',
        'team_id': 't9',
        'deep_link': '/incidents/inc-4',
      });
      await settleTap(tester);

      expect(tester.takeException(), isNull);
      expect(MagicRouter.instance.currentPath, '/');
    });

    testWidgets('a second boot replaces the subscription instead of stacking a '
        'second one', (WidgetTester tester) async {
      // Counted on the refusal branch because it is the one arm that produces
      // exactly one observable per handler run. Every other arm coalesces: two
      // concurrent switches are one request (`_isSubmitting`), and two `go()`
      // calls to one location are one navigation, so a leaked subscription
      // would hide behind either of them.
      final FakeLogManager log = Log.fake();
      signIn(<String, dynamic>{'id': 't1', 'name': 'Alpha'});
      await mountRouter(tester);

      final _TappablePushDriver driver = listen();
      AppServiceProvider.listenForPushTaps();

      driver.tap(<String, dynamic>{
        'type': 'incident_opened',
        'incident_id': 'inc-5',
        'kind': 'incident',
      });
      await settleTap(tester);

      expect(
        log.entries
            .where(
              (FakeLogEntry entry) =>
                  entry.message.contains('[AppServiceProvider] push tap'),
            )
            .length,
        1,
        reason: 'one tap reaches the handler once, however often the app boots',
      );
    });
  });
}
