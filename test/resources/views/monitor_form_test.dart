import 'dart:async';

import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/resources/views/monitors/monitor_create_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_edit_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_form.dart';

import '../../support/monitor_fixtures.dart';

/// In-memory language loader supplying all [trans] keys exercised by the
/// monitor create/edit/form widgets.
///
/// Short, wrappable strings avoid RenderFlex overflow at the test viewport and
/// mirror the pattern established in monitor_detail_view_test.dart.
class _MonitorFormLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // MonitorForm: field labels.
      'uptizm.monitors.form_field_name_label': 'Name',
      'uptizm.monitors.form_field_name_placeholder': 'e.g. API gateway',
      'uptizm.monitors.form_type_label': 'Type',
      // The AI-assist ladder. Absent from this loader, `trans()` answers with
      // the raw dotted key, three of them side by side in one segmented
      // control, and the row overflowed by 164px: a harness artifact that looks
      // exactly like a layout defect.
      'uptizm.monitors.form_ai_mode_label': 'AI assist',
      'uptizm.monitors.form_ai_mode_hint': 'What Uptizm may do on its own.',
      'uptizm.monitors.ai_mode_off': 'Off',
      'uptizm.monitors.ai_mode_suggest': 'Suggest',
      'uptizm.monitors.ai_mode_auto': 'Auto',
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
      'uptizm.monitors.form_advanced_label': 'Advanced configuration',
      'uptizm.monitors.form_advanced_hint': 'HTTP method, headers, timeout.',
      'uptizm.monitors.form_method_label': 'HTTP method',
      'uptizm.monitors.form_headers_label': 'Request headers',
      'uptizm.monitors.form_headers_hint': 'Key / value pairs.',
      // MonitorForm: the credential block.
      'uptizm.monitors.form_auth_label': 'Authentication',
      'uptizm.monitors.form_auth_hint': 'Sent with every check.',
      'uptizm.monitors.form_auth_type_none': 'None',
      'uptizm.monitors.form_auth_type_basic': 'Basic',
      'uptizm.monitors.form_auth_type_bearer': 'Bearer',
      'uptizm.monitors.form_auth_type_api_key': 'API key',
      'uptizm.monitors.form_auth_username_label': 'Username',
      'uptizm.monitors.form_auth_username_placeholder': 'svc-user',
      'uptizm.monitors.form_auth_password_label': 'Password',
      'uptizm.monitors.form_auth_token_label': 'Token',
      'uptizm.monitors.form_auth_key_label': 'API key',
      'uptizm.monitors.form_auth_header_label': 'Header name',
      'uptizm.monitors.form_auth_header_placeholder': 'X-Api-Key',
      'uptizm.monitors.form_auth_secret_stored_placeholder': 'Blank keeps it',
      'uptizm.monitors.form_auth_error_username_required': 'Enter a username.',
      'uptizm.monitors.form_auth_error_password_required': 'Enter a password.',
      'uptizm.monitors.form_auth_error_token_required': 'Enter a token.',
      'uptizm.monitors.form_auth_error_key_required': 'Enter a key.',
      'uptizm.monitors.form_auth_error_header_required': 'Enter a header.',
      'uptizm.monitors.form_auth_error_too_long': 'Max :max characters.',
      'uptizm.monitors.form_body_label': 'Request body',
      'uptizm.monitors.form_body_placeholder': 'JSON payload',
      'uptizm.monitors.form_timeout_label': 'Timeout (seconds)',
      'uptizm.monitors.form_timeout_hint': 'Max wait for a response.',
      'uptizm.monitors.form_cancel': 'Cancel',
      'uptizm.monitors.form_submit_create': 'Create monitor',
      'uptizm.monitors.form_submit_save': 'Save changes',

      // MonitorEditView.
      'uptizm.monitors.form_title_edit': 'Edit monitor',
      'uptizm.monitors.form_editing': 'Editing :name.',

      // MonitorCreateView.
      'uptizm.monitors.create_header_title': 'New monitor',
      'uptizm.monitors.create_header_description': 'Set up a new health check.',
      'uptizm.monitors.back_to_monitors': 'Back to monitors',
      'uptizm.monitors.create_mode_ai': 'AI setup',
      'uptizm.monitors.create_mode_manual': 'Manual',
      'uptizm.monitors.create_ai_card_title': 'AI setup',
      'uptizm.monitors.create_ai_card_description':
          'Paste a URL and AI configures the monitor.',
      'uptizm.monitors.create_ai_url_label': 'Endpoint URL',
      'uptizm.monitors.create_ai_url_placeholder':
          'https://api.example.com/health',
      'uptizm.monitors.create_ai_analyze_button': 'Analyze with AI',
      'uptizm.monitors.create_ai_analyze_failed': 'Check the URL is reachable.',
      'uptizm.monitors.create_ai_analyze_lost': 'That run expired, run it again.',
      'uptizm.monitors.create_ai_analyzing_title': 'Analyzing endpoint…',
      // The five step rows and the note a skipped one carries. Short strings on
      // purpose: without them each row lays out its ~34-character raw key and
      // the card overflows for a reason that has nothing to do with the state
      // machine under test.
      'uptizm.monitors.create_ai_step_1': 'Probing',
      'uptizm.monitors.create_ai_step_2': 'Digesting',
      'uptizm.monitors.create_ai_step_3': 'Measuring',
      'uptizm.monitors.create_ai_step_4': 'Suggesting',
      'uptizm.monitors.create_ai_step_5': 'Discovering',
      'uptizm.monitors.create_ai_analyze_skipped_note': 'not needed',
      'uptizm.monitors.create_ai_review_banner_title':
          'AI configured this monitor',
      'uptizm.monitors.create_ai_suggested_metrics': 'Suggested metrics',
      'uptizm.monitors.create_ai_suggested_metrics_help':
          'These will be tracked after creation.',

      // Not-found state.
      'uptizm.monitors.error_load_title': 'Monitor not found',
      'uptizm.monitors.error_load_description': 'No monitor with that id.',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card, Button, Input, SegmentedControl,
    // and other magic_starter widgets resolve their themes without a full app
    // boot, mirroring the pattern in monitor_detail_view_test.dart.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Bind LogManager so Log.error() works inside MonitorController.analyze's
    // failure path (it logs before surfacing the error toast).
    Magic.singleton('log', () => LogManager());

    // Bind an empty fake network so the wired controller resolves the `network`
    // service. MonitorEditView reads `controller.monitorById(id)` in build();
    // its onInit `reload()` and per-id `_refreshOne` fetch `GET /monitors[/:id]`
    // and the empty fake returns `{}` (no `data`), a decode no-op that leaves
    // the seeded inventory below untouched instead of clobbering it or throwing.
    Http.fake();
    // Seed the controller cache so `monitorById('api')` resolves the fixture
    // 'API gateway' monitor the edit-view tests assert against. The MonitorForm
    // and MonitorCreateView groups do not read the controller, so this is inert
    // for them.
    MonitorController.instance.seedForTest(monitorFixtures);

    // Load short prose so trans() returns human labels instead of raw keys.
    Translator.instance.setLoader(_MonitorFormLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  // ---------------------------------------------------------------------------
  // Wrap helper: MaterialApp > MediaQuery > WindTheme > Scaffold.
  //
  // The default size is 1200×900, matching desktop width. The form footer uses
  // auto-width buttons (no w-full inside flex-row), so any desktop width is
  // safe; the 1200px width stays above the sm breakpoint so responsive column
  // layouts lay out as intended.
  // ---------------------------------------------------------------------------

  /// Wraps [widget] in the standard test harness at the given [size].
  Widget wrap(Widget widget, {Size size = const Size(1200, 900)}) {
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
  // MonitorForm
  // ---------------------------------------------------------------------------

  group('MonitorForm', () {
    testWidgets('builds without exception at a desktop width', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async => <String, String>{},
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MonitorForm), findsOneWidget);
    });

    testWidgets('a blank target blocks submit and shows an inline error', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      var submitted = false;
      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async {
              submitted = true;
              return <String, String>{};
            },
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final submit = find.text(trans('uptizm.monitors.form_submit_create'));
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pump();

      expect(submitted, isFalse, reason: 'A blank target must not submit');
      expect(
        find.text(trans('uptizm.monitors.form_url_error_required')),
        findsOneWidget,
      );
    });

    testWidgets('a TCP target without a port shows the host:port error', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      var submitted = false;
      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            initialType: 'tcp',
            initialUrl: 'db.example.com',
            onSubmit: (_) async {
              submitted = true;
              return <String, String>{};
            },
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final submit = find.text(trans('uptizm.monitors.form_submit_create'));
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pump();

      expect(
        submitted,
        isFalse,
        reason: 'A portless TCP target must not submit',
      );
      expect(
        find.text(trans('uptizm.monitors.form_url_error_tcp')),
        findsOneWidget,
      );
    });

    testWidgets('a valid TCP host:port target submits', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      var submitted = false;
      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            initialName: 'Primary database',
            initialType: 'tcp',
            initialUrl: 'db.example.com:5432',
            onSubmit: (_) async {
              submitted = true;
              return <String, String>{};
            },
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final submit = find.text(trans('uptizm.monitors.form_submit_create'));
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pump();

      expect(submitted, isTrue, reason: 'A valid host:port target must submit');
    });

    testWidgets('a double tap submits once, not twice', (tester) async {
      // The regression this pins: the submit handler is async and was wired
      // straight to the button, so nothing stopped a second tap during the
      // await. On create that is not idempotent: two taps made two monitors,
      // each counting against the plan limit. A double tap costs nothing on web.
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      var submits = 0;
      final Completer<void> inFlight = Completer<void>();
      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            initialName: 'Primary database',
            initialType: 'tcp',
            initialUrl: 'db.example.com:5432',
            onSubmit: (_) async {
              submits++;
              // Hold the write open so the second tap lands mid-flight, which is
              // the only window the bug lived in.
              await inFlight.future;
              return <String, String>{};
            },
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final Finder label = find.text(
        trans('uptizm.monitors.form_submit_create'),
      );
      await tester.ensureVisible(label);
      await tester.tap(label);
      await tester.pump();

      // The label is gone mid-flight because WButton swaps its child for the
      // loading content, which is the same switch that drops its onTap. So the
      // second tap has to go through the button widget rather than its text.
      expect(
        label,
        findsNothing,
        reason: 'the in-flight button shows its loading content, not the label',
      );

      await tester.tap(find.byType(MSButton).last, warnIfMissed: false);
      await tester.pump();

      expect(submits, 1, reason: 'the second tap must be dropped');

      inFlight.complete();
      await tester.pumpAndSettle();

      expect(submits, 1, reason: 'and it must not fire once the first resolves');
    });

    testWidgets('advanced section is hidden by default', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async => <String, String>{},
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      // The method and timeout labels are only present when advanced is on.
      expect(
        find.text(trans('uptizm.monitors.form_method_label')),
        findsNothing,
        reason: 'Method label must be absent before advanced is toggled on',
      );
      expect(
        find.text(trans('uptizm.monitors.form_timeout_label')),
        findsNothing,
        reason: 'Timeout label must be absent before advanced is toggled on',
      );
    });

    testWidgets('toggling advanced reveals method, headers, and timeout', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 5000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async => <String, String>{},
            onCancel: () {},
          ),
          size: const Size(1200, 5000),
        ),
      );
      await tester.pump();

      // The Switch and label are siblings in a flex-row; scroll the label
      // into view first, then tap the last Switch in reading order.
      await tester.ensureVisible(
        find.text(trans('uptizm.monitors.form_advanced_label')),
      );
      await tester.pump();

      // Find all Switch widgets; the advanced toggle is the last one (after the
      // two notification switches). Tap it to enable advanced mode.
      final Finder switches = find.byType(MSSwitch);
      expect(switches, findsWidgets);
      // The advanced switch is the third Switch in reading order.
      await tester.tap(switches.last);
      await tester.pump();

      // After toggling, the method, headers, and timeout labels appear.
      await tester.ensureVisible(
        find.text(trans('uptizm.monitors.form_method_label')),
      );
      expect(
        find.text(trans('uptizm.monitors.form_method_label')),
        findsOneWidget,
        reason: 'HTTP method label must appear after enabling advanced mode',
      );
      expect(
        find.text(trans('uptizm.monitors.form_headers_label')),
        findsOneWidget,
        reason: 'Headers label must appear after enabling advanced mode',
      );
      expect(
        find.text(trans('uptizm.monitors.form_timeout_label')),
        findsOneWidget,
        reason: 'Timeout label must appear after enabling advanced mode',
      );
    });

    testWidgets(
      'startAdvanced: true renders method, headers, and timeout on mount',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 5000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              startAdvanced: true,
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (_) async => <String, String>{},
              onCancel: () {},
            ),
            size: const Size(1200, 5000),
          ),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.monitors.form_method_label')),
          findsOneWidget,
          reason: 'Method label must be present when startAdvanced is true',
        );
        expect(
          find.text(trans('uptizm.monitors.form_timeout_label')),
          findsOneWidget,
          reason: 'Timeout label must be present when startAdvanced is true',
        );
      },
    );

    testWidgets('interval select renders the 10s option label', (tester) async {
      // Under the bare harness the EntitlementController has not resolved a plan
      // (its interval floor is a permissive 0, nothing is locked), so we assert
      // only that the base interval label renders; the plan-driven lock/suffix
      // is exercised in entitlement_controller_test.dart.
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async => <String, String>{},
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      // The interval label is always present regardless of lock state.
      expect(
        find.text(trans('uptizm.monitors.form_interval_label')),
        findsOneWidget,
        reason: 'Check interval label must be rendered',
      );
    });

    testWidgets('renders the Notifications section', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async => <String, String>{},
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.form_notifications_title')),
        findsOneWidget,
        reason: 'Notifications section title must be rendered',
      );
    });

    testWidgets('footer Cancel and Submit buttons are present', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async => <String, String>{},
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      expect(
        find.widgetWithText(MSButton, trans('uptizm.monitors.form_cancel')),
        findsOneWidget,
        reason: 'Cancel button must be in the footer',
      );
      expect(
        find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_create'),
        ),
        findsOneWidget,
        reason: 'Submit button must be in the footer',
      );
    });
  });

  // ---------------------------------------------------------------------------
  // MonitorForm credentials (the auth_config block)
  //
  // The edit half is the one that can destroy data: `MonitorResource` reduces a
  // stored credential to `type` / `username` / `header`, so the form never
  // receives the secret and must omit `auth_config` from the request rather
  // than round-trip a partial map (which 422s) or a masked placeholder (which
  // would be submitted as the literal new password).
  // ---------------------------------------------------------------------------

  group('MonitorForm credentials', () {
    /// The obscured secret input, whichever scheme is selected. Found by its
    /// [InputType] rather than a placeholder, because on a create it has none.
    Finder secretInput() {
      return find.byWidgetPredicate(
        (widget) => widget is MSInput && widget.type == InputType.password,
      );
    }

    /// Pumps a form with the advanced section open, runs [interact], taps
    /// Submit, and returns the posted field map (null when the client-side
    /// checks blocked the submit).
    Future<Map<String, dynamic>?> submitWith(
      WidgetTester tester, {
      required String submitLabelKey,
      bool isEdit = false,
      Map<String, dynamic>? initialAuthConfig,
      Map<String, dynamic>? initialPendingAuthConfig,
      required Future<void> Function(WidgetTester tester) interact,
    }) async {
      await tester.binding.setSurfaceSize(const Size(1200, 5000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Map<String, dynamic>? captured;
      await tester.pumpWidget(
        wrap(
          MonitorForm(
            isEdit: isEdit,
            startAdvanced: true,
            initialName: 'API gateway',
            initialUrl: 'https://api.example.com/health',
            initialAuthConfig: initialAuthConfig,
            initialPendingAuthConfig: initialPendingAuthConfig,
            submitLabel: trans(submitLabelKey),
            onSubmit: (fields) async {
              captured = fields;
              return <String, String>{};
            },
            onCancel: () {},
          ),
          size: const Size(1200, 5000),
        ),
      );
      await tester.pump();

      await interact(tester);

      final Finder submit = find.widgetWithText(
        MSButton,
        trans(submitLabelKey),
      );
      await tester.ensureVisible(submit);
      await tester.pump();
      await tester.tap(submit);
      await tester.pump();

      return captured;
    }

    testWidgets('a create with basic auth posts a complete credential map', (
      tester,
    ) async {
      final Map<String, dynamic>? fields = await submitWith(
        tester,
        submitLabelKey: 'uptizm.monitors.form_submit_create',
        interact: (tester) async {
          await tester.tap(find.text(trans('uptizm.monitors.form_auth_type_basic')));
          await tester.pump();
          await tester.enterText(
            find.widgetWithText(
              MSInput,
              trans('uptizm.monitors.form_auth_username_placeholder'),
            ),
            'svc',
          );
          await tester.pump();
          await tester.enterText(secretInput(), 's3cret');
          await tester.pump();
        },
      );

      expect(fields, isNotNull, reason: 'a complete credential must submit');
      expect(
        fields!['auth_config'],
        equals({'type': 'basic', 'username': 'svc', 'password': 's3cret'}),
        reason: 'the create request carries the whole credential the worker '
            'needs, and only the keys the selected scheme uses',
      );
    });

    testWidgets('a create with no credential still posts an explicit null', (
      tester,
    ) async {
      final Map<String, dynamic>? fields = await submitWith(
        tester,
        submitLabelKey: 'uptizm.monitors.form_submit_create',
        interact: (tester) async {},
      );

      expect(fields, isNotNull);
      expect(
        fields!.containsKey('auth_config'),
        isTrue,
        reason: 'a create sends the full request shape',
      );
      expect(fields['auth_config'], isNull);
    });

    testWidgets(
      'an edit that changes only the name omits auth_config from the request',
      (tester) async {
        // The destructive case. The form holds no secret to resend, so a
        // request carrying `{type: basic, username: svc}` would be refused by
        // `required_if:auth_config.type,basic` and one carrying an explicit
        // null would blank a credential nobody asked to change. Omitting the
        // key is the only shape that leaves the stored credential alone.
        final Map<String, dynamic>? fields = await submitWith(
          tester,
          submitLabelKey: 'uptizm.monitors.form_submit_save',
          isEdit: true,
          initialAuthConfig: const {'type': 'basic', 'username': 'svc'},
          interact: (tester) async {
            await tester.enterText(
              find.widgetWithText(MSInput, 'API gateway'),
              'API gateway (renamed)',
            );
            await tester.pump();
          },
        );

        expect(fields, isNotNull, reason: 'a rename must not be blocked');
        expect(fields!['name'], equals('API gateway (renamed)'));
        expect(
          fields.containsKey('auth_config'),
          isFalse,
          reason: 'an untouched credential must not travel at all: neither a '
              'partial map (422) nor an explicit null (blanks the secret)',
        );
      },
    );

    testWidgets(
      'a credential carried in from the AI setup step posts its secret',
      (tester) async {
        // The other half of the seed contract, and the opposite of the edit
        // case above. This credential was typed on the AI input step and
        // nothing has stored it, so a form that dropped its secret the way a
        // redacted seed drops one would create a monitor with no credential and
        // its first check would 401 on an endpoint the analysis just read.
        final Map<String, dynamic>? fields = await submitWith(
          tester,
          submitLabelKey: 'uptizm.monitors.form_submit_create',
          initialPendingAuthConfig: const {
            'type': 'basic',
            'username': 'svc',
            'password': 's3cret',
          },
          interact: (tester) async {},
        );

        expect(fields, isNotNull, reason: 'a complete credential must submit');
        expect(
          fields!['auth_config'],
          equals({'type': 'basic', 'username': 'svc', 'password': 's3cret'}),
          reason: 'the secret has to reach the create request: it exists '
              'nowhere else',
        );
      },
    );

    testWidgets(
      'a pending credential fills the secret input, a stored one does not',
      (tester) async {
        // What the operator sees is the same distinction: a secret that will be
        // sent is present in the field, a secret the backend holds and withheld
        // is announced by the placeholder and never faked into the value.
        await tester.binding.setSurfaceSize(const Size(1200, 5000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            MonitorForm(
              startAdvanced: true,
              initialName: 'API gateway',
              initialUrl: 'https://api.example.com/health',
              initialPendingAuthConfig: const {
                'type': 'basic',
                'username': 'svc',
                'password': 's3cret',
              },
              submitLabel: trans('uptizm.monitors.form_submit_create'),
              onSubmit: (_) async => <String, String>{},
              onCancel: () {},
            ),
            size: const Size(1200, 5000),
          ),
        );
        await tester.pump();

        final MSInput secret = tester.widget<MSInput>(secretInput());
        expect(secret.value, equals('s3cret'));
        expect(
          secret.placeholder,
          isNull,
          reason: 'nothing is stored, so nothing can be kept by leaving it '
              'blank; claiming otherwise would be the wrong affordance',
        );
      },
    );

    testWidgets('the stored secret is never rendered, only announced', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 5000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            isEdit: true,
            startAdvanced: true,
            initialName: 'API gateway',
            initialUrl: 'https://api.example.com/health',
            initialAuthConfig: const {'type': 'basic', 'username': 'svc'},
            submitLabel: trans('uptizm.monitors.form_submit_save'),
            onSubmit: (_) async => <String, String>{},
            onCancel: () {},
          ),
          size: const Size(1200, 5000),
        ),
      );
      await tester.pump();

      final MSInput secret = tester.widget<MSInput>(secretInput());
      expect(
        secret.value ?? '',
        isEmpty,
        reason: 'a masked placeholder would be posted as the literal password',
      );
      expect(
        secret.placeholder,
        equals(trans('uptizm.monitors.form_auth_secret_stored_placeholder')),
        reason: 'the operator has to be told blank means unchanged',
      );
      expect(
        find.widgetWithText(MSInput, 'svc'),
        findsOneWidget,
        reason: 'the non-secret username the backend does return is seeded',
      );
    });

    testWidgets('typing a new password on an edit replaces the credential', (
      tester,
    ) async {
      final Map<String, dynamic>? fields = await submitWith(
        tester,
        submitLabelKey: 'uptizm.monitors.form_submit_save',
        isEdit: true,
        initialAuthConfig: const {'type': 'basic', 'username': 'svc'},
        interact: (tester) async {
          await tester.enterText(secretInput(), 'rotated');
          await tester.pump();
        },
      );

      expect(fields, isNotNull);
      expect(
        fields!['auth_config'],
        equals({'type': 'basic', 'username': 'svc', 'password': 'rotated'}),
        reason: 'the seeded username rides along with the retyped secret',
      );
    });

    testWidgets('switching an edit to None clears the stored credential', (
      tester,
    ) async {
      final Map<String, dynamic>? fields = await submitWith(
        tester,
        submitLabelKey: 'uptizm.monitors.form_submit_save',
        isEdit: true,
        initialAuthConfig: const {'type': 'basic', 'username': 'svc'},
        interact: (tester) async {
          await tester.tap(
            find.text(trans('uptizm.monitors.form_auth_type_none')),
          );
          await tester.pump();
        },
      );

      expect(fields, isNotNull);
      expect(
        fields!.containsKey('auth_config'),
        isTrue,
        reason: 'clearing is a deliberate act and has to reach the backend',
      );
      expect(fields['auth_config'], isNull);
    });

    testWidgets('switching scheme without retyping the secret blocks submit', (
      tester,
    ) async {
      // The stored basic password says nothing about a bearer token, so the
      // scheme change makes the secret required again rather than posting
      // `{type: bearer}` and collecting a 422.
      final Map<String, dynamic>? fields = await submitWith(
        tester,
        submitLabelKey: 'uptizm.monitors.form_submit_save',
        isEdit: true,
        initialAuthConfig: const {'type': 'basic', 'username': 'svc'},
        interact: (tester) async {
          await tester.tap(
            find.text(trans('uptizm.monitors.form_auth_type_bearer')),
          );
          await tester.pump();
        },
      );

      expect(fields, isNull, reason: 'an incomplete credential must not post');
      expect(
        find.text(trans('uptizm.monitors.form_auth_error_token_required')),
        findsOneWidget,
      );
    });

    testWidgets('an api_key create posts the header name beside the key', (
      tester,
    ) async {
      final Map<String, dynamic>? fields = await submitWith(
        tester,
        submitLabelKey: 'uptizm.monitors.form_submit_create',
        interact: (tester) async {
          await tester.tap(
            find.text(trans('uptizm.monitors.form_auth_type_api_key')),
          );
          await tester.pump();
          await tester.enterText(
            find.widgetWithText(
              MSInput,
              trans('uptizm.monitors.form_auth_header_placeholder'),
            ),
            'X-Api-Key',
          );
          await tester.pump();
          await tester.enterText(secretInput(), 'k-123');
          await tester.pump();
        },
      );

      expect(fields, isNotNull);
      expect(
        fields!['auth_config'],
        equals({'type': 'api_key', 'key': 'k-123', 'header': 'X-Api-Key'}),
      );
    });

    testWidgets('an oversized token is refused here, not by a 422', (
      tester,
    ) async {
      // `max:2048` on the backend, and these values travel inside the
      // HMAC-signed relay spec, so the bound is worth stating locally.
      final Map<String, dynamic>? fields = await submitWith(
        tester,
        submitLabelKey: 'uptizm.monitors.form_submit_create',
        interact: (tester) async {
          await tester.tap(
            find.text(trans('uptizm.monitors.form_auth_type_bearer')),
          );
          await tester.pump();
          await tester.enterText(secretInput(), 'x' * 2049);
          await tester.pump();
        },
      );

      expect(fields, isNull, reason: 'an over-long token must not post');
      expect(
        find.text(
          trans('uptizm.monitors.form_auth_error_too_long', {'max': '2048'}),
        ),
        findsOneWidget,
      );
    });

    testWidgets('a server 422 on auth_config lands under the named field', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 5000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            startAdvanced: true,
            initialName: 'API gateway',
            initialUrl: 'https://api.example.com/health',
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            // Laravel reports the map's inner shape with dotted keys; anything
            // the form owns no slot for becomes a generic toast instead.
            onSubmit: (_) async => {
              'auth_config.password': 'That password was rejected.',
            },
            onCancel: () {},
          ),
          size: const Size(1200, 5000),
        ),
      );
      await tester.pump();

      await tester.tap(find.text(trans('uptizm.monitors.form_auth_type_basic')));
      await tester.pump();
      await tester.enterText(
        find.widgetWithText(
          MSInput,
          trans('uptizm.monitors.form_auth_username_placeholder'),
        ),
        'svc',
      );
      await tester.pump();
      await tester.enterText(secretInput(), 'wrong');
      await tester.pump();

      final Finder submit = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.form_submit_create'),
      );
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pumpAndSettle();

      expect(find.text('That password was rejected.'), findsOneWidget);
    });

    testWidgets('a TCP monitor renders no credential block', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 5000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            startAdvanced: true,
            initialType: 'tcp',
            initialUrl: 'db.example.com:5432',
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) async => <String, String>{},
            onCancel: () {},
          ),
          size: const Size(1200, 5000),
        ),
      );
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.form_auth_label')),
        findsNothing,
        reason: 'a socket connect has nothing to authenticate with',
      );
    });
  });

  // ---------------------------------------------------------------------------
  // MonitorCreateView
  // ---------------------------------------------------------------------------

  // ---------------------------------------------------------------------------
  // The analyze run, as this screen has to drive it since the 202 split.
  //
  // `POST /monitors/analyze` ACCEPTS a run and answers a run id; a worker does
  // the model calls and the analysis arrives on `GET /monitors/analyze/{run}`.
  // So a test that wants the review step has to stub both endpoints and advance
  // the clock past one poll interval, and neither is optional: with only the
  // POST stubbed the view sits on the analyzing step forever, which is exactly
  // what the old single-response fixtures did when this landed.
  // ---------------------------------------------------------------------------

  /// The 202 accept body: the run's first snapshot, the same shape the poll
  /// answers in. `steps` is a json ARRAY because PHP encodes an empty array as
  /// one; it only becomes an object once an ordinal has reported.
  Map<String, dynamic> acceptedRun({String runId = 'run-1'}) => {
    'data': {
      'run_id': runId,
      'status': 'queued',
      'step': 0,
      'steps': <dynamic>[],
      'probe': {'region': 'us-east', 'status_code': 200, 'response_ms': 120},
      'reason': null,
      'result': null,
    },
  };

  /// One `GET /monitors/analyze/{run}` body. [result] is the old synchronous
  /// analyze body verbatim under `data`, which is where it lives now.
  Map<String, dynamic> runRead({
    String runId = 'run-1',
    required String status,
    int step = 0,
    Map<String, String> steps = const {},
    String? reason,
    Map<String, dynamic>? result,
  }) => {
    'data': {
      'run_id': runId,
      'status': status,
      'step': step,
      'steps': steps,
      'probe': {'region': 'us-east', 'status_code': 200, 'response_ms': 120},
      'reason': reason,
      'result': result == null ? null : {'data': result, 'meta': null},
    },
  };

  /// Fakes a run that is accepted and then completes with [analysis] on its
  /// first poll read, plus any [extra] stubs the test needs (a create, say).
  FakeNetworkDriver fakeCompletedRun(
    Map<String, dynamic> analysis, {
    Map<String, MagicResponse> extra = const {},
  }) {
    return Http.fake(<String, MagicResponse>{
      'monitors/analyze': Http.response(acceptedRun(), 202),
      'monitors/analyze/run-1': Http.response(
        runRead(
          status: 'completed',
          step: 5,
          steps: const {
            '1': 'done',
            '2': 'done',
            '3': 'done',
            '4': 'done',
            '5': 'done',
          },
          result: analysis,
        ),
      ),
      ...extra,
    });
  }

  /// Advances fake time past one poll interval so the accepted run is read.
  ///
  /// The controller polls on a [Timer] every 2500ms and fake time does not move
  /// on its own, so without this the review step is never reached and every
  /// assertion past the accept reads an absent widget.
  Future<void> settleAnalyzeRun(WidgetTester tester) async {
    await tester.pump(const Duration(milliseconds: 2600));
    await tester.pumpAndSettle();
  }

  /// Types [url] into the AI input step and taps Analyze, settling the accept
  /// but NOT the run: a caller that wants the review step calls
  /// [settleAnalyzeRun] after this, and one asserting on the analyzing card does
  /// not.
  Future<void> startAnalyze(WidgetTester tester, String url) async {
    final Finder urlInput = find.widgetWithText(
      MSInput,
      trans('uptizm.monitors.create_ai_url_placeholder'),
    );
    await tester.enterText(urlInput, url);
    await tester.pump();
    await tester.tap(
      find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.create_ai_analyze_button'),
      ),
    );
    await tester.pump();
  }

  group('MonitorCreateView', () {
    testWidgets('AI input step renders the URL Input and Analyze button', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorCreateView()));
      await tester.pump();

      expect(tester.takeException(), isNull);

      // The URL Input is identified by its placeholder text.
      expect(
        find.widgetWithText(
          MSInput,
          trans('uptizm.monitors.create_ai_url_placeholder'),
        ),
        findsOneWidget,
        reason: 'URL input must be present in the AI input step',
      );

      // The "Analyze with AI" button is present (disabled while URL is empty).
      expect(
        find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.create_ai_analyze_button'),
        ),
        findsOneWidget,
        reason: 'Analyze button must be present in the AI input step',
      );
    });

    testWidgets('AI mode Analyze button is disabled when URL is empty', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorCreateView()));
      await tester.pump();

      final MSButton analyzeBtn = tester.widget<MSButton>(
        find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.create_ai_analyze_button'),
        ),
      );
      expect(
        analyzeBtn.disabled,
        isTrue,
        reason: 'Analyze button must be disabled when the URL field is empty',
      );
    });

    testWidgets('Manual mode renders the bare MonitorForm', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 5000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const MonitorCreateView(), size: const Size(1200, 5000)),
      );
      await tester.pump();

      // Tap the "Manual" segment.
      await tester.tap(find.text(trans('uptizm.monitors.create_mode_manual')));
      await tester.pump();

      // After switching to manual the bare MonitorForm is in the tree and the
      // AI url prompt is gone.
      expect(
        find.byType(MonitorForm),
        findsOneWidget,
        reason: 'MonitorForm must be rendered in manual mode',
      );
      expect(
        find.widgetWithText(
          MSInput,
          trans('uptizm.monitors.create_ai_url_placeholder'),
        ),
        findsNothing,
        reason: 'AI URL input must not be shown in manual mode',
      );
    });

    testWidgets('entering a URL enables the Analyze button then a successful '
        'POST /monitors/analyze advances to the review step, prefilled', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 5000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final fake = fakeCompletedRun({
        'url': 'https://api.example.com/health',
        'name': 'api.example.com',
        'recommended_interval_seconds': 60,
        'recommended_warn_threshold_ms': 300,
        'recommended_critical_threshold_ms': 1000,
        'recommended_regions': ['us-east', 'eu-west'],
        'rationale': 'Stable JSON API, 60s checks are sufficient.',
      });

      await tester.pumpWidget(
        wrap(const MonitorCreateView(), size: const Size(1200, 5000)),
      );
      await tester.pump();

      // Type a URL into the AI input.
      final Finder urlInput = find.widgetWithText(
        MSInput,
        trans('uptizm.monitors.create_ai_url_placeholder'),
      );
      await tester.tap(urlInput);
      await tester.pump();
      await tester.enterText(urlInput, 'https://api.example.com/health');
      await tester.pump();

      // Now the Analyze button must be enabled.
      final MSButton analyzeBtn = tester.widget<MSButton>(
        find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.create_ai_analyze_button'),
        ),
      );
      expect(
        analyzeBtn.disabled,
        isFalse,
        reason: 'Analyze button must be enabled once a URL is entered',
      );

      // Tap Analyze to fire the live POST /monitors/analyze request, then let
      // the run's first poll read land: the 202 accepts, the read answers.
      await tester.tap(
        find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.create_ai_analyze_button'),
        ),
      );
      await tester.pump();
      await settleAnalyzeRun(tester);

      // The request actually fired the analyze endpoint with the pasted URL.
      fake.assertSent(
        (request) =>
            request.method == 'POST' &&
            request.url.contains('monitors/analyze') &&
            (request.data as Map)['url'] == 'https://api.example.com/health',
      );
      // And the analysis was fetched over the run's own authorised GET rather
      // than read out of the accept, which carries no result at all.
      fake.assertSent(
        (request) =>
            request.method == 'GET' &&
            request.url.contains('monitors/analyze/run-1'),
      );

      // After the response resolves the view flips to the review step: a
      // MonitorForm pre-filled from the response is now in the tree.
      expect(
        find.byType(MonitorForm),
        findsOneWidget,
        reason: 'MonitorForm must be present in the AI review step',
      );
      expect(
        find.widgetWithText(MSInput, 'api.example.com'),
        findsOneWidget,
        reason: 'The Name field must be prefilled from the analyze response',
      );

      // The response carries no `suggested_metrics` key, so the suggested
      // custom metrics section (label + pills + help) must not render at all;
      // a fabricated fixture is worse than an absent suggestion.
      expect(
        find.text(trans('uptizm.monitors.create_ai_suggested_metrics')),
        findsNothing,
        reason:
            'The suggested-metrics section must be absent when the backend '
            'returns no suggested_metrics',
      );
    });

    testWidgets(
      'a successful analyze carrying suggested_metrics renders one pill per '
      'entry, sourced from the response rather than a fixture',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 5000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        fakeCompletedRun({
          'url': 'https://api.example.com/health',
          'name': 'api.example.com',
          'recommended_interval_seconds': 60,
          'recommended_warn_threshold_ms': 300,
          'recommended_critical_threshold_ms': 1000,
          'recommended_regions': ['us-east', 'eu-west'],
          'rationale': 'Stable JSON API, 60s checks are sufficient.',
          'suggested_metrics': [
            {
              'key': 'p95_ms',
              'label': 'p95 latency',
              'type': 'numeric',
              'source': 'json',
              'path': r'$.latency.p95',
              'unit': 'ms',
              'warn': 300,
              'critical': 1000,
              'sample_value': '120',
            },
            {
              'key': 'error_rate',
              'label': 'Error rate',
              'type': 'numeric',
              'source': 'json',
              'path': r'$.errors.rate',
              'unit': '%',
              'warn': 1,
              'critical': 5,
              'sample_value': '0.2',
            },
          ],
        });

        await tester.pumpWidget(
          wrap(const MonitorCreateView(), size: const Size(1200, 5000)),
        );
        await tester.pump();

        await startAnalyze(tester, 'https://api.example.com/health');
        await settleAnalyzeRun(tester);

        // The section header appears (the response carries real suggestions)
        // and exactly one pill renders per suggested_metrics entry, using the
        // backend's own labels rather than the deleted p95_ms/error_rate/
        // active_conns fixture.
        expect(
          find.text(trans('uptizm.monitors.create_ai_suggested_metrics')),
          findsOneWidget,
          reason: 'The suggested-metrics section must render when the '
              'backend proposes real metrics',
        );
        expect(
          find.text('p95 latency'),
          findsOneWidget,
          reason: 'A pill must render for the first suggested_metrics entry',
        );
        expect(
          find.text('Error rate'),
          findsOneWidget,
          reason: 'A pill must render for the second suggested_metrics entry',
        );
      },
    );

    testWidgets(
      'a failed POST /monitors/analyze falls back to the input step',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1200, 5000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        Http.fake({
          'monitors/analyze': Http.response({
            'message': 'The url field is required.',
          }, 422),
        });

        await tester.pumpWidget(
          wrap(const MonitorCreateView(), size: const Size(1200, 5000)),
        );
        await tester.pump();

        final Finder urlInput = find.widgetWithText(
          MSInput,
          trans('uptizm.monitors.create_ai_url_placeholder'),
        );
        await tester.tap(urlInput);
        await tester.pump();
        await tester.enterText(urlInput, 'https://api.example.com/health');
        await tester.pump();

        await tester.tap(
          find.widgetWithText(
            MSButton,
            trans('uptizm.monitors.create_ai_analyze_button'),
          ),
        );
        await tester.pumpAndSettle();

        // The failed analyze must fall back to the input step, never the
        // review form: the Analyze button (only rendered on the input step)
        // is back in the tree, re-enabled for a retry.
        expect(
          find.byType(MonitorForm),
          findsNothing,
          reason: 'A failed analyze must not render the review MonitorForm',
        );
        final Finder analyzeButtonAgain = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.create_ai_analyze_button'),
        );
        expect(
          analyzeButtonAgain,
          findsOneWidget,
          reason: 'A failed analyze must fall back to the AI input step',
        );
        expect(
          tester.widget<MSButton>(analyzeButtonAgain).disabled,
          isFalse,
          reason: 'The retry Analyze button must be enabled (URL is retained)',
        );
      },
    );

    // -------------------------------------------------------------------------
    // The analyze-step rows, driven by the run rather than decorated.
    //
    // Before this they were five identical spinners on a timer of their own, so
    // the card said the same thing whatever the worker was doing (and kept
    // saying it after the worker had stopped). Every assertion below counts
    // GLYPHS rather than reading a className, because a className is not what an
    // operator sees and an inert Wind token would let a wrong one pass.
    // -------------------------------------------------------------------------

    /// The number of rows rendering each state, by the glyph that identifies it.
    int rows(WidgetTester tester, IconData icon) =>
        tester.widgetList(find.byIcon(icon)).length;

    testWidgets('the analyze rows advance with the run, and a skipped step '
        'renders as skipped rather than spinning on work that never ran', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 5000));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      addTearDown(MonitorController.instance.abandonAnalyzeRun);

      int reads = 0;
      Http.fake((MagicRequest request) {
        if (request.method == 'POST') {
          return Http.response(acceptedRun(), 202);
        }
        if (!request.url.contains('monitors/analyze/')) {
          return Http.response(<String, dynamic>{});
        }

        reads++;
        // Read 1 is the state this test exists for: step 2 SKIPPED (the digest
        // had no body to read), which is terminal, so the row after it is the
        // one in flight and nothing is left claiming to work on step 2.
        return Http.response(
          reads == 1
              ? runRead(
                  status: 'analyzing',
                  step: 2,
                  steps: const {'1': 'done', '2': 'skipped'},
                )
              : runRead(
                  status: 'completed',
                  step: 5,
                  steps: const {
                    '1': 'done',
                    '2': 'skipped',
                    '3': 'done',
                    '4': 'done',
                    '5': 'done',
                  },
                  result: const {
                    'url': 'https://api.example.com/health',
                    'name': 'api.example.com',
                    'recommended_interval_seconds': 60,
                    'recommended_regions': ['us-east'],
                    'rationale': 'Stable JSON API.',
                  },
                ),
        );
      });

      await tester.pumpWidget(
        wrap(const MonitorCreateView(), size: const Size(1200, 5000)),
      );
      await tester.pump();
      await startAnalyze(tester, 'https://api.example.com/health');

      // 1. Accepted, nothing reported yet. The probe runs INSIDE the accepting
      //    request, so ordinal 1 is the one genuinely working and the other four
      //    are pending, not spinning.
      expect(
        find.text(trans('uptizm.monitors.create_ai_step_1')),
        findsOneWidget,
        reason: 'the analyzing card must be on screen',
      );
      expect(rows(tester, Icons.autorenew), 1, reason: 'exactly one row is in flight');
      expect(rows(tester, Icons.radio_button_unchecked), 4);
      expect(rows(tester, Icons.check_circle_outline), 0);

      // 2. One poll read later the rows are the run's own state.
      await tester.pump(const Duration(milliseconds: 2600));
      await tester.pump();

      // THE assertion of this step, and it goes FIRST so a run of it names the
      // state that broke rather than a side effect of it: collapsing `skipped`
      // into `done` (the measured mutation, `evidence/step-10-skipped-step-
      // red.md`) leaves this at 0 and the check count at 2.
      expect(
        rows(tester, Icons.remove_circle_outline),
        1,
        reason: 'ordinal 2 reported skipped and must render as skipped, not as '
            'done and not as still running',
      );
      expect(
        find.text(trans('uptizm.monitors.create_ai_analyze_skipped_note')),
        findsOneWidget,
        reason: 'a skipped step says so in words; a muted glyph is not a claim '
            'anybody reads',
      );
      expect(
        rows(tester, Icons.check_circle_outline),
        1,
        reason: 'ordinal 1 reported done, and it is the ONLY done row: a client '
            'that folded skipped into done would report two findings where the '
            'worker made one',
      );
      expect(
        rows(tester, Icons.autorenew),
        1,
        reason: 'a skipped tick is terminal, so the row AFTER it is the one in '
            'flight: no row is left spinning on the skipped step',
      );
      expect(
        rows(tester, Icons.radio_button_unchecked),
        2,
        reason: 'ordinals 4 and 5 are pending, not spinning',
      );

      // 3. And the run still finishes into the review step, so the skipped
      //    state is a passing-through state rather than a dead end.
      await settleAnalyzeRun(tester);
      expect(find.byType(MonitorForm), findsOneWidget);
    });

    testWidgets(
      'a lost run tells the operator to run it again, not to check the URL',
      (tester) async {
        // The run's cache entry is gone (Redis runs `volatile-lru` at a 512 MB
        // ceiling, and the entry expires at 900s anyway). The target was never
        // the problem, so the reachability hint would be a wrong diagnosis of a
        // URL that answered fine minutes earlier.
        await tester.binding.setSurfaceSize(const Size(1200, 5000));
        addTearDown(() => tester.binding.setSurfaceSize(null));
        addTearDown(MonitorController.instance.abandonAnalyzeRun);

        Http.fake((MagicRequest request) {
          if (request.method == 'POST') {
            return Http.response(acceptedRun(), 202);
          }

          return Http.response(<String, dynamic>{'message': 'Not found.'}, 404);
        });

        await tester.pumpWidget(
          wrap(const MonitorCreateView(), size: const Size(1200, 5000)),
        );
        await tester.pump();
        await startAnalyze(tester, 'https://api.example.com/health');
        await settleAnalyzeRun(tester);

        expect(
          find.text(trans('uptizm.monitors.create_ai_analyze_lost')),
          findsOneWidget,
          reason: 'an expired run has its own copy',
        );
        expect(
          find.text(trans('uptizm.monitors.create_ai_analyze_failed')),
          findsNothing,
          reason: 'and it is NOT the reachability hint, which blames a target '
              'that was fine',
        );
      },
    );

    testWidgets(
      'switching to Manual mid-run abandons the run and blames nobody for it',
      (tester) async {
        // Abandoning settles the pending analyze with null, exactly as a failure
        // does. Without the attempt guard the operator would land on the manual
        // form and then be told the URL could not be analyzed, for a run they
        // cancelled themselves; and the poll would keep reading for four
        // minutes with nothing left to render it.
        await tester.binding.setSurfaceSize(const Size(1200, 5000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        int reads = 0;
        Http.fake((MagicRequest request) {
          if (request.method == 'POST') {
            return Http.response(acceptedRun(), 202);
          }
          if (request.url.contains('monitors/analyze/')) reads++;

          return Http.response(<String, dynamic>{});
        });

        await tester.pumpWidget(
          wrap(const MonitorCreateView(), size: const Size(1200, 5000)),
        );
        await tester.pump();
        await startAnalyze(tester, 'https://api.example.com/health');
        expect(
          find.text(trans('uptizm.monitors.create_ai_step_1')),
          findsOneWidget,
          reason: 'the run is in flight before the mode switch',
        );

        await tester.tap(
          find.text(trans('uptizm.monitors.create_mode_manual')),
        );
        await tester.pumpAndSettle();

        expect(
          find.text(trans('uptizm.monitors.create_ai_analyze_failed')),
          findsNothing,
          reason: 'a cancelled run is not a failed one',
        );
        expect(
          find.text(trans('uptizm.monitors.create_ai_analyze_lost')),
          findsNothing,
        );

        // The poll is gone with the run: no read ever happens, and no pending
        // timer is left behind (which is itself asserted by the test framework).
        await tester.pump(const Duration(milliseconds: 5200));
        expect(
          reads,
          0,
          reason: 'an abandoned run must stop costing a read every 2500ms',
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // MonitorEditView
  // ---------------------------------------------------------------------------

  group('MonitorEditView', () {
    testWidgets("id: 'api' prefills the monitor name", (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorEditView(id: 'api')));
      await tester.pump();

      expect(tester.takeException(), isNull);

      // The fixture name for the 'api' monitor is 'API gateway'. The MonitorForm
      // renders it in the Name field (Input with value 'API gateway').
      expect(
        find.widgetWithText(MSInput, 'API gateway'),
        findsOneWidget,
        reason:
            "The Name input must be prefilled with the fixture monitor's name",
      );
    });

    testWidgets("id: 'api' renders the form submit button", (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorEditView(id: 'api')));
      await tester.pump();

      expect(
        find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.form_submit_save'),
        ),
        findsOneWidget,
        reason: 'Save changes button must be present for a known monitor id',
      );
    });

    testWidgets('unknown id renders the not-found MSEmptyState', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const MonitorEditView(id: 'does-not-exist')),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.byType(MSEmptyState),
        findsOneWidget,
        reason: 'MSEmptyState must be rendered for an unknown monitor id',
      );
      expect(
        find.byType(MonitorForm),
        findsNothing,
        reason: 'MonitorForm must not be rendered for an unknown monitor id',
      );
    });

    testWidgets('unknown id renders the not-found title text', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const MonitorEditView(id: 'does-not-exist')),
      );
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.error_load_title')),
        findsWidgets,
        reason: 'The not-found title must appear for an unknown monitor id',
      );
    });

    testWidgets('null id renders the not-found MSEmptyState', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorEditView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.byType(MSEmptyState),
        findsOneWidget,
        reason: 'MSEmptyState must be rendered when no id is provided',
      );
    });
  });
}
