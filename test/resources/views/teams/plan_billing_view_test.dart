import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:magic_payments/magic_payments.dart'
    show
        BillingCheckoutSession,
        BillingEntitlement,
        BillingException,
        BillingInvoicesPage,
        BillingService,
        Invoice,
        InvoiceStatus,
        PaymentMethod,
        Payments,
        PaymentsManager,
        StoreBillingService,
        UsageStat,
        WebBillingService;
import 'package:uptizm/app/mocks/billing.dart' show plans;
import 'package:uptizm/app/mocks/teams_data.dart' show planWireRows;
import 'package:uptizm/app/models/user.dart' show User;
import 'package:uptizm/app/providers/app_service_provider.dart';
import 'package:uptizm/resources/views/teams/plan_billing_view.dart';

import '../../../support/bundled_lang.dart';

/// The five entitlement READS, with a configurable rail and no purchase rail at
/// all: the base every fake below extends.
///
/// It is split from the two rail fakes because which rails a fake serves is the
/// subject of half this file. The screen resolves the web rail and the store
/// rail from the INJECTED object's own type (`_resolveWebRail` and
/// `_resolveStoreRail` on the view's state), so a single fake implementing both
/// would model a build that cannot exist (`dart.library.io` has no web checkout
/// and the web arm has no store) and would let a store-rail test pass on a web
/// affordance.
///
/// The rail arrives as its RAW WIRE WORD (`app_store`, not `ManageVia`
/// .appStore), because that is the only thing the real decoder ever sees; a
/// fake taking an already-decoded case could pass while
/// `ManageVia.fromWire('play_store')` silently fell into its fallback. The
/// entitlement is therefore built through [BillingEntitlement.fromMap] rather
/// than through the const constructor, which takes cases already decoded.
class _ReadsBillingService implements BillingService {
  _ReadsBillingService({
    this.manageVia = 'none',
    this.manageUrl,
    this.invoices = const [],
    this.usage = const [],
  });

  /// The wire word for `manage_via`.
  final String manageVia;

  /// The wire value for `manage_url`; `null` models a store rail whose
  /// management destination has not arrived.
  final String? manageUrl;

  /// The wire value for `plan`, fixed rather than injectable: every assertion
  /// in this file is about the rail or the membership, and the tier only has to
  /// be a real catalogue id (one with a cheaper and a pricier neighbour) so the
  /// grid resolves an Upgrade, a Downgrade and a Current-plan CTA.
  ///
  /// A getter rather than a field, so [_HeldRetiredTierBillingService] can
  /// override it without the `overridden_fields` lint a second `final` field
  /// would trigger.
  String get entitlementPlan => 'pro';

  /// The billing history `GET /billing/invoices` resolves to.
  final List<Invoice> invoices;

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    return BillingEntitlement.fromMap(<String, dynamic>{
      'plan': entitlementPlan,
      'plan_status': 'active',
      'subscribed': true,
      'renews': true,
      'provider': 'stripe',
      'manage_via': manageVia,
      'manage_url': manageUrl,
      'ai_analysis_trials_remaining': null,
    });
  }

  @override
  Future<List<Map<String, dynamic>>> getPlans() async => planWireRows(plans);

  /// The metered usage `GET /billing/usage` resolves to, LABEL-FREE, exactly as
  /// the package decodes it: pairing the display copy on is the screen's job, and
  /// a fixture that pre-labelled these would assert its own words instead.
  final List<UsageStat> usage;

  @override
  Future<List<UsageStat>> getUsage() async => usage;

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) async {
    return BillingInvoicesPage(invoices: invoices, nextCursor: null);
  }

  /// The card and the renewal date as the RAIL reports them: two numbers for the
  /// expiry and an instant for the renewal, not `'08 / 27'` and
  /// `'Jun 1, 2026'`. Formatting both is the screen's job now, so a fixture that
  /// handed over finished strings would be asserting the fixture's own
  /// formatting rather than the product's.
  @override
  Future<PaymentMethod> getPaymentMethod() async {
    return PaymentMethod(
      brand: 'Visa',
      last4: '4242',
      expMonth: 8,
      expYear: 2027,
      renewalDate: DateTime.utc(2026, 6, 1),
    );
  }
}

/// A tier with no rail behind it: every read answers, and every answer is
/// "nothing".
///
/// The two payloads below are copied off `GET /billing` and
/// `GET /billing/payment-method` on the dev box, for a team holding
/// `plan = business` with `plan_provider` null (a tier granted directly rather
/// than sold). They are not invented: the live walk in step 32 is what produced
/// them.
///
/// [_ReadsBillingService] cannot model this state, and that is why it went
/// unnoticed. Its `getPaymentMethod()` pins a Visa ending 4242 with a real
/// renewal instant, so every other test in this file reaches the renewal
/// sentence through a date that EXISTS, and the branch taken when the read
/// resolves EMPTY had no fixture pointing at it. A fixture that pins one value
/// makes the other branch unreachable, and an unreachable branch is not covered
/// by however many tests pass.
class _UnbilledBillingService extends _ReadsBillingService {
  @override
  Future<BillingEntitlement> currentEntitlement() async {
    return BillingEntitlement.fromMap(<String, dynamic>{
      'plan': 'business',
      'plan_status': 'none',
      'subscribed': false,
      'renews': null,
      'provider': 'none',
      'manage_via': 'none',
      'manage_url': null,
      'current_period_end': null,
      'ai_analysis_trials_remaining': null,
    });
  }

