import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/entitlement_controller.dart';
import 'package:uptizm/app/enums/ai_level.dart' show AiLevel;
import 'package:uptizm/app/mocks/billing.dart' show plans;
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/services/billing/billing_service.dart';
import 'package:uptizm/app/support/billing_types.dart' show Plan, PlanLimits;
import 'package:uptizm/app/support/team_types.dart'
    show PaymentMethod, UsageStat;
import 'package:uptizm/resources/views/monitors/monitor_form.dart';
import 'package:uptizm/ui/components/key_value_editor/key_value_editor.dart'
    show KeyValueRow;

/// In-memory [BillingService] fake feeding [EntitlementController] a fixed
/// plan id, mirroring the fake in `entitlement_controller_test.dart`. Only
/// the three reads the controller depends on are implemented; the
/// purchase-action methods this form never touches throw loudly.
class _FakeBilling implements BillingService {
  _FakeBilling({this.entitlementPlan, List<Plan>? catalog})
    : _catalog = catalog ?? plans;

  /// The plan id `currentEntitlement` resolves to.
  final String? entitlementPlan;

  /// The catalog `getPlans` resolves to. Defaults to the shared design-lab
  /// fixture; a test that needs a specific `limits.regions` value (the shared
  /// fixture predates that field and defaults it to null/unlimited) supplies
  /// its own catalog instead.
  final List<Plan> _catalog;

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    return BillingEntitlement(
      plan: entitlementPlan,
      status: 'active',
      aiAnalysisTrialsRemaining: null,
      raw: {'plan': entitlementPlan, 'status': 'active'},
    );
  }

  @override
  Future<List<Plan>> getPlans() async => _catalog;

  @override
  Future<List<UsageStat>> getUsage() async => const [];

  @override
  Future<BillingCheckoutSession> checkout({
    required String plan,
    required String successUrl,
    required String cancelUrl,
  }) => throw UnimplementedError();

  @override
  Future<void> swap({required String plan}) => throw UnimplementedError();

  @override
  Future<void> cancel() => throw UnimplementedError();

  @override
  Future<String> openPortal({String? returnUrl}) => throw UnimplementedError();

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) =>
      throw UnimplementedError();

  @override
  Future<PaymentMethod> getPaymentMethod() => throw UnimplementedError();
}

