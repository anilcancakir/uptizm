// Step 17's replacement for the blocked live dusk walk: `AppLayout` swaps
// widget trees at `lg` (1024px), and each side can break alone, so this test
// mounts the packaged billing route THROUGH the real shell at both widths
// and proves the shell differs while the screen does not.
//
// The mount/service-double shape is deliberately the same one
// `test/app/providers/app_service_provider_test.dart` already built for the
// billing surface (real registration, real controller, uptizm's own
// `withUsageCopy`/`formatCount`/ownership reader), and the width-driving idiom
// is `test/ui/layouts/app_layout_test.dart`'s `pumpAtWidth`
// (`tester.view.physicalSize` + `devicePixelRatio`, both reset). Both fixture
// sets are private to their own files, so this file holds its own minimal
// copies rather than reaching into another file's private classes.
import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_payments/magic_payments.dart'
    show
        BillingEntitlement,
        BillingInvoicesPage,
        BillingService,
        Invoice,
        PaymentMethod,
        UsageStat;
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/models/user.dart' show User;
import 'package:uptizm/app/providers/app_service_provider.dart';
import 'package:uptizm/app/support/formatters.dart' show formatCount;
import 'package:uptizm/app/support/team_types.dart' show withUsageCopy;
import 'package:uptizm/config/magic_starter.dart' show magicStarterConfig;
import 'package:uptizm/config/uptizm_status_tokens.dart'
    show uptizmStatusAliases;
import 'package:uptizm/ui/layouts/app_layout.dart';
import 'package:uptizm/ui/layouts/sidebar.dart';

import '../../support/bundled_lang.dart';

/// Serves uptizm's own shipped English catalogue.
class _BundledEnglishLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang('en');
}

/// A minimal read-only [BillingService], carrying just enough of one real
/// catalogue row and one named usage stat to prove the screen renders
/// uptizm's own copy, not raw wire keys, at either width.
class _StubBillingService implements BillingService {
  @override
  Future<BillingEntitlement> currentEntitlement() async {
    return BillingEntitlement.fromMap(<String, dynamic>{
      'plan': 'free',
      'plan_status': 'active',
      'subscribed': true,
      'renews': true,
      'provider': 'stripe',
      'manage_via': 'none',
      'manage_url': null,
      'ai_analysis_trials_remaining': null,
    });
  }

  @override
  Future<List<Map<String, dynamic>>> getPlans() async =>
      <Map<String, dynamic>>[
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
      ];

  // `checks_this_month` rather than `monitors`: the Sidebar's own nav
  // destination also renders the word "Monitors", and picking that key would
  // make the usage-meter assertion below ambiguous between the shell's own
  // chrome and the screen this test is actually about.
  @override
  Future<List<UsageStat>> getUsage() async => const <UsageStat>[
    UsageStat(key: 'checks_this_month', used: 83365, limit: 100000),
  ];

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) async {
    return const BillingInvoicesPage(invoices: <Invoice>[], nextCursor: null);
  }

  @override
  Future<PaymentMethod> getPaymentMethod() async {
    return const PaymentMethod(available: true);
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();

    Magic.singleton('log', () => LogManager());
    Config.set('logging', <String, dynamic>{
      'default': 'console',
      'channels': <String, dynamic>{
        'console': <String, dynamic>{'driver': 'console', 'level': 'debug'},
      },
    });
    final Map<String, dynamic> block =
        magicStarterConfig['magic_starter'] as Map<String, dynamic>;
    Config.set('magic_starter', block);
    Magic.singleton('magic_starter', () => MagicStarterManager());

    Http.fake();

    Translator.instance.setLoader(_BundledEnglishLoader());
    await Translator.instance.setLocale(const Locale('en'));

    Auth.fake(
      user: User.fromMap(<String, dynamic>{
        'id': 'u1',
        'name': 'Ada',
        'current_team': <String, dynamic>{
          'id': 't1',
          'name': 'Alpha',
          'user_role': 'owner',
        },
      }),
    );
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Registers uptizm's real billing surface with uptizm's real
  /// collaborators (the same wiring `AppServiceProvider.registerBillingSurface`
  /// installs at boot), mounts the packaged view INSIDE the real [AppLayout]
  /// at ([width], [height]), and settles the mount-time reads.
  Future<void> mountShellAtSize(
    WidgetTester tester,
    double width,
    double height,
  ) async {
    AppServiceProvider.registerBillingSurface();
    Magic.put(
      MagicStarterBillingController(
        usageCopy: withUsageCopy,
        formatNumber: formatCount,
        isOwnerReader: AppServiceProvider.readTeamOwnership,
        storeFundedTeamReader: AppServiceProvider.readStoreFundedTeam,
        billingService: _StubBillingService(),
      ),
    );

    tester.view.devicePixelRatio = 1.0;
    tester.view.physicalSize = Size(width, height);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        home: WindTheme(
          data: WindThemeData(aliases: uptizmStatusAliases),
          child: AppLayout(child: MagicStarter.view.make('teams.billing')),
        ),
      ),
    );
    await tester.pump();
    await tester.pump();
  }

  group('the billing route renders through the real AppLayout shell', () {
    testWidgets(
      'MOBILE (390x844): mobile chrome, no Sidebar, the screen and its '
      'usage label render',
      (WidgetTester tester) async {
        await mountShellAtSize(tester, 390, 844);

        // The shell: mobile chrome takes over below `lg`, so no Sidebar.
        expect(find.byType(Sidebar), findsNothing);
        // The screen: the package view's own title, from uptizm's shipped
        // catalogue rather than a raw `magic_starter.billing.title` key.
        expect(find.text('Plan & billing'), findsOneWidget);
        // The usage meter: uptizm's word for the resource, not the raw wire
        // key `checks_this_month`, paired through `withUsageCopy`.
        expect(find.text('Checks this month'), findsOneWidget);
        // No layout exception at this width. Left as a strict assertion on
        // purpose: a real problem here must be a finding, not a weakened
        // test. See the step report for what this currently catches.
        expect(tester.takeException(), isNull);
      },
    );

    testWidgets(
      'DESKTOP (1440x900): the Sidebar renders, the screen and its usage '
      'label render',
      (WidgetTester tester) async {
        await mountShellAtSize(tester, 1440, 900);

        // The shell: the persistent Sidebar takes over at and above `lg`.
        expect(find.byType(Sidebar), findsOneWidget);
        // The screen renders identically on this side of the breakpoint.
        expect(find.text('Plan & billing'), findsOneWidget);
        expect(find.text('Checks this month'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );
  });
}
