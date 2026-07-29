import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/escalation_controller.dart';
import 'package:uptizm/app/models/escalation_policy.dart';
import 'package:uptizm/app/support/billing_types.dart' show Plan;
import 'package:uptizm/app/support/team_types.dart'
    show PaymentMethod, UsageStat;
import 'package:uptizm/app/mocks/billing.dart' show plans;
import 'package:uptizm/app/services/billing/billing_service.dart';
import 'package:uptizm/resources/views/teams/escalation_policies_view.dart';
import 'package:uptizm/resources/views/teams/escalation_policy_editor_view.dart';
import 'package:uptizm/resources/views/teams/on_call_schedule_view.dart';
import 'package:uptizm/resources/views/teams/plan_billing_view.dart';

/// In-memory [BillingService] fake for [PlanBillingView] wiring tests.
///
/// Records every [checkout] call's `plan` and lets a test configure a canned
/// [entitlementPlan] (`currentEntitlement`) or a [checkoutError] to throw from
/// [checkout], so a widget test can assert the live-wiring branch (web
/// checkout, mobile-deferred, error) without a real network driver.
class _FakeBillingService implements BillingService {
  _FakeBillingService({this.entitlementPlan, this.checkoutError});

  /// The plan id [currentEntitlement] resolves to; `null` mirrors an absent
  /// entitlement field.
  final String? entitlementPlan;

  /// When set, [checkout] throws this instead of returning a session.
  final BillingException? checkoutError;

  /// Every `plan` passed to [checkout], in call order.
  final List<String> checkoutPlans = [];

  @override
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required String successUrl,
    required String cancelUrl,
  }) async {
    checkoutPlans.add(plan);
    if (checkoutError != null) throw checkoutError!;
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
    return BillingEntitlement(
      plan: entitlementPlan,
      status: 'active',
      aiAnalysisTrialsRemaining: null,
      raw: {'plan': entitlementPlan, 'status': 'active'},
    );
  }

  /// Returns the design-lab plan catalog verbatim (mirrors the live
  /// cheapest-to-priciest order the widget under test relies on for its CTA
  /// assertions), so the plans grid renders without a real network driver.
  @override
  Future<List<Plan>> getPlans() async => plans;

  @override
  Future<List<UsageStat>> getUsage() async => const [];

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) async {
    return const BillingInvoicesPage(invoices: [], nextCursor: null);
  }

  @override
  Future<PaymentMethod> getPaymentMethod() async => const PaymentMethod();
}