/// In-memory language loader supplying the [trans] keys [MonitorForm]
/// exercises, mirroring the pattern in `test/resources/views/monitor_form_test.dart`.
class _MonitorWriteLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.monitors.form_field_name_label': 'Name',
      'uptizm.monitors.form_field_name_placeholder': 'e.g. API gateway',
      'uptizm.monitors.form_type_label': 'Type',
      'uptizm.monitors.form_url_label': 'URL or host',
      'uptizm.monitors.form_url_hint_http': 'Must start with https://',
      'uptizm.monitors.form_url_hint_other': 'Hostname or IP',
      'uptizm.monitors.form_url_placeholder': 'https://example.com/health',
      'uptizm.monitors.form_name_error_required': 'Name is required.',
      'uptizm.monitors.form_interval_label': 'Check interval',
      'uptizm.monitors.form_regions_label': 'Probe regions',
      'uptizm.monitors.form_regions_hint': 'Select at least one region.',
      'uptizm.monitors.form_slo_label': 'Uptime SLO',
      'uptizm.monitors.form_slo_hint': 'Set an error-budget target.',
      'uptizm.monitors.form_notifications_title': 'Notifications',
      'uptizm.monitors.form_notifications_hint': 'When to alert.',
      'uptizm.monitors.form_alert_down': 'Alert when down',
      'uptizm.monitors.form_alert_recover': 'Alert on recovery',
      'uptizm.monitors.form_escalation_label': 'Escalation policy',
      'uptizm.monitors.form_escalation_hint': 'Who gets paged.',
      'uptizm.monitors.form_escalation_none': 'Team default',
      'uptizm.monitors.form_escalation_empty': 'No escalation policies yet.',
      'uptizm.monitors.interval_custom': 'Every :seconds seconds',
      'uptizm.monitors.interval_30s': 'Every 30 seconds',
      'uptizm.monitors.interval_3m': 'Every 3 minutes',
      'uptizm.monitors.form_advanced_label': 'Advanced configuration',
      'uptizm.monitors.form_advanced_hint': 'HTTP method, headers, timeout.',
      'uptizm.monitors.form_method_label': 'HTTP method',
      'uptizm.monitors.form_headers_label': 'Request headers',
      'uptizm.monitors.form_headers_hint': 'Key / value pairs.',
      'uptizm.monitors.form_body_label': 'Request body',
      'uptizm.monitors.form_body_placeholder': 'JSON payload',
      'uptizm.monitors.form_timeout_label': 'Timeout (seconds)',
      'uptizm.monitors.form_timeout_hint': 'Max wait for a response.',
      'uptizm.monitors.form_cancel': 'Cancel',
      'uptizm.monitors.form_submit_create': 'Create monitor',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card, Button, Input, and the other
    // magic_starter widgets resolve their themes without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Bind LogManager so the EntitlementController's offline-degradation path
    // (Log.error on the failed billing fetch the monitor form triggers via
    // EntitlementController.instance) resolves instead of throwing.
    Magic.singleton('log', () => LogManager());

    Translator.instance.setLoader(_MonitorWriteLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in the standard test harness. Pumping [MonitorForm] alone
  /// (not the full create/edit view inside the app layout) avoids the
  /// LayoutBuilder/items-stretch crash the app shell can hit in a bare widget
  /// test.
  Widget wrap(Widget widget, {Size size = const Size(1200, 1600)}) {
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

  group('MonitorForm.onSubmit', () {
    testWidgets('Submit reports the built field map with seconds-converted '
        'interval and the booleans', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Map<String, dynamic>? captured;

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            initialName: 'API gateway',
            initialUrl: 'https://api.example.com/health',
            initialType: 'http',
            initialInterval: '1m',
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (fields) async {
              captured = fields;
              return <String, String>{};
            },
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      // Edit the name field so the captured map reflects a live user edit,
      // not just the initial values.
      await tester.enterText(
        find.widgetWithText(MSInput, 'API gateway'),
        'API gateway (edited)',
      );
      await tester.pump();

      final Finder submitButton = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.form_submit_create'),
      );
      await tester.ensureVisible(submitButton);
      await tester.pump();
      await tester.tap(submitButton);
      await tester.pump();

      expect(
        captured,
        isNotNull,
        reason: 'Submit must invoke onSubmit with the built field map',
      );
      expect(captured!['name'], equals('API gateway (edited)'));
      expect(captured!['url'], equals('https://api.example.com/health'));
      expect(captured!['type'], equals('http'));
      expect(captured!['method'], equals('get'));
      expect(
        captured!['check_interval_sec'],
        equals(60),
        reason: '1m must be sent as 60 seconds, not the "1m" token',
      );
      expect(captured!['alert_on_down'], isTrue);
      expect(captured!['alert_on_recover'], isTrue);
      expect(captured!['show_on_status_page'], isTrue);
      expect(captured!['only_show_if_degraded'], isFalse);
      expect(captured!['ssl_tracking'], isTrue);
    });

    testWidgets('a blank Name blocks submit and shows the inline name error', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      bool submitCalled = false;

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            // A valid target but a blank Name: the client-side required check
            // must block the round trip on Name before onSubmit fires.
            initialUrl: 'https://api.example.com/health',
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async {
              submitCalled = true;
              return <String, String>{};
            },
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final Finder submitButton = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.form_submit_create'),
      );
      await tester.ensureVisible(submitButton);
      await tester.tap(submitButton);
      await tester.pump();

      expect(
        submitCalled,
        isFalse,
        reason: 'A blank Name must not fire a round trip',
      );
      expect(
        find.text(trans('uptizm.monitors.form_name_error_required')),
        findsOneWidget,
        reason: 'The required-name error must render inline under the field',
      );
    });

    testWidgets('a server 422 renders the message under the matching field', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      const String serverMessage = 'That name is already taken.';

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            // A fully valid client-side form: the only rejection is the server's
            // per-field 422, which must land under the Name field.
            initialName: 'API gateway',
            initialUrl: 'https://api.example.com/health',
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async => const {'name': serverMessage},
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final Finder submitButton = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.form_submit_create'),
      );
      await tester.ensureVisible(submitButton);
      await tester.tap(submitButton);
      await tester.pump();

      expect(
        find.text(serverMessage),
        findsOneWidget,
        reason: 'The server 422 message must render inline under the field',
      );
    });

    testWidgets('Cancel does not invoke onSubmit', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      bool submitCalled = false;
      bool cancelCalled = false;

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async {
              submitCalled = true;
              return <String, String>{};
            },
            onCancel: () => cancelCalled = true,
          ),
        ),
      );
      await tester.pump();

      final Finder cancelButton = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.form_cancel'),
      );
      await tester.ensureVisible(cancelButton);
      await tester.pump();
      await tester.tap(cancelButton);
      await tester.pump();

      expect(
        submitCalled,
        isFalse,
        reason: 'Cancel must never invoke onSubmit (no write on cancel)',
      );
      expect(cancelCalled, isTrue);
    });
  });

  group('MonitorForm edit payload', () {
    /// Pumps an edit-mode form seeded exactly as `MonitorEditView` seeds it from
    /// a real monitor, renames it, and returns the posted field map.
    Future<Map<String, dynamic>> submitRename(WidgetTester tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Map<String, dynamic>? captured;

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            isEdit: true,
            initialName: 'Checkout',
            initialUrl: 'https://checkout.example.com/health',
            initialType: 'http',
            initialIntervalSec: 180,
            initialRegions: const ['eu-west'],
            initialHeaders: const [
              KeyValueRow(key: 'X-Api-Key', value: 'real-secret-header'),
            ],
            initialSlo: '99.99',
            initialMethod: 'head',
            initialTimeoutSec: '45',
            initialAiMode: 'suggest',
            initialAlertOnDown: false,
            initialAlertOnRecover: false,
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (fields) async {
              captured = fields;
              return <String, String>{};
            },
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      await tester.enterText(
        find.widgetWithText(MSInput, 'Checkout'),
        'Checkout (renamed)',
      );
      await tester.pump();

      final Finder submitButton = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.form_submit_create'),
      );
      await tester.ensureVisible(submitButton);
      await tester.pump();
      await tester.tap(submitButton);
      await tester.pump();

      expect(captured, isNotNull);
      return captured!;
    }

    testWidgets('a rename preserves every field the operator configured', (
      tester,
    ) async {
      // The regression this pins: MonitorEditView used to pass only name, url
      // and regions, so the form filled the rest with CREATE defaults and the
      // resulting PUT overwrote them. Renaming a monitor reset its method,
      // interval, timeout and SLO, and replaced its real request headers with
      // the literal placeholder `Authorization: Bearer …`, which then went out
      // to the monitored endpoint on every probe.
      final Map<String, dynamic> fields = await submitRename(tester);

      expect(fields['name'], equals('Checkout (renamed)'));
      expect(
        fields['request_headers'],
        equals({'X-Api-Key': 'real-secret-header'}),
        reason: 'the operator\'s real auth header must survive a rename',
      );
      expect(
        fields['check_interval_sec'],
        equals(180),
        reason: '180s has no preset option, so it must round-trip verbatim '
            'rather than snap to a preset',
      );
      expect(fields['method'], equals('head'));
      expect(fields['timeout_sec'], equals(45));
      expect(fields['slo_target'], equals(99.99));
      expect(fields['ai_mode'], equals('suggest'));
      expect(fields['alert_on_down'], isFalse);
      expect(fields['alert_on_recover'], isFalse);
    });

    testWidgets('an edit omits the settings the form exposes no control for', (
      tester,
    ) async {
      // These have no UI, so sending a default for them on an edit silently
      // resets the monitor's auth config, tags, status-page placement and SSL
      // tracking. Every UpdateMonitorRequest rule is `sometimes`, so omitting
      // them is the intended partial-update shape.
      final Map<String, dynamic> fields = await submitRename(tester);

      for (final String unowned in const <String>[
        'auth_config',
        'expected_status_code',
        'tags',
        'show_on_status_page',
        'only_show_if_degraded',
        'ssl_tracking',
        'ssl_alert_threshold_days',
      ]) {
        expect(
          fields.containsKey(unowned),
          isFalse,
          reason: '$unowned has no form control, so an edit must not post it',
        );
      }
    });

    testWidgets('every posted field survives the ORM mass-assignment filter', (
      tester,
    ) async {
      // The gap this closes. The form built a correct payload, but the ORM strips
      // any key missing from Monitor.fillable BEFORE the request is sent, so the
      // write vanished with a 2xx and no validation error to surface. Both
      // `ai_mode` (which gates the AI suggestion sweep) and
      // `escalation_policy_id` (which selects the paging ladder) were lost that
      // way while the UI showed the operator their choice had been saved.
      final Map<String, dynamic> fields = await submitRename(tester);
      final List<String> fillable = Monitor().fillable;

      for (final String key in fields.keys) {
        expect(
          fillable,
          contains(key),
          reason: 'the form posts "$key", so Monitor.fillable must carry it or '
              'the ORM drops it before the request leaves the client',
        );
      }
    });

    testWidgets('a create still posts the full request shape', (tester) async {
      // The other half of the contract: a create has no existing row to
      // preserve, so it must keep sending the defaults the backend expects.
      await tester.binding.setSurfaceSize(const Size(1200, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Map<String, dynamic>? captured;

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            initialName: 'API gateway',
            initialUrl: 'https://api.example.com/health',
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (fields) async {
              captured = fields;
              return <String, String>{};
            },
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final Finder submitButton = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.form_submit_create'),
      );
      await tester.ensureVisible(submitButton);
      await tester.pump();
      await tester.tap(submitButton);
      await tester.pump();

      expect(captured, isNotNull);
      expect(captured!.containsKey('tags'), isTrue);
      expect(captured!.containsKey('show_on_status_page'), isTrue);
      expect(captured!.containsKey('ssl_tracking'), isTrue);
      expect(captured!.containsKey('auth_config'), isTrue);
    });
  });

  group('MonitorForm interval default from entitlement', () {
    /// Finds the interval [MSSelect], identified by carrying a `'3m'` option
    /// (the Free-tier floor token), rather than by position among the form's
    /// several selects (SLO, escalation, interval).
    MSSelect<String> intervalSelect(WidgetTester tester) {
      return tester.widget<MSSelect<String>>(
        find.byWidgetPredicate(
          (widget) =>
              widget is MSSelect<String> &&
              widget.options.any((o) => o.value == '3m'),
        ),
      );
    }

    testWidgets(
      'a Free entitlement seeds the create form on 3m, unlocked, once the '
      'plan resolves (not the pre-fetch permissive default)',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        // Register the fake-backed singleton BEFORE the form resolves
        // EntitlementController.instance in initState, so the form's own
        // self-trigger (`_ensureLoading`) drives this fake rather than a real
        // network call. The instance is left UNLOADED at this point on
        // purpose: the form must still land on the right default once the
        // async load resolves, not only when the plan is already warm.
        final controller = EntitlementController(
          billing: _FakeBilling(entitlementPlan: 'free'),
        );
        Magic.findOrPut(() => controller);

        Map<String, dynamic>? captured;

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              initialName: 'API gateway',
              initialUrl: 'https://api.example.com/health',
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (fields) async {
                captured = fields;
                return <String, String>{};
              },
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();

        // Let the fake billing reads resolve and the form react to the
        // now-loaded entitlement.
        for (var i = 0; i < 20 && !controller.isLoaded; i++) {
          await tester.pump(const Duration(milliseconds: 1));
        }
        await tester.pump();

        final MSSelect<String> select = intervalSelect(tester);
        expect(
          select.value,
          equals('3m'),
          reason: 'A Free entitlement floor (180s) must seed the default at '
              'the nearest offered token, 3m, not the locked 30s literal',
        );
        final SelectOption<String> option3m = select.options.firstWhere(
          (o) => o.value == '3m',
        );
        expect(
          option3m.disabled,
          isFalse,
          reason: '3m is exactly the Free floor, so it must not be locked',
        );

        final Finder submitButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_create'),
        );
        await tester.ensureVisible(submitButton);
        await tester.pump();
        await tester.tap(submitButton);
        await tester.pump();

        expect(captured, isNotNull);
        expect(
          captured!['check_interval_sec'],
          equals(180),
          reason: 'The POSTED interval must be the Free floor in seconds, '
              'not just the visible 3m label',
        );
      },
    );

    testWidgets(
      'a Pro entitlement still seeds the create form on 30s',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final controller = EntitlementController(
          billing: _FakeBilling(entitlementPlan: 'pro'),
        );
        Magic.findOrPut(() => controller);

        Map<String, dynamic>? captured;

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              initialName: 'API gateway',
              initialUrl: 'https://api.example.com/health',
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (fields) async {
                captured = fields;
                return <String, String>{};
              },
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();

        for (var i = 0; i < 20 && !controller.isLoaded; i++) {
          await tester.pump(const Duration(milliseconds: 1));
        }
        await tester.pump();

        final MSSelect<String> select = intervalSelect(tester);
        expect(
          select.value,
          equals('30s'),
          reason: 'A Pro entitlement floor (30s) leaves the 30s default in '
              'place; nothing forces it up',
        );
        final SelectOption<String> option30s = select.options.firstWhere(
          (o) => o.value == '30s',
        );
        expect(option30s.disabled, isFalse);

        final Finder submitButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_create'),
        );
        await tester.ensureVisible(submitButton);
        await tester.pump();
        await tester.tap(submitButton);
        await tester.pump();

        expect(captured, isNotNull);
        expect(captured!['check_interval_sec'], equals(30));
      },
    );

    testWidgets(
      'editing an existing monitor still shows its stored interval even '
      'when it is now locked under the loaded plan',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        // The monitor was created on a faster plan and stored at 60s (1m);
        // the team now sits on Free (floor 180s), which would lock 1m. The
        // edit form must still show and post the monitor's real interval,
        // never silently snap it up to the new floor.
        final controller = EntitlementController(
          billing: _FakeBilling(entitlementPlan: 'free'),
        );
        Magic.findOrPut(() => controller);
        await controller.reload();

        Map<String, dynamic>? captured;

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              isEdit: true,
              initialName: 'Checkout',
              initialUrl: 'https://checkout.example.com/health',
              initialType: 'http',
              initialIntervalSec: 60,
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (fields) async {
                captured = fields;
                return <String, String>{};
              },
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();
        await tester.pump();

        final MSSelect<String> select = intervalSelect(tester);
        expect(
          select.value,
          equals('1m'),
          reason: 'The monitor\'s own stored interval must render verbatim, '
              'even though it is now locked under the current plan',
        );

        final Finder submitButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_create'),
        );
        await tester.ensureVisible(submitButton);
        await tester.pump();
        await tester.tap(submitButton);
        await tester.pump();

        expect(captured, isNotNull);
        expect(
          captured!['check_interval_sec'],
          equals(60),
          reason:
              'An edit must never override the stored interval toward the '
              'plan floor',
        );
      },
    );
  });

  group('MonitorForm regions gate from entitlement', () {
    /// A one-region Free tier and a five-region Pro tier, built locally rather
    /// than pulled from the shared `app/mocks/billing.dart` fixture: that
    /// fixture predates `PlanLimits.regions` and defaults every tier to
    /// unlimited, which would defeat both tests below.
    const Plan freeOneRegion = Plan(
      id: 'free',
      name: 'Free',
      tagline: 'Kick the tires, solo projects.',
      monthly: 0,
      annual: 0,
      aiLine: '',
      features: [],
      limits: PlanLimits(
        monitors: 1,
        checkIntervalSec: 180,
        statusPages: 1,
        subscribers: 100,
        responders: 1,
        regions: 1,
        ai: AiLevel.inbox,
        whiteLabel: false,
        privatePages: false,
        sso: false,
      ),
    );

    const Plan proFiveRegions = Plan(
      id: 'pro',
      name: 'Pro',
      tagline: 'Startups and small teams that page.',
      monthly: 34,
      annual: 29,
      aiLine: '',
      features: [],
      recommended: true,
      limits: PlanLimits(
        monitors: 50,
        checkIntervalSec: 30,
        statusPages: 3,
        subscribers: 1000,
        responders: 3,
        regions: 5,
        ai: AiLevel.analysis,
        whiteLabel: false,
        privatePages: false,
        sso: false,
      ),
    );

    testWidgets(
      'a Free entitlement is one region, swapped rather than locked',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final controller = EntitlementController(
          billing: _FakeBilling(
            entitlementPlan: 'free',
            catalog: const [freeOneRegion, proFiveRegions],
          ),
        );
        Magic.findOrPut(() => controller);
        await controller.reload();

        Map<String, dynamic>? captured;

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              initialName: 'API gateway',
              initialUrl: 'https://api.example.com/health',
              // Start at exactly the Free allowance (one region), so the
              // cap is not already broken before the user touches anything.
              initialRegions: const ['us-east'],
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (fields) async {
                captured = fields;
                return <String, String>{};
              },
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();
        await tester.pump();

        // No tile advertises a plan. Reported from a live session: at a cap of
        // one every other tile read "EU West · Pro", which blames the region.
        // No region is gated (every plan probes from every region); the plan
        // limits HOW MANY at once, so the suffix invited an upgrade for a
        // reason that does not exist.
        expect(
          find.textContaining('· Pro'),
          findsNothing,
          reason: 'the count limit is not a property of any one region',
        );

        // It is stated once, under the grid, with the real allowance and the
        // plan that raises it.
        expect(
          find.text(
            trans('uptizm.monitors.form_regions_cap_notice_upgrade_one', {
              'count': '1',
              'plan': 'Free',
              'upgrade': 'Pro',
            }),
          ),
          findsOneWidget,
          reason: 'one honest line replaces five misleading suffixes',
        );

        // At a cap of one the grid is a radio group: tapping another region
        // SWAPS. Locking it left a Free operator unable to change region at
        // all, since the only route was clearing the selection first.
        await tester.tap(find.text('US West'));
        await tester.pump();

        final Finder submitButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_create'),
        );
        await tester.ensureVisible(submitButton);
        await tester.pump();
        await tester.tap(submitButton);
        await tester.pump();

        expect(captured, isNotNull);
        expect(
          captured!['regions'],
          equals(['us-west']),
          reason:
              'the tap replaced the selection rather than being refused, and '
              'the cap still holds at exactly one region',
        );
      },
    );

    testWidgets(
      'a Free operator cannot clear their only region',
      (tester) async {
        // `regions` is `required|array|min:1` on the backend, so an empty
        // selection has nowhere to go. At a cap of one, a tap on the SELECTED
        // tile is therefore a no-op rather than a route to a guaranteed 422.
        await tester.binding.setSurfaceSize(const Size(1200, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final controller = EntitlementController(
          billing: _FakeBilling(
            entitlementPlan: 'free',
            catalog: const [freeOneRegion, proFiveRegions],
          ),
        );
        Magic.findOrPut(() => controller);
        await controller.reload();

        Map<String, dynamic>? captured;

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              initialName: 'API gateway',
              initialUrl: 'https://api.example.com/health',
              initialRegions: const ['us-east'],
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (fields) async {
                captured = fields;
                return <String, String>{};
              },
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();
        await tester.pump();

        await tester.tap(find.text('US East'));
        await tester.pump();

        final Finder submitButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_create'),
        );
        await tester.ensureVisible(submitButton);
        await tester.pump();
        await tester.tap(submitButton);
        await tester.pump();

        expect(
          captured!['regions'],
          equals(['us-east']),
          reason: 'the selected tile stays selected at a cap of one',
        );
      },
    );

    // Found by walking the real create screen, not by this suite: the form's
    // own default is TWO regions, the cap only locks tiles that are not already
    // selected, and a create-time default is nobody's deliberate pick. So a
    // Free operator opened the screen with a selection their plan refuses and
    // would have learned it from a 422 on save, which is the failure this whole
    // gate exists to prevent. The test above sidesteps it by passing a
    // one-region baseline; this one uses the default the screen actually ships.
    testWidgets(
      'the create form never opens a Free plan above its region allowance',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final controller = EntitlementController(
          billing: _FakeBilling(
            entitlementPlan: 'free',
            catalog: const [freeOneRegion, proFiveRegions],
          ),
        );
        Magic.findOrPut(() => controller);
        await controller.reload();

        Map<String, dynamic>? captured;

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              initialName: 'API gateway',
              initialUrl: 'https://api.example.com/health',
              // No initialRegions override: this is the shipped default.
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (fields) async {
                captured = fields;
                return <String, String>{};
              },
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();
        await tester.pump();

        final Finder submitButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_create'),
        );
        await tester.ensureVisible(submitButton);
        await tester.pump();
        await tester.tap(submitButton);
        await tester.pump();

        expect(captured, isNotNull);
        expect(
          (captured!['regions'] as List).length,
          equals(1),
          reason:
              'A Free plan allows one region, so the create form must not '
              'post two just because two is the built-in default',
        );
      },
    );

    testWidgets(
      'editing a grandfathered 5-region monitor keeps all five selected '
      'and the form saveable',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 1600));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        // The monitor was created on a plan with all 5 regions and the team
        // has since downgraded to Free (1 region); the backend's delta-only
        // gate keeps a monitor at its stored count, refusing only an
        // increase, so editing it must round-trip all five untouched.
        final controller = EntitlementController(
          billing: _FakeBilling(
            entitlementPlan: 'free',
            catalog: const [freeOneRegion, proFiveRegions],
          ),
        );
        Magic.findOrPut(() => controller);
        await controller.reload();

        const List<String> fiveRegions = [
          'us-east',
          'us-west',
          'eu-west',
          'eu-central',
          'ap',
        ];

        Map<String, dynamic>? captured;

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              isEdit: true,
              initialName: 'Checkout',
              initialUrl: 'https://checkout.example.com/health',
              initialType: 'http',
              initialRegions: fiveRegions,
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (fields) async {
                captured = fields;
                return <String, String>{};
              },
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();
        await tester.pump();

        // None of the five must render locked (a locked tile would carry the
        // " · <Plan>" suffix): a grandfathered monitor's stored regions must
        // never be silently dropped or shown as unreachable.
        expect(
          find.textContaining(' · Pro'),
          findsNothing,
          reason:
              'A monitor already holding 5 regions must not show any of its '
              'own stored regions as locked',
        );

        final Finder submitButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_create'),
        );
        await tester.ensureVisible(submitButton);
        await tester.pump();
        await tester.tap(submitButton);
        await tester.pump();

        expect(captured, isNotNull);
        expect(
          captured!['regions'],
          equals(fiveRegions),
          reason:
              'A grandfathered monitor must keep all five stored regions and '
              'the form must stay saveable on the current (downgraded) plan',
        );
      },
    );
  });
}
