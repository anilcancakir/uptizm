import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:magic_payments/magic_payments.dart'
    show
        BillingCheckoutSession,
        BillingEntitlement,
        BillingInvoicesPage,
        BillingService,
        PaymentMethod,
        Payments,
        PaymentsManager,
        UsageStat,
        WebBillingService;
import 'package:uptizm/app/controllers/escalation_controller.dart';
import 'package:uptizm/app/models/escalation_policy.dart';
import 'package:uptizm/app/providers/app_service_provider.dart';
import 'package:uptizm/config/magic_starter.dart' show magicStarterConfig;
import 'package:uptizm/resources/views/teams/escalation_policies_view.dart';
import 'package:uptizm/resources/views/teams/escalation_policy_editor_view.dart';
import 'package:uptizm/resources/views/teams/on_call_schedule_view.dart';

import '../../support/bundled_lang.dart';
import '../../support/skeleton_matchers.dart';

/// A build that can serve WEB checkout, standing in for the rail alone.
///
/// `flutter test` runs on the `dart:io` arm, whose web rail is legitimately
/// null, and the billing screen gates its portal and checkout affordances on
/// that rail existing. Registering this through the manager's own override seam
/// leaves `Payments.billing` (the READS, which are what the case below is
/// about) resolving the real HTTP driver.
///
/// It implements the read contract too because [WebBillingService] does not
/// extend it and the manager's web role is typed on the rail; every read here
/// answers empty and none of them is ever called.
class _WebRailStandIn implements BillingService, WebBillingService {
  @override
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required String successUrl,
    required String cancelUrl,
  }) async {
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
  Future<String> openPortal({String? returnUrl}) async => '';

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    return BillingEntitlement.fromMap(const <String, dynamic>{});
  }

  @override
  Future<List<Map<String, dynamic>>> getPlans() async => const [];

  @override
  Future<List<UsageStat>> getUsage() async => const [];

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) async {
    return const BillingInvoicesPage(invoices: [], nextCursor: null);
  }

  @override
  Future<PaymentMethod> getPaymentMethod() async => const PaymentMethod();
}

/// Serves uptizm's OWN shipped catalogue, for the billing group below.
///
/// The billing screen renders `magic_starter.billing.*` keys now, and reading
/// the shipped asset rather than an inline map is what keeps a copy assertion
/// an assertion about the product.
class _BundledLangLoader implements TranslationLoader {
  const _BundledLangLoader(this.locale);

  /// The catalogue to serve, regardless of the locale the translator asks for.
  final String locale;

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}