/// In-memory language loader supplying every [trans] key exercised by the
/// uptizm team-operations views' smoke tests (notification channels,
/// escalation policies + editor, on-call schedule, plan & billing). Team
/// create/settings/members and invitation acceptance moved to magic_starter,
/// so their keys are gone. Short, wrappable strings avoid RenderFlex overflow
/// at the test viewport.
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

      // Plan & billing.
      'uptizm.teams.billing_title': 'Plan & billing',
      'uptizm.teams.billing_description': 'Your plan and usage.',
      'uptizm.teams.billing_plan_current_badge': 'Current',
      'uptizm.teams.billing_renewal_text':
          ':price/mo billed :cycle · renews :date',
      'uptizm.teams.billing_renewal_cycle_annual': 'annually',
      'uptizm.teams.billing_renewal_cycle_monthly': 'monthly',
      'uptizm.teams.billing_plans_heading': 'Plans',
      'uptizm.teams.billing_plans_monthly': 'Monthly',
      'uptizm.teams.billing_plans_annual': 'Annual',
      'uptizm.teams.billing_plan_recommended_badge': 'Recommended',
      'uptizm.teams.billing_plan_price_monthly': '/mo',
      'uptizm.teams.billing_plan_price_custom': 'Custom',
      'uptizm.teams.billing_plan_billing_custom': 'Tailored to your scale',
      'uptizm.teams.billing_plan_billing_annual': 'billed annually',
      'uptizm.teams.billing_plan_billing_free': 'free forever',
      'uptizm.teams.billing_plan_billing_monthly': 'billed monthly',
      'uptizm.teams.billing_plan_button_current': 'Current plan',
      'uptizm.teams.billing_plan_button_contact': 'Contact sales',
      'uptizm.teams.billing_plan_button_upgrade': 'Upgrade',
      'uptizm.teams.billing_plan_button_downgrade': 'Downgrade',
      'uptizm.teams.billing_plan_button_unresolved': 'View plan',
      'uptizm.teams.billing_payment_header': 'Payment method',
      'uptizm.teams.billing_payment_expires': 'Expires :date',
      'uptizm.teams.billing_payment_update_button': 'Update',
      'uptizm.teams.billing_invoices_header': 'Billing history',
      'uptizm.teams.billing_invoice_receipt_button': 'Receipt',
      'uptizm.teams.billing_toast_contact_title': "We'll be in touch",
      'uptizm.teams.billing_toast_contact_description': 'Sales will reach out.',
      'uptizm.teams.billing_toast_upgrade_title': 'Upgrading to :name',
      'uptizm.teams.billing_toast_switch_title': 'Switching to :name',
      'uptizm.teams.billing_toast_change_description': 'Billed :cycle.',
      'uptizm.teams.billing_toast_deferred_title': 'Billing coming soon',
      'uptizm.teams.billing_toast_checkout_failed_title': 'Checkout failed',
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
    // (the PlanBillingView CTA calls Magic.success/error/MagicFeedback.info,
    // which fall through to a warning log when no navigator context is
    // mounted, as here; mirrors monitor_controller_test.dart).
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

  /// Wraps [widget] like [wrap], but pins the [MaterialApp]'s navigator key to
  /// [MagicRouter.instance.navigatorKey] so [MagicFeedback] (`Magic.success`/
  /// `Magic.error`/`MagicFeedback.info`) resolves a mounted context and
  /// actually renders its `SnackBar`, instead of degrading to a logged
  /// warning. Needed by the notification-channels honest-toast assertions and
  /// the billing-toast assertions below; the shared [wrap] stays as-is for
  /// every other group in this file.
  Widget wrapWithSnackbar(Widget widget, {Size size = const Size(1280, 8000)}) {
    // WindTheme sits ABOVE the MaterialApp so the Navigator overlay (where
    // MagicFeedback now inserts its toast) can resolve it, mirroring the real
    // app's root-level WindTheme. A WindTheme under `home` is a sibling of the
    // overlay, so a Wind-built toast would throw "No WindTheme found in
    // context".
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(
        navigatorKey: MagicRouter.instance.navigatorKey,
        home: MediaQuery(
          data: MediaQueryData(size: size),
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
  // PlanBillingView
  // ---------------------------------------------------------------------------

  group('PlanBillingView', () {
    testWidgets('renders the title and the billing-history heading', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 8000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const PlanBillingView(), size: const Size(1280, 8000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.billing_title')), findsOneWidget);
      expect(
        find.text(trans('uptizm.teams.billing_invoices_header')),
        findsOneWidget,
      );
    });

    testWidgets(
      'selecting an upgrade plan on web calls BillingService.checkout',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 8000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final _FakeBillingService billing = _FakeBillingService(
          entitlementPlan: 'pro',
        );

        await tester.pumpWidget(
          wrap(
            PlanBillingView(billingService: billing),
            size: const Size(1280, 8000),
          ),
        );
        await tester.pump();

        // Business sits above the fixture default ("pro"), so its CTA reads
        // "Upgrade" and is enabled.
        await tester.tap(
          find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(billing.checkoutPlans, ['business']);
      },
    );

    testWidgets(
      'mobile-deferred: UnsupportedPlatformException surfaces an info toast, '
      'not an error',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 8000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final _FakeBillingService billing = _FakeBillingService(
          entitlementPlan: 'pro',
          checkoutError: const UnsupportedPlatformException(
            'In-app purchases are not yet available on this platform.',
          ),
        );

        await tester.pumpWidget(
          wrapWithSnackbar(PlanBillingView(billingService: billing)),
        );
        await tester.pump();

        await tester.tap(
          find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
        );
        await tester.pump();

        // The checkout call still fires (and fails); the failure must not
        // crash the screen, and must surface the friendly deferred title
        // rather than the checkout-failed error title.
        expect(tester.takeException(), isNull);
        expect(billing.checkoutPlans, ['business']);
        expect(
          find.text(trans('uptizm.teams.billing_toast_deferred_title')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.teams.billing_toast_checkout_failed_title')),
          findsNothing,
        );
        // Flush the overlay toast's auto-dismiss timer so it does not leak past
        // the test (the framework's !timersPending invariant).
        await tester.pump(const Duration(seconds: 5));
        await tester.pumpAndSettle();
      },
    );

    testWidgets('a failed checkout surfaces the checkout-failed error toast', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 8000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final _FakeBillingService billing = _FakeBillingService(
        entitlementPlan: 'pro',
        checkoutError: const BillingException('No payment method.'),
      );

      await tester.pumpWidget(
        wrapWithSnackbar(PlanBillingView(billingService: billing)),
      );
      await tester.pump();

      await tester.tap(
        find.text(trans('uptizm.teams.billing_plan_button_upgrade')),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.billing_toast_checkout_failed_title')),
        findsOneWidget,
      );
      // Flush the overlay toast's auto-dismiss timer so it does not leak past
      // the test (the framework's !timersPending invariant).
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();
    });

    testWidgets('the loaded entitlement plan becomes the current plan', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 8000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final _FakeBillingService billing = _FakeBillingService(
        entitlementPlan: 'business',
      );

      await tester.pumpWidget(
        wrap(
          PlanBillingView(billingService: billing),
          size: const Size(1280, 8000),
        ),
      );
      await tester.pump();
      await tester.pump();

      expect(tester.takeException(), isNull);
      // Business is now the current plan, so exactly one CTA reads
      // "Current plan" (Business's own), and both Free and Pro's CTAs read
      // "Downgrade" since Business now outranks them.
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_current')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.billing_plan_button_downgrade')),
        findsNWidgets(2),
      );
    });

    testWidgets(
      'fetches the 4 live billing endpoints and renders the decoded shapes',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 10000));
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
            'renewal_date': null,
            'brand': null,
            'last4': null,
            'exp_month': null,
            'exp_year': null,
          }),
        });

        await tester.pumpWidget(
          wrap(const PlanBillingView(), size: const Size(1280, 10000)),
        );
        await tester.pump();
        await tester.pump();

        expect(tester.takeException(), isNull);

        // The 4 endpoints were all hit.
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

        // Plans grid renders both tiers. `GET /billing` (the entitlement
        // read) is not stubbed here, so it degrades silently and
        // [_currentPlanId] stays unresolved: the current-plan card renders
        // its loading skeleton instead of guessing, so "Pro" only appears
        // once (in the plans grid).
        expect(find.text('Free'), findsOneWidget);
        expect(find.text('Pro'), findsOneWidget);

        // Usage meters render the used/limit readout.
        expect(find.textContaining('47'), findsWidgets);

        // Invoices list renders the fetched row.
        expect(find.text('INV-0001'), findsOneWidget);

        // The all-null payment-method payload (Stripe soft-fail) renders an
        // empty/updatable state instead of crashing: no masked card number,
        // but the "Update" action is still present.
        expect(find.textContaining('••••'), findsNothing);
        expect(
          find.text(trans('uptizm.teams.billing_payment_update_button')),
          findsOneWidget,
        );
      },
    );
  });
}