  /// Resolved, and carrying nothing. Distinct from the fetch never having
  /// answered: the view keeps `_paymentMethod` null for that, and the neutral
  /// pending label is correct there.
  @override
  Future<PaymentMethod> getPaymentMethod() async => const PaymentMethod();
}

/// A PAYING Stripe customer whose payment-method read soft-failed.
///
/// `manage_via` is `portal`, which the server sends exactly when a Stripe
/// customer exists, and the payment method resolves EMPTY. That pair is not
/// exotic: `BillingController::paymentMethod()` catches every Throwable from its
/// live Stripe reads and answers 200 with all five fields null, which is
/// byte-identical to the body a team with no rail receives. So a timeout at
/// Stripe puts a real subscriber into exactly this state.
///
/// It exists because the first version of the "no rail behind this tier" copy
/// keyed on the empty read alone and told this customer they had no subscription
/// and no card.
class _SoftFailedPaymentBillingService extends _ReadsBillingService {
  _SoftFailedPaymentBillingService() : super(manageVia: 'portal');

  @override
  Future<PaymentMethod> getPaymentMethod() async => const PaymentMethod();
}

/// A team grandfathered on a tier the current catalogue no longer serves.
///
/// `_ReadsBillingService.entitlementPlan` is fixed to `'pro'`, a real
/// catalogue id, because every other test in this file needs a tier with both
/// a cheaper and a pricier neighbour so the grid resolves an Upgrade AND a
/// Downgrade CTA. This fixture overrides just that one field with an id
/// `plans` (`app/mocks/billing.dart`) never carries, modelling a customer
/// whose tier the backend retired: `_planIndex`/`_findPlan` must answer
/// absence rather than the catalogue's cheapest entry.
///
/// Extends [_RailBillingService] (a WEB rail) rather than the bare reads,
/// because a build with no rail at all renders no CTA on a priced tier
/// regardless of direction, which would make the neutral-label assertion
/// below pass for the wrong reason.
class _HeldRetiredTierBillingService extends _RailBillingService {
  @override
  String get entitlementPlan => 'legacy_grandfathered';
}

/// The payment-method read answers EMPTY while the entitlement read never does.
///
/// Both are dispatched together on mount, and for a customer-less team the
/// payment-method one is the cheaper (Cashier's `defaultPaymentMethod()`
/// short-circuits on `hasStripeId()`), so this ordering is ordinary rather than
/// contrived. A failing entitlement read makes it permanent.
///
/// In that window neither sentence is available: "no card on file" needs to know
/// there is no rail, and "the read failed" needs to know one failed.
class _UnresolvedRailBillingService extends _ReadsBillingService {
  @override
  Future<BillingEntitlement> currentEntitlement() async {
    throw const BillingException('Failed to load the billing entitlement.');
  }

  @override
  Future<PaymentMethod> getPaymentMethod() async => const PaymentMethod();
}

/// The reads PLUS the WEB rail, recording every purchase-affecting call so a
/// test can assert an affordance was not merely hidden but never reachable.
///
/// `checkout` and `openPortal` live on [WebBillingService] rather than on the
/// read contract, and the screen renders no purchase or portal affordance at all
/// when that rail is absent, so a read-only fake would have made every "the
/// affordance is gone" assertion below pass for the wrong reason.
class _RailBillingService extends _ReadsBillingService
    implements WebBillingService {
  _RailBillingService({
    super.manageVia = 'none',
    super.manageUrl,
    super.invoices = const [],
    super.usage = const [],
  });

  /// Every `plan` passed to [checkout], in call order.
  final List<String> checkoutPlans = [];

  /// How many times [openPortal] was called.
  int portalCalls = 0;

  @override
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required String successUrl,
    required String cancelUrl,
  }) async {
    checkoutPlans.add(plan);
    return const BillingCheckoutSession(
      checkoutUrl: 'https://checkout.stripe.com/test_session',
      sessionId: 'session_test',
    );
  }

  @override
  Future<void> swap({required String plan}) async {}

  @override
  Future<void> cancel() async {}

  @override
  Future<String> openPortal({String? returnUrl}) async {
    portalCalls++;
    return 'https://billing.stripe.com/session/test';
  }
}

/// The reads PLUS the STORE rail, recording every call the store rail can
/// receive.
///
/// It deliberately does NOT implement [WebBillingService]: a build with a store
/// has no web checkout (`billing_service_io.dart` answers `null` for it), so a
/// fake serving both would let a store test pass on the web CTA and would make
/// "a store build never offers web checkout" unfalsifiable.
class _StoreRailBillingService extends _ReadsBillingService
    implements StoreBillingService {
  _StoreRailBillingService({
    super.manageVia = 'none',
    this.purchaseResult = true,
    this.purchaseError,
    this.restoreResult = true,
  });

  /// What the store reports for a completed sheet: `true` is a transaction,
  /// `false` is the customer dismissing it (which is not a failure).
  final bool purchaseResult;

  /// A rail failure to raise instead of answering, so the error paths are
  /// reachable without the platform channel.
  final BillingException? purchaseError;

  /// Whether the store hands a previous purchase back to [restore].
  final bool restoreResult;

  /// Every `appUserId` passed to [identify], in call order.
  final List<String> identifiedIds = [];

  /// Every `plan` passed to [purchase], in call order.
  final List<String> purchasedPlans = [];

  /// How many times [restore] was called.
  int restoreCalls = 0;

  @override
  Future<void> identify(String appUserId) async {
    identifiedIds.add(appUserId);
  }

  @override
  Future<bool> purchase({required String plan}) async {
    purchasedPlans.add(plan);
    final BillingException? error = purchaseError;
    if (error != null) throw error;

    return purchaseResult;
  }

  @override
  Future<bool> restore() async {
    restoreCalls++;
    return restoreResult;
  }

  @override
  Future<void> openStoreManagement() async {}
}