/// In-memory language loader supplying every [trans] key exercised by the
/// uptizm team-operations views' smoke tests (notification channels,
/// escalation policies + editor, on-call schedule). Team create/settings/
/// members, invitation acceptance and plan & billing moved to magic_starter, so
/// their keys are gone; the billing group below reads the SHIPPED catalogue
/// instead, because the screen it mounts is the package's. Short, wrappable
/// strings avoid RenderFlex overflow at the test viewport.
class _TeamsViewsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // Shared.
      'common.cancel': 'Cancel',
      'nav.dashboard': 'Dashboard',
      'uptizm.nav.dashboard': 'Dashboard',
      'uptizm.settings.hub_title': 'Settings',
      'uptizm.status.editor_breadcrumb_back': 'Status pages',
      'uptizm.team_menu.escalation': 'Escalation policies',

      // Notification channels.
      'uptizm.teams.channels_title': 'Notification channels',
      'uptizm.teams.channels_description': 'Team-level integrations.',
      'uptizm.teams.channels_email_desc': 'Send alerts to team members.',
      'uptizm.teams.channels_sms_desc': 'Receive alerts by text.',
      'uptizm.teams.channels_slack_desc': 'Post alerts to Slack.',
      'uptizm.teams.channels_teams_desc': 'Post alerts to Teams.',
      'uptizm.teams.channels_webhook_desc': 'POST alerts to an endpoint.',
      'uptizm.teams.channels_severity_critical': 'Critical only',
      'uptizm.teams.channels_severity_all': 'All alerts',
      'uptizm.teams.channels_connect_button': 'Connect',
      'uptizm.teams.channels_save_button': 'Save',
      'uptizm.teams.channels_test_button': 'Send test',
      'uptizm.teams.channels_toast_title': 'Not yet saved',
      'uptizm.teams.channels_toast_description': ':channel not persisted.',
      'uptizm.teams.channels_email_recipients_label': 'Additional recipients',
      'uptizm.teams.channels_email_recipients_hint': 'Plus every member.',
      'uptizm.teams.channels_email_recipients_placeholder': 'oncall@acme.com',
      'uptizm.teams.channels_sms_phone_label': 'Phone number',
      'uptizm.teams.channels_slack_workspace_label': 'Workspace',
      'uptizm.teams.channels_slack_channel_label': 'Channel',
      'uptizm.teams.channels_slack_channel_placeholder': '#incidents',
      'uptizm.teams.channels_teams_webhook_label': 'Incoming webhook URL',
      'uptizm.teams.channels_teams_webhook_hint': 'Create one in connectors.',
      'uptizm.teams.channels_teams_webhook_placeholder':
          'https://outlook.office.com/webhook',
      'uptizm.teams.channels_webhook_url_label': 'Endpoint URL',
      'uptizm.teams.channels_webhook_secret_label': 'Signing secret',
      'uptizm.teams.channels_webhook_secret_hint': 'Sent as a header.',
      'uptizm.teams.channels_severity_label': 'Deliver',
      'uptizm.teams.channels_severity_hint': 'Which alerts this channel gets.',

      // Escalation policies list.
      'uptizm.teams.escalation_title': 'Escalation policies',
      'uptizm.teams.escalation_description':
          'When an alert goes unacknowledged.',
      'uptizm.teams.escalation_new_button': 'New policy',
      'uptizm.teams.escalation_empty_title': 'No escalation policies yet',
      'uptizm.teams.escalation_empty_description':
          'A policy decides who Uptizm pages next.',
      'uptizm.teams.escalation_oncall_reference':
          'Who answers comes from on-call.',
      'uptizm.teams.escalation_policy_edit_button': 'Edit',
      'uptizm.teams.escalation_policy_delete_button': 'Delete',
      'uptizm.teams.escalation_policy_count_word_singular': 'monitor',
      'uptizm.teams.escalation_policy_count_word_plural': 'monitors',
      'uptizm.teams.escalation_policy_default_badge': 'Default',
      'uptizm.teams.escalation_policy_repeats_last':
          'Repeats until acknowledged.',
      'uptizm.teams.escalation_policy_delete_confirm_title': 'Delete :name?',
      'uptizm.teams.escalation_policy_delete_confirm_description':
          "Can't be undone.",
      'uptizm.teams.escalation_policy_delete_confirm_label': 'Delete policy',

      // Escalation policy editor.
      'uptizm.teams.escalation_editor_title_new': 'New escalation policy',
      'uptizm.teams.escalation_editor_title_edit': 'Edit policy',
      'uptizm.teams.escalation_editor_description': 'A ladder of rungs.',
      'uptizm.teams.escalation_editor_save_button': 'Save changes',
      'uptizm.teams.escalation_editor_create_button': 'Create policy',
      'uptizm.teams.escalation_editor_name_label': 'Name',
      'uptizm.teams.escalation_editor_name_placeholder': 'Critical path',
      'uptizm.teams.form_name_error_required': 'Name is required.',
      'uptizm.teams.form_targets_error_required': 'Add a target.',
      'uptizm.teams.escalation_editor_desc_label': 'Description',
      'uptizm.teams.escalation_editor_desc_placeholder': 'Aggressive paging.',
      'uptizm.teams.escalation_editor_ladder_header': 'Escalation ladder',
      'uptizm.teams.escalation_editor_add_rung_button': '+ Add rung',
      'uptizm.teams.escalation_editor_rung_title': 'Rung :number',
      'uptizm.teams.escalation_editor_delay_label': 'When',
      'uptizm.teams.escalation_editor_targets_label': 'Notify',
      'uptizm.teams.escalation_editor_targets_hint': "Who this rung pages.",
      'uptizm.teams.escalation_editor_repeat_label': 'Repeat the last rung',
      'uptizm.teams.escalation_editor_default_label': 'Use as default',
      'uptizm.teams.escalation_toast_error_title': "Couldn't save",
      'uptizm.teams.escalation_toast_error_description': 'Try again.',

      // On-call schedule.
      'uptizm.teams.oncall_title': 'On-call schedule',
      'uptizm.teams.oncall_description': 'Who answers first.',
      'uptizm.teams.oncall_override_button': 'Override',
      'uptizm.teams.oncall_override_label': 'Hand the pager to',
      'uptizm.teams.oncall_current_header': 'On call now',
      'uptizm.teams.oncall_rotation_header': 'Rotation',
      'uptizm.teams.oncall_remove_button': 'Remove',
      'uptizm.teams.oncall_add_button': '+ Add to rotation',
      'uptizm.teams.oncall_escalation_reference':
          'Configure escalation policies.',
      'uptizm.teams.oncall_remove_confirm_title': 'Remove :name?',
      'uptizm.teams.oncall_remove_confirm_description': "They'll stop shifts.",
      'uptizm.teams.oncall_remove_confirm_label': 'Remove',
      'uptizm.teams.oncall_empty_title': 'No on-call schedule yet',
      'uptizm.teams.oncall_empty_description': 'Create one to page someone.',
      'uptizm.teams.oncall_create_button': 'Create schedule',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / Button / Input / Switch / Badge
    // / PageHeader resolve their themes via MagicStarter.* without a full app
    // boot (mirrors settings_views_test.dart).
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Bind LogManager so Log.warning() works inside MagicFeedback.showSnackbar
    // (a view calling Magic.success/error/MagicFeedback.info falls through to a
    // warning log when no navigator context is mounted, as here; mirrors
    // monitor_controller_test.dart). The billing group below also needs it for
    // the reads that log and degrade rather than throwing.
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver so EscalationController's wired reload/
    // create/save/delete actions resolve the `network` service instead of
    // throwing on an unregistered service (mirrors monitor_controller_test.dart).
    Http.fake();
    // Force-build the lazy GoRouter so MagicRoute.to (used by
    // EscalationController.create/save/delete) does not throw
    // StateError('Router not initialized...').
    MagicRouter.instance.routerConfig;

    Translator.instance.setLoader(_TeamsViewsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
    // The payments manager is a `static final` that outlives a container reset,
    // so an override registered by one test would hand the next a rail it never
    // asked for. This is the package's own isolation seam.
    Payments.forgetDrivers();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a
  /// configurable [MediaQuery] size, mirroring the harness established in
  /// `settings_views_test.dart`.
  Widget wrap(Widget widget, {Size size = const Size(1280, 6000)}) {
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

  // ---------------------------------------------------------------------------
  // EscalationPoliciesView
  // ---------------------------------------------------------------------------

  group('EscalationPoliciesView', () {
    /// Fixture-shaped detail seeds for [EscalationController.seedForTest],
    /// standing in for the live `GET /escalation-policies` + per-policy
    /// detail hydration this view now sources instead of the design-lab
    /// fixtures (see `EscalationController`'s class docblock).
    final List<EscalationPolicy> seeds = [
      EscalationPolicy.fromMap({
        'id': 'standard',
        'name': 'Standard',
        'steps': [
          {
            'id': 'step-1',
            'position': 0,
            'delay_minutes': 0,
            'target_type': 'channel',
            'channel': 'Slack #incidents',
          },
        ],
      }),
      EscalationPolicy.fromMap({
        'id': 'critical',
        'name': 'Critical path',
        'steps': [
          {
            'id': 'step-2',
            'position': 0,
            'delay_minutes': 0,
            'target_type': 'channel',
            'channel': 'On-call engineer',
          },
        ],
      }),
    ];

    testWidgets('renders the title and a card for every policy', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      EscalationController.instance.seedForTest(seeds);

      await tester.pumpWidget(
        wrap(const EscalationPoliciesView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      // The rung rail's connecting line is a Stack+Positioned bar (the
      // incident_timeline pattern), not an Expanded-in-Column, so no
      // RenderFlex overflow fires under the unbounded-height scroll view.
      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.escalation_title')), findsOneWidget);
      for (final EscalationPolicy policy in seeds) {
        expect(find.text(policy.name!), findsOneWidget);
      }
    });

    testWidgets('shows a skeleton before the first read resolves, not a page '
        'with no policies', (tester) async {
      // The regression this pins: loading was indistinguishable from emptiness,
      // so a team with a configured ladder opened this screen on a bare page
      // with no policy cards and only grew them once the fetch landed (two round
      // trips here: the index call plus a per-policy detail hydration).
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // No seedForTest: the mount's own fetch is still in flight on the first
      // frame. Deliberately NOT pumped again, since that pending state is only
      // observable on the very first frame.
      await tester.pumpWidget(
        wrap(const EscalationPoliciesView(), size: const Size(1280, 6000)),
      );

      expect(find.byType(MSSkeleton), findsWidgets);
      expectVisibleSkeletons(tester);
      expect(
        find.text(trans('uptizm.teams.escalation_policy_edit_button')),
        findsNothing,
        reason: 'the skeleton offers no actions on policies it has not read',
      );

      // Once it resolves (the fake answers nothing), the skeleton gives way to
      // the honestly policy-less page.
      await tester.pump();
      expect(find.byType(MSSkeleton), findsNothing);
      expect(
        find.text(trans('uptizm.teams.escalation_policy_edit_button')),
        findsNothing,
      );
    });

    testWidgets('a resolved empty roster shows no policies and no skeleton', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // Seeding is a resolved state, so an empty seed is a known-empty roster.
      EscalationController.instance.seedForTest(const []);

      await tester.pumpWidget(
        wrap(const EscalationPoliciesView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(find.byType(MSSkeleton), findsNothing);
      expect(find.text(trans('uptizm.teams.escalation_title')), findsOneWidget);
      expect(
        find.text(trans('uptizm.teams.escalation_policy_edit_button')),
        findsNothing,
      );

      // And it SAYS so. This case used to assert only the absence of policies,
      // which an empty `WDiv` satisfies: a team with no policy saw a hairline, a
      // gap and the on-call footnote, reading as a screen that failed to load.
      // Every sibling surface answers this state with an empty state.
      expect(
        find.text(trans('uptizm.teams.escalation_empty_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.escalation_empty_description')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // EscalationPolicyEditorView
  // ---------------------------------------------------------------------------

  group('EscalationPolicyEditorView', () {
    testWidgets('create mode renders the new-policy title', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const EscalationPolicyEditorView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.escalation_editor_title_new')),
        findsOneWidget,
      );
    });

    testWidgets(
      'create mode: saving with a blank name shows an inline error and skips '
      'the write',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 6000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final FakeNetworkDriver fake = Http.fake();
        MagicRouter.instance.routerConfig;

        await tester.pumpWidget(
          wrap(const EscalationPolicyEditorView(), size: const Size(1280, 6000)),
        );
        await tester.pump();

        // Tap Create with the blank create defaults (empty name, one rung with
        // no targets): the client-side required check must block before any
        // round trip.
        await tester.tap(
          find.text(trans('uptizm.teams.escalation_editor_create_button')),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.teams.form_name_error_required')),
          findsOneWidget,
          reason: 'A blank name must surface its inline required error',
        );
        // No round trip: the blank submit never reached the write endpoint.
        fake.assertNotSent(
          (r) =>
              (r.method == 'POST' || r.method == 'PUT') &&
              r.url.contains('escalation-policies'),
        );
      },
    );

    testWidgets('edit mode resolves a known id and renders the edit title', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final EscalationPolicy detail = EscalationPolicy.fromMap({
        'id': 'standard',
        'name': 'Standard',
        'steps': [
          {
            'id': 'step-1',
            'position': 0,
            'delay_minutes': 0,
            'target_type': 'channel',
            'channel': 'Slack #incidents',
          },
        ],
      });
      EscalationController.instance.seedForTest([detail]);

      await tester.pumpWidget(
        wrap(
          EscalationPolicyEditorView(id: detail.id),
          size: const Size(1280, 6000),
        ),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.escalation_editor_title_edit')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // OnCallScheduleView
  // ---------------------------------------------------------------------------

  group('OnCallScheduleView', () {
    testWidgets(
      'renders the title and the honest empty state when the API has no '
      'schedule',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 6000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        // The bare `Http.fake()` from setUp answers every read with an empty
        // envelope, i.e. this team has no on-call schedule.
        await tester.pumpWidget(
          wrap(const OnCallScheduleView(), size: const Size(1280, 6000)),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(find.text(trans('uptizm.teams.oncall_title')), findsOneWidget);
        expect(
          find.text(trans('uptizm.teams.oncall_empty_title')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.teams.oncall_create_button')),
          findsOneWidget,
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // The billing surface, which magic_starter renders and uptizm feeds
  // ---------------------------------------------------------------------------

  group('the billing surface reads uptizm own endpoints', () {
    setUp(() async {
      // The shipped config, loaded the way `Magic.init` does: the feature flag
      // decides whether `teams.billing` is registered at all, and the origin
      // decides whether a checkout call to action is reachable. Set BEFORE the
      // `magic_starter` singleton is first resolved, since the manager reads
      // both in its constructor.
      Config.set(
        'magic_starter',
        magicStarterConfig['magic_starter'] as Map<String, dynamic>,
      );

      // The shipped catalogue, because the screen renders the package's
      // `magic_starter.billing.*` keys now and this file's inline loader
      // deliberately no longer carries them.
      Translator.instance.setLoader(const _BundledLangLoader('en'));
      await Translator.instance.setLocale(const Locale('en'));
    });

    testWidgets(
      'the five live billing endpoints are hit and their shapes render',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 12000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final FakeNetworkDriver fake = Http.fake({
          'billing/plans': Http.response({
            'data': [
              {
                'id': 'free',
                'name': 'Free',
                'tagline': 'Kick the tires.',
                'monthly': 0,
                'annual': 0,
                'currency': 'usd',
                'ai_line': 'AI anomaly inbox.',
                'features': <String>[],
                'recommended': false,
                'limits': {'check_interval_sec': 180, 'ai': 'inbox'},
              },
              {
                'id': 'pro',
                'name': 'Pro',
                'tagline': 'Startups.',
                'monthly': 34,
                'annual': 29,
                'currency': 'usd',
                'ai_line': 'Full AI incident analysis.',
                'features': <String>[],
                'recommended': true,
                'limits': {'check_interval_sec': 30, 'ai': 'analysis'},
              },
            ],
          }),
          'billing/usage': Http.response({
            'monitors': {'used': 47, 'limit': 50},
            'responders': {'used': 3, 'limit': 3},
            'checks_this_month': {'used': 128400, 'limit': null},
          }),
          'billing/invoices': Http.response({
            'data': [
              {
                'id': 'in_test_1',
                'number': 'INV-0001',
                'date': '2026-06-01T00:00:00.000000Z',
                'amount': '\$29.00',
                'status': 'paid',
                'pdf_url': 'https://stripe.test/invoice.pdf',
              },
            ],
            'next_cursor': null,
          }),
          'billing/payment-method': Http.response({
            'available': true,
            'renewal_date': null,
            'brand': null,
            'last4': null,
            'exp_month': null,
            'exp_year': null,
          }),
          // Under its `data` envelope, which is the entitlement read's own
          // contract: the driver refuses a bare object, and stubbing one would
          // leave this case asserting against the degraded arm.
          'billing': Http.response({
            'data': {
              'plan': 'free',
              'plan_status': 'active',
              'subscribed': false,
              'renews': false,
              'provider': 'stripe',
              'manage_via': 'none',
            },
          }),
        });

        // Nothing is injected on purpose, and that is the whole point of this
        // case: `AppServiceProvider.registerBillingSurface` builds the
        // controller with no `billingService`, so the reads resolve
        // `Payments.billing`, the REAL `magic_payments` HTTP driver, and the
        // paths asserted below are the front/back contract with uptizm's own
        // `api/v1`. Every other billing case in this repo hands the controller
        // a fake and therefore cannot see a path change at all.
        //
        // The web rail is the one exception: `flutter test` runs on the
        // `dart:io` arm, whose web rail is legitimately null, and it is
        // registered through the manager's own override seam, which leaves
        // `Payments.billing` untouched.
        Payments.extend(PaymentsManager.webRole, _WebRailStandIn.new);
        AppServiceProvider.registerBillingSurface();

        await tester.pumpWidget(
          wrap(
            MagicStarter.view.make('teams.billing'),
            size: const Size(1280, 12000),
          ),
        );
        await tester.pump();
        await tester.pump();

        expect(tester.takeException(), isNull);

        // The five reads the screen fires at mount, each by its own path.
        fake.assertSent(
          (r) => r.method == 'GET' && r.url.endsWith('billing'),
        );
        fake.assertSent(
          (r) => r.method == 'GET' && r.url.contains('billing/plans'),
        );
        fake.assertSent(
          (r) => r.method == 'GET' && r.url.contains('billing/usage'),
        );
        fake.assertSent(
          (r) => r.method == 'GET' && r.url.contains('billing/invoices'),
        );
        fake.assertSent(
          (r) => r.method == 'GET' && r.url.contains('billing/payment-method'),
        );

        // And the decoded shapes reach the screen: both catalogue rows, the
        // usage readout, and the invoice row.
        expect(find.text('Free'), findsWidgets);
        expect(find.text('Pro'), findsOneWidget);
        expect(find.textContaining('47'), findsWidgets);
        expect(find.text('INV-0001'), findsOneWidget);

        // A payload the rail ANSWERED with no card on it renders no masked
        // number and says there is no card, rather than reporting a failure the
        // read did not have. `available: true` is what tells the two apart, and
        // it is uptizm's backend that emits it.
        expect(find.textContaining('••••'), findsNothing);
        expect(
          find.text(trans('magic_starter.billing.payment_none')),
          findsOneWidget,
        );
        expect(find.text(trans('common.error_occurred')), findsNothing);
      },
    );
  });
}
