import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:magic_payments/magic_payments.dart'
    show
        BillingCheckoutSession,
        BillingEntitlement,
        BillingInvoicesPage,
        BillingService,
        Invoice,
        InvoiceStatus,
        PaymentMethod,
        UsageStat,
        WebBillingService;
import 'package:uptizm/app/mocks/billing.dart' show plans;
import 'package:uptizm/app/mocks/teams_data.dart' show planWireRows;
import 'package:uptizm/resources/views/teams/plan_billing_view.dart';

import '../../../support/bundled_lang.dart';

/// A [BillingService] whose entitlement is configurable per rail, recording
/// every purchase-affecting call so a test can assert an affordance was not
/// merely hidden but never reachable.
///
/// The rail arrives as its RAW WIRE WORD (`app_store`, not `ManageVia`
/// .appStore), because that is the only thing the real decoder ever sees; a
/// fake taking an already-decoded case could pass while
/// `ManageVia.fromWire('play_store')` silently fell into its fallback. The
/// entitlement is therefore built through [BillingEntitlement.fromMap] rather
/// than through the const constructor, which takes cases already decoded.
///
/// It implements BOTH the read contract and [WebBillingService], because the
/// subject calls both: `checkout` and `openPortal` live on the rail contract, and
/// the screen renders no purchase or portal affordance at all when the rail is
/// absent. A read-only fake would have made every "the affordance is gone"
/// assertion below pass for the wrong reason.
class _RailBillingService implements BillingService, WebBillingService {
  _RailBillingService({
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
  final String entitlementPlan = 'pro';

  /// The billing history `GET /billing/invoices` resolves to.
  final List<Invoice> invoices;

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
  Widget wrap(Widget widget, {Size size = const Size(1280, 12000)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  /// Mounts [view] and settles the five mount-time reads.
  Future<void> mount(WidgetTester tester, PlanBillingView view) async {
    await tester.binding.setSurfaceSize(const Size(1280, 12000));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(view));
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