/// Feeds the SHIPPED catalogue for [locale] into the translator, so an
/// assertion about rendered copy is an assertion about the product rather than
/// about the test author's inline literal (this repo has shipped both an
/// ungrammatical Turkish string and a raw i18n key past a green suite).
class _BundledLangLoader implements TranslationLoader {
  const _BundledLangLoader(this.locale);

  /// The catalogue to serve, regardless of the locale the translator asks for.
  final String locale;

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}

void main() {
  /// The one invoice the billing-history assertions need, so the receipt
  /// affordance has a row to live on.
  ///
  /// `final`, not `const`: the date is an instant (month names and date order are
  /// display copy the screen owns), and a [DateTime] can never be a constant.
  final Invoice invoice = Invoice(
    id: 'in_test_1',
    number: 'INV-0001',
    date: DateTime.utc(2026, 6, 1),
    amount: '\$29.00',
    status: InvoiceStatus.paid,
  );

  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Card / Button / Badge / PageHeader resolve their themes through
    // MagicStarter.*, and MagicFeedback falls through to a warning log with no
    // mounted navigator, so both bindings are needed without a full app boot
    // (mirrors the harness in teams_views_test.dart).
    Magic.singleton('magic_starter', () => MagicStarterManager());
    Magic.singleton('log', () => LogManager());
    Http.fake();
    // Force-build the lazy GoRouter: the view reads
    // MagicRouter.instance.queryParameters for the `?upgrade=` deep link.
    MagicRouter.instance.routerConfig;

    Translator.instance.setLoader(const _BundledLangLoader('en'));
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] with a default [WindTheme] under a viewport tall enough for
  /// the whole screen, mirroring the harness in `teams_views_test.dart`.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: MediaQuery(
        data: const MediaQueryData(size: Size(1280, 12000)),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  /// Wraps [widget] so a [MagicFeedback] toast can actually render.
  ///
  /// [WindTheme] sits ABOVE the [MaterialApp] because the toast is inserted into
  /// the Navigator's overlay, which is a sibling of `home`: a Wind-built toast
  /// under `home` throws "No WindTheme found in context". Mirrors the harness in
  /// `teams_views_test.dart`; without it `MagicFeedback` degrades to a warning
  /// log and an assertion on the reported copy passes for nobody.
  Widget wrapWithSnackbar(Widget widget) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(
        navigatorKey: MagicRouter.instance.navigatorKey,
        home: MediaQuery(
          data: const MediaQueryData(size: Size(1280, 12000)),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  /// Mounts [view] and settles the mount-time reads.
  ///
  /// [withToasts] mounts the toast-capable harness instead, for the two tests
  /// whose subject is what the store rail reported back.
  Future<void> mount(
    WidgetTester tester,
    PlanBillingView view, {
    bool withToasts = false,
  }) async {
    await tester.binding.setSurfaceSize(const Size(1280, 12000));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(withToasts ? wrapWithSnackbar(view) : wrap(view));
    await tester.pump();
    await tester.pump();
  }

  /// Every string this build rendered, for the "nothing anywhere in the tree
  /// points at a web purchase" assertion. Reads the widget tree rather than a
  /// list of known labels: the point is to catch a URL nobody thought to look
  /// for, which a label-by-label check cannot do.
  List<String> renderedText(WidgetTester tester) {
    return tester
        .widgetList<Text>(find.byType(Text))
        .map((Text text) => text.data ?? '')
        .toList();
  }

  group('PlanBillingView manage_via: portal', () {
    testWidgets('keeps the three Stripe-portal affordances and the purchase '
        'CTA', (tester) async {
      final _RailBillingService billing = _RailBillingService(
        manageVia: 'portal',
        invoices: <Invoice>[invoice],
      );

      await mount(tester, PlanBillingView(billingService: billing));

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_payment_update_button')),
        findsOneWidget,
        reason: 'the portal rail keeps the payment-method Update button',
      );
      expect(
        find.text(trans('uptizm.teams.billing_invoice_receipt_button')),
        findsOneWidget,
        reason: 'the portal rail keeps the invoice Receipt button',
      );
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsOneWidget,
        reason: 'the portal rail can still buy a higher tier',
      );
      expect(
        find.text(trans('uptizm.teams.billing_manage_header')),
        findsNothing,
        reason: 'no store-managed statement on a rail we manage ourselves',
      );
    });
  });

  group('PlanBillingView manage_via: app_store', () {
    testWidgets('renders the App Store statement with its passed-through link, '
        'and no Stripe affordance anywhere', (tester) async {
      final _RailBillingService billing = _RailBillingService(
        manageVia: 'app_store',
        manageUrl: 'https://apps.apple.com/account/subscriptions',
        invoices: <Invoice>[invoice],
      );

      await mount(tester, PlanBillingView(billingService: billing));

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_manage_app_store_text')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.billing_manage_store_button')),
        findsOneWidget,
        reason: 'a non-null manage_url gets a tappable affordance',
      );
      expect(
        find.text(trans('uptizm.teams.billing_manage_play_store_text')),
        findsNothing,
      );

      // The three Stripe-portal affordances are gone, and so is the purchase
      // CTA: a second rail must not be able to start charging this team.
      expect(
        find.text(trans('uptizm.teams.billing_payment_update_button')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_invoice_receipt_button')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_downgrade')),
        findsNothing,
      );
      expect(billing.portalCalls, 0);
      expect(billing.checkoutPlans, isEmpty);

      // Nothing rendered points at a web purchase or the Stripe portal
      // (Apple's 3.1.3 steering rule), and the enterprise contact-sales CTA
      // still comes off the plan GRID rather than off the rail.
      for (final String text in renderedText(tester)) {
        expect(
          text.toLowerCase(),
          allOf(
            isNot(contains('stripe')),
            isNot(contains('checkout')),
            isNot(contains('uptizm.com')),
          ),
          reason: 'a store-billed screen must not steer to a web purchase',
        );
      }
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_contact')),
        findsOneWidget,
      );
    });

    testWidgets('renders the Turkish statement from the shipped catalogue', (
      tester,
    ) async {
      Translator.instance.setLoader(const _BundledLangLoader('tr'));
      await Translator.instance.setLocale(const Locale('tr'));

      final Map<String, dynamic> tr = readBundledLang('tr');
      final Object? statement =
          tr['uptizm.teams.billing_manage_app_store_text'];
      expect(
        statement,
        isA<String>(),
        reason: 'a missing tr key ships as a visible raw i18n key',
      );

      final _RailBillingService billing = _RailBillingService(
        manageVia: 'app_store',
        manageUrl: 'https://apps.apple.com/account/subscriptions',
      );

      await mount(tester, PlanBillingView(billingService: billing));

      expect(tester.takeException(), isNull);
      expect(find.text(statement! as String), findsOneWidget);
      expect(
        find.textContaining('billing_manage_'),
        findsNothing,
        reason: 'a raw i18n key must never reach the screen',
      );
    });
  });

  group('PlanBillingView manage_via: play_store', () {
    testWidgets('a null manage_url states where the subscription lives and '
        'offers no tappable affordance', (tester) async {
      final _RailBillingService billing = _RailBillingService(
        manageVia: 'play_store',
        invoices: <Invoice>[invoice],
      );

      await mount(tester, PlanBillingView(billingService: billing));

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_manage_play_store_text')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.billing_manage_store_no_url')),
        findsOneWidget,
        reason: 'the statement explains where to go instead of a dead button',
      );
      expect(
        find.text(trans('uptizm.teams.billing_manage_store_button')),
        findsNothing,
        reason: 'a null manage_url renders no button at all, disabled or not',
      );
      expect(
        find.text(trans('uptizm.teams.billing_payment_update_button')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_invoice_receipt_button')),
        findsNothing,
      );
    });
  });

  group('PlanBillingView manage_via: none', () {
    testWidgets('renders the purchase surface and no dead portal button', (
      tester,
    ) async {
      final _RailBillingService billing = _RailBillingService(
        manageVia: 'none',
        invoices: <Invoice>[invoice],
      );

      await mount(tester, PlanBillingView(billingService: billing));

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsOneWidget,
        reason: 'manage_via none is where a team starts a subscription',
      );
      expect(
        find.text(trans('uptizm.teams.billing_manage_header')),
        findsNothing,
      );
      // `GET /billing/portal` answers 409 no_billing_account for a team with
      // no Stripe customer, which is precisely the team the server reports as
      // `none`. So the affordance is absent rather than dead.
      expect(
        find.text(trans('uptizm.teams.billing_payment_update_button')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_invoice_receipt_button')),
        findsNothing,
      );
      expect(billing.portalCalls, 0);
    });
  });

  group('PlanBillingView ownership', () {
    testWidgets('a non-owner gets no purchase CTA and an owner-can-upgrade '
        'message instead', (tester) async {
      final _RailBillingService billing = _RailBillingService(
        manageVia: 'none',
        invoices: <Invoice>[invoice],
      );

      await mount(
        tester,
        PlanBillingView(billingService: billing, isOwner: false),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_owner_only_notice')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_downgrade')),
        findsNothing,
      );
      expect(billing.checkoutPlans, isEmpty);
      // A sales handoff is not a purchase, and it is driven by the plan GRID
      // rather than by the entitlement, so it survives the owner gate.
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_contact')),
        findsOneWidget,
      );
    });

    testWidgets('a non-owner gets no Stripe-portal affordance either, since '
        'the portal route is the owner\'s too', (tester) async {
      // `GET /billing/portal` goes through resolveTeamForBillingChange() like
      // the three other write routes, so a member's Update and Receipt buttons
      // are 403s waiting to happen rather than actions.
      final _RailBillingService billing = _RailBillingService(
        manageVia: 'portal',
        invoices: <Invoice>[invoice],
      );

      await mount(
        tester,
        PlanBillingView(billingService: billing, isOwner: false),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_payment_update_button')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_invoice_receipt_button')),
        findsNothing,
      );
      expect(billing.portalCalls, 0);
    });

    testWidgets('the owner keeps the purchase CTA', (tester) async {
      final _RailBillingService billing = _RailBillingService(
        manageVia: 'none',
      );

      await mount(
        tester,
        PlanBillingView(billingService: billing, isOwner: true),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.billing_owner_only_notice')),
        findsNothing,
      );
    });
  });

  group('display copy the package does not carry', () {
    /// The usage wire `BillingController::usage()` sends, decoded by the package
    /// and therefore label-free. Every label and unit below has to come from the
    /// SHIPPED catalogue via `withUsageCopy`, which is the whole point.
    final List<UsageStat> usage = UsageStat.fromWireMap(<String, dynamic>{
      'monitors': {'used': 47, 'limit': 50},
      'checks_this_month': {'used': 128400, 'limit': null},
      // A resource this app has no word for. It must reach the gates (they look
      // a resource up by key) and must NOT reach the screen as a raw wire key.
      'widgets_provisioned': {'used': 3, 'limit': 9},
    });

    testWidgets('renders the same date, expiry, usage label and status pill it '
        'rendered before the types moved into magic_payments', (tester) async {
      final _RailBillingService billing = _RailBillingService(
        manageVia: 'portal',
        invoices: <Invoice>[invoice],
        usage: usage,
      );

      await mount(tester, PlanBillingView(billingService: billing));

      expect(tester.takeException(), isNull);

      // 1. A date. `Invoice.date` and `PaymentMethod.renewalDate` are instants
      //    now; both are rendered by the same `Jun 1, 2026` formatter, so both
      //    the invoice row and the renewal sentence carry that exact string.
      expect(find.text('Jun 1, 2026'), findsOneWidget);
      expect(
        find.text('\$29/mo billed annually · renews Jun 1, 2026'),
        findsOneWidget,
      );

      // 2. A card expiry, built from the rail's `exp_month`/`exp_year`.
      expect(find.text('Expires 08 / 27'), findsOneWidget);

      // 3. A usage label and a unit, paired on by key from the catalogue. The
      //    unlabelled fourth resource renders no meter at all.
      expect(find.text('Monitors'), findsOneWidget);
      expect(find.text('Checks this month'), findsOneWidget);
      expect(find.textContaining('checks'), findsWidgets);
      expect(find.textContaining('widgets_provisioned'), findsNothing);

      // 4. An invoice-status pill, whose word now sits beside its tone.
      expect(find.text('Paid'), findsOneWidget);
    });

    testWidgets('the labels and the pill are Turkish in a Turkish session, and '
        'the date shape is unchanged', (tester) async {
      // English is the locale where a hardcoded literal passes by construction,
      // so the same four strings are read again from the tr catalogue.
      Translator.instance.setLoader(const _BundledLangLoader('tr'));
      await Translator.instance.setLocale(const Locale('tr'));

      final _RailBillingService billing = _RailBillingService(
        manageVia: 'portal',
        invoices: <Invoice>[invoice],
        usage: usage,
      );

      await mount(tester, PlanBillingView(billingService: billing));

      expect(tester.takeException(), isNull);
      expect(find.text('İzleyiciler'), findsOneWidget);
      expect(find.text('Bu ayki kontroller'), findsOneWidget);
      expect(find.text('Ödendi'), findsOneWidget);
      expect(find.text('Son kullanma 08 / 27'), findsOneWidget);
      // The date table itself is English-only by decision (it always was), so
      // this locks the shape rather than claiming a translation.
      expect(find.text('Jun 1, 2026'), findsOneWidget);
    });
  });

  group('PlanBillingView store rail', () {
    /// The wire `GET /billing/store-funded-team` answers when one of the
    /// caller's OTHER teams is already funded by a store account.
    Map<String, MagicResponse> fundedBy(String name) => {
      'billing/store-funded-team': Http.response(<String, dynamic>{
        'store_funded_team': <String, dynamic>{'id': 'team-other', 'name': name},
      }),
    };

    testWidgets('the owner gets a store purchase CTA, no web checkout, and no '
        'USD catalogue price', (tester) async {
      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'none',
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsOneWidget,
        reason: 'a store build sells through the store rail',
      );
      // The catalogue's integer USD figure is not what a storefront charges, and
      // the driver exposes no localised string yet, so the surface states where
      // the price comes from instead of naming a wrong one. Asserted as the
      // exact card price (`$29`) rather than as a substring: the renewal line
      // above the grid legitimately carries the web price for a team a store did
      // NOT sell to, which is this fixture's `manage_via: none`, and the
      // store-billed case has its own test below.
      expect(find.text('\$29'), findsNothing);
      expect(
        find.text(trans('uptizm.teams.billing_plan_price_store')),
        findsWidgets,
      );
      // Nothing on a store build may point at web checkout or the portal.
      expect(
        find.text(trans('uptizm.teams.billing_payment_update_button')),
        findsNothing,
      );
      for (final String text in renderedText(tester)) {
        expect(
          text.toLowerCase(),
          allOf(isNot(contains('stripe')), isNot(contains('uptizm.com'))),
          reason: 'a store build must not steer to a web purchase',
        );
      }
    });

    testWidgets('tapping the CTA buys through the store rail', (tester) async {
      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'none',
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
      );

      await tester.tap(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      // 'business' sits above the fixture's 'pro', so its CTA reads Upgrade.
      expect(store.purchasedPlans, ['business']);
      // Flush the confirmation toast's auto-dismiss timer.
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();
    });

    testWidgets('the one-team refusal is re-asked at the tap, not read off the '
        'mount', (tester) async {
      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'none',
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
        withToasts: true,
      );

      // The CTA rendered because nothing funded another team when the screen
      // loaded.
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsOneWidget,
      );

      // Then the answer changes under a mounted screen: another device bought,
      // or a `?upgrade=` deep link fired before the read had resolved at all.
      Http.fake(fundedBy('Kodizm Ops'));

      await tester.tap(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        store.purchasedPlans,
        isEmpty,
        reason: 'the sheet must not open at all, not open and be undone',
      );
      expect(
        find.text(trans('uptizm.teams.billing_store_bound_title')),
        findsOneWidget,
      );
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();
    });

    testWidgets('a dismissed purchase sheet reports nothing at all', (
      tester,
    ) async {
      // `false` is the ordinary outcome of a customer changing their mind, so a
      // "purchase complete" or an "it failed" toast would both be this screen
      // inventing an event.
      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'none',
        purchaseResult: false,
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
        withToasts: true,
      );

      await tester.tap(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(store.purchasedPlans, ['business']);
      expect(
        find.text(trans('uptizm.teams.billing_store_purchase_title')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_toast_checkout_failed_title')),
        findsNothing,
      );
    });

    testWidgets('a rail failure surfaces the failure toast and does not crash '
        'the screen', (tester) async {
      // The message a real unconfigured rail throws, near enough verbatim: the
      // package writes these for whoever wired the rail up, and this one names
      // an internal config key.
      const String developerMessage =
          'The store rail is not configured. Set '
          'payments.revenuecat.public_sdk_key to this platform\'s public '
          'RevenueCat SDK key.';

      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'none',
        purchaseError: const BillingException(developerMessage),
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
        withToasts: true,
      );

      await tester.tap(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_toast_checkout_failed_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.billing_store_purchase_title')),
        findsNothing,
      );

      // The body is the customer's sentence, and the developer's never reaches
      // the screen. This used to render `error.message` directly, so a Turkish
      // session was shown an English sentence naming a config key.
      expect(
        find.text(trans('uptizm.teams.billing_toast_failed_text')),
        findsOneWidget,
      );
      expect(find.textContaining('public_sdk_key'), findsNothing);
      expect(find.text(developerMessage), findsNothing);
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();
    });

    testWidgets('a non-owner gets no store purchase CTA and no restore', (
      tester,
    ) async {
      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'none',
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: false),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_store_restore_button')),
        findsNothing,
        reason: 'a restore would re-attribute a subscription to this team too',
      );
      expect(store.purchasedPlans, isEmpty);
      expect(
        find.text(trans('uptizm.teams.billing_owner_only_notice')),
        findsOneWidget,
      );
    });

    testWidgets('a store account already funding another team is refused with '
        'that team named', (tester) async {
      Http.fake(fundedBy('Kodizm Ops'));

      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'none',
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
      );

      expect(tester.takeException(), isNull);
      // The refusal names the team, because "a store account can fund only one
      // team" is unactionable without knowing which one holds it.
      expect(
        find.text(
          trans('uptizm.teams.billing_store_bound_text', {
            'team': 'Kodizm Ops',
          }),
        ),
        findsOneWidget,
      );
      // And it is a refusal rather than a warning beside a live button: the
      // second purchase would TRANSFER the subscription off the named team.
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_store_restore_button')),
        findsNothing,
      );
      expect(store.purchasedPlans, isEmpty);
    });

    testWidgets('a Stripe-billed team gets no store purchase surface', (
      tester,
    ) async {
      // The mirror of the store-rail refusal on the web side: a second rail
      // must never open a parallel subscription, whichever rail is second.
      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'portal',
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_store_restore_button')),
        findsNothing,
      );
      expect(store.purchasedPlans, isEmpty);
    });

    testWidgets('restoring hands the store purchase back and reports what the '
        'store answered', (tester) async {
      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'none',
        restoreResult: false,
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
        withToasts: true,
      );

      await tester.tap(
        find.text(trans('uptizm.teams.billing_store_restore_button')),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(store.restoreCalls, 1);
      // `false` is an answer to show the customer, not a failure to log.
      expect(
        find.text(trans('uptizm.teams.billing_store_restore_none_title')),
        findsOneWidget,
      );
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();
    });

    testWidgets('a store-billed team reads its renewal line off the store, not '
        'off the USD catalogue', (tester) async {
      final _StoreRailBillingService store = _StoreRailBillingService(
        manageVia: 'app_store',
      );

      await mount(
        tester,
        PlanBillingView(billingService: store, isOwner: true),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_renewal_store')),
        findsOneWidget,
      );
      expect(find.textContaining('billed annually'), findsNothing);
    });
  });

  group('a tier with no rail behind it says so', () {
    testWidgets('the renewal line does not promise a renewal it cannot have', (
      tester,
    ) async {
      await mount(
        tester,
        PlanBillingView(billingService: _UnbilledBillingService(), isOwner: true),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_renewal_unbilled')),
        findsOneWidget,
      );
      // The defect this replaces: the sentence rendered `renews Unknown`, which
      // reads as a renewal whose date was lost rather than as no renewal.
      expect(find.textContaining(trans('common.unknown')), findsNothing);
      // The live sentence's own separator, not the bare word: the honest
      // replacement above ends in "nothing renews", so asserting on `renews`
      // alone matches the fix and fails on the very thing it is checking.
      expect(find.textContaining('· renews '), findsNothing);
    });

    testWidgets('the payment section names the absence instead of labelling a '
        'card Unknown', (tester) async {
      await mount(
        tester,
        PlanBillingView(billingService: _UnbilledBillingService(), isOwner: true),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_payment_none')),
        findsOneWidget,
      );
      // Two symptoms of one defect, both pinned. The brand tile rendered
      // `common.unknown` beside a row that fell back to the SECTION HEADING,
      // so "Payment method" appeared twice and the pair read as a real card of
      // an unknown brand.
      expect(find.textContaining(trans('common.unknown')), findsNothing);
      expect(
        find.text(trans('uptizm.teams.billing_payment_header')),
        findsOneWidget,
      );
    });
  });

  group('a held tier the catalogue no longer serves renders as unknown', () {
    testWidgets('the current-plan card names the held tier id instead of the '
        "catalogue's cheapest plan", (tester) async {
      await mount(
        tester,
        PlanBillingView(
          billingService: _HeldRetiredTierBillingService(),
          isOwner: true,
        ),
      );

      expect(tester.takeException(), isNull);
      // The defect this replaces: `_findPlan` fell back to `_plans.first`
      // ('free'), so a grandfathered customer saw the free tier's name and
      // renewal line as their own current plan.
      expect(
        find.text(
          trans('uptizm.teams.billing_plan_unavailable_text', {
            'id': 'legacy_grandfathered',
          }),
        ),
        findsOneWidget,
      );
      // The "Current" badge still marks the card as theirs, even though its
      // details are unavailable. Exactly one: no priced-tier card in the grid
      // may claim to be the active plan when [_findPlan] found none.
      expect(
        find.text(trans('uptizm.teams.billing_plan_current_badge')),
        findsOneWidget,
      );
    });

    testWidgets('every plan card falls back to a neutral comparison label '
        'rather than Upgrade or Downgrade', (tester) async {
      await mount(
        tester,
        PlanBillingView(
          billingService: _HeldRetiredTierBillingService(),
          isOwner: true,
        ),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_downgrade')),
        findsNothing,
      );
      // Every priced, non-custom card (free/pro/business) reads the neutral
      // label; the custom tier keeps its own "Contact sales".
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_unranked')),
        findsNWidgets(3),
      );
    });

    testWidgets('the Turkish session renders the shipped Turkish copy, not a '
        'raw i18n key', (tester) async {
      Translator.instance.setLoader(const _BundledLangLoader('tr'));
      await Translator.instance.setLocale(const Locale('tr'));

      final Map<String, dynamic> tr = readBundledLang('tr');
      final Object? unavailable = tr['uptizm.teams.billing_plan_unavailable_text'];
      final Object? unranked = tr['uptizm.teams.billing_plan_button_unranked'];
      expect(unavailable, isA<String>());
      expect(unranked, isA<String>());

      await mount(
        tester,
        PlanBillingView(
          billingService: _HeldRetiredTierBillingService(),
          isOwner: true,
        ),
      );

      expect(tester.takeException(), isNull);
      expect(
        find.text(
          trans('uptizm.teams.billing_plan_unavailable_text', {
            'id': 'legacy_grandfathered',
          }),
        ),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_unranked')),
        findsNWidgets(3),
      );
      expect(find.textContaining('billing_plan_unavailable'), findsNothing);
      expect(find.textContaining('billing_plan_button_unranked'), findsNothing);
    });
  });

  group('a failed read is never reported as an absence', () {
    testWidgets('a paying customer is not told they have no subscription when '
        'the Stripe read soft-failed', (tester) async {
      await mount(
        tester,
        PlanBillingView(
          billingService: _SoftFailedPaymentBillingService(),
          isOwner: true,
        ),
      );

      expect(tester.takeException(), isNull);

      // The sentence is TRUE only of a team with no rail. This team has one:
      // `manage_via` is `portal`, so a Stripe customer exists.
      expect(
        find.text(trans('uptizm.teams.billing_renewal_unbilled')),
        findsNothing,
      );
      expect(
        find.text(trans('uptizm.teams.billing_payment_none')),
        findsNothing,
      );
    });

    testWidgets('it says the read failed rather than inventing a card', (
      tester,
    ) async {
      await mount(
        tester,
        PlanBillingView(
          billingService: _SoftFailedPaymentBillingService(),
          isOwner: true,
        ),
      );

      // What the customer should see: the truth, which is that we could not
      // read their card, next to the button that lets them replace it.
      expect(find.text(trans('common.error_occurred')), findsOneWidget);

      // And not the incoherent pair this whole thread started from: a brand tile
      // reading "Unknown" beside a row that fell back to the SECTION HEADING, so
      // the heading appeared twice.
      expect(
        find.text(trans('uptizm.teams.billing_payment_header')),
        findsOneWidget,
      );

      // The renewal line DOES still read "renews Unknown" here, and that is the
      // intended answer rather than an oversight: the read did not resolve into
      // anything, so a neutral label is the honest fallback, and it is what this
      // state rendered before any of this work. Asserted rather than forbidden,
      // because the temptation on seeing it is to reach for the confident
      // sentence, which is exactly the defect the sibling test pins.
      expect(find.textContaining(trans('common.unknown')), findsOneWidget);
    });
  });

  group('an unresolved rail claims neither sentence', () {
    testWidgets('the payment card waits instead of picking one', (tester) async {
      await mount(
        tester,
        PlanBillingView(
          billingService: _UnresolvedRailBillingService(),
          isOwner: true,
        ),
      );

      expect(tester.takeException(), isNull);

      // Neither is knowable yet: "no card on file" needs to know there is no
      // rail, and "the read failed" needs to know one failed. The second sneaked
      // in with the fix for the first, because `null` is not `ManageVia.none`
      // and the branch keyed on the negative.
      expect(
        find.text(trans('uptizm.teams.billing_payment_none')),
        findsNothing,
      );
      expect(find.text(trans('common.error_occurred')), findsNothing);
    });
  });

  group('the team switch re-identifies the store customer', () {
    /// Registers [store] as the app's STORE rail, the way a consumer swaps one.
    ///
    /// `PaymentsManager` is a `static final` singleton that outlives
    /// `MagicApp.reset()`, so the teardown is not hygiene: without it the fake
    /// would answer `Payments.store` for every later test in this file and turn
    /// every "no store rail" assertion into a false pass.
    void useStoreRail(_StoreRailBillingService store) {
      Payments.extend(PaymentsManager.storeRole, () => store);
      addTearDown(Payments.forgetDrivers);
    }

    test('identifies with the NEW team id once the switch has resolved',
        () async {
      final FakeNetworkDriver network = Http.fake();
      Auth.fake();
      final _StoreRailBillingService store = _StoreRailBillingService();
      useStoreRail(store);

      await AppServiceProvider.switchTeamAndIdentifyStore('team-beta');

      network.assertSent(
        (MagicRequest request) => request.url.contains('user/current-team'),
      );
      // Asserted at the point the identify HAPPENS, not on a flag read before
      // it: the App User ID the rail is bound to is what a webhook attributes a
      // purchase to, so the id itself is the assertion.
      expect(store.identifiedIds, ['team-beta']);
    });

    test('a session that never switched still identifies the team it is on',
        () async {
      // The common path on a fresh install: sign in, open billing, buy. Without
      // this the rail would still hold its anonymous id and the purchase would
      // arrive attributed to nobody, so identifying on the SWITCH alone is not
      // enough even though the switch is the case that goes wrong silently.
      Http.fake();
      Auth.fake(
        user: User.fromMap(<String, dynamic>{
          'id': 'u1',
          'name': 'Ada',
          'current_team': <String, dynamic>{
            'id': 'team-alpha',
            'name': 'Alpha',
          },
        }),
      );
      final _StoreRailBillingService store = _StoreRailBillingService();
      useStoreRail(store);

      AppServiceProvider.syncStoreIdentity();
      await pumpEventQueue();

      expect(store.identifiedIds, ['team-alpha']);
    });

    test('a logged-out session identifies nothing', () async {
      // There is nobody to attribute a purchase to, and the rail keeps whatever
      // the previous session bound: the contract has no logout, and the billing
      // screen is behind auth, so nothing can spend against it meanwhile.
      Http.fake();
      Auth.fake();
      final _StoreRailBillingService store = _StoreRailBillingService();
      useStoreRail(store);

      AppServiceProvider.syncStoreIdentity();
      await pumpEventQueue();

      expect(store.identifiedIds, isEmpty);
    });

    test('does not re-identify when the backend refused the switch', () async {
      // A failed switch leaves the app on the team it was already on, so
      // re-identifying would bind the store account to a team the user never
      // landed on and hand the next purchase to it.
      Http.fake(<String, MagicResponse>{
        'user/current-team': Http.response(<String, dynamic>{
          'message': 'That team is not yours.',
        }, 422),
      });
      Auth.fake();
      final _StoreRailBillingService store = _StoreRailBillingService();
      useStoreRail(store);

      await AppServiceProvider.switchTeamAndIdentifyStore('team-beta');

      expect(store.identifiedIds, isEmpty);
    });
  });

  group('Language catalogue', () {
    test('en and tr carry exactly the same keys', () {
      // No automated parity gate exists anywhere else, so a missing tr key
      // ships as a visible raw key with nothing failing. This locks the
      // invariant for every later step, not only for this one.
      expect(
        readBundledLang('tr').keys.toSet(),
        readBundledLang('en').keys.toSet(),
      );
    });

    test('every new billing management key has a non-empty Turkish value', () {
      final Map<String, dynamic> tr = readBundledLang('tr');
      final Map<String, dynamic> en = readBundledLang('en');

      const List<String> keys = [
        'uptizm.teams.billing_manage_header',
        'uptizm.teams.billing_manage_app_store_text',
        'uptizm.teams.billing_manage_play_store_text',
        'uptizm.teams.billing_manage_store_button',
        'uptizm.teams.billing_manage_store_no_url',
        'uptizm.teams.billing_owner_only_notice',
        'uptizm.teams.billing_renewal_store',
        'uptizm.teams.billing_plan_price_store',
        'uptizm.teams.billing_store_bound_title',
        'uptizm.teams.billing_store_bound_text',
        'uptizm.teams.billing_store_purchase_title',
        'uptizm.teams.billing_store_purchase_text',
        'uptizm.teams.billing_store_restore_button',
        'uptizm.teams.billing_store_restore_found_title',
        'uptizm.teams.billing_store_restore_none_title',
        'uptizm.teams.billing_store_restore_none_text',
      ];

      for (final String key in keys) {
        expect(en[key], isA<String>(), reason: '$key is missing from en.json');
        expect(tr[key], isA<String>(), reason: '$key is missing from tr.json');
        expect((tr[key] as String).trim(), isNotEmpty);
        expect(
          tr[key],
          isNot(en[key]),
          reason: '$key reads as English left in place',
        );
      }
    });
  });
}
