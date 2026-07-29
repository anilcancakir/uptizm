import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/resources/views/monitors/monitor_form.dart';
import 'package:uptizm/ui/components/key_value_editor/key_value_editor.dart'
    show KeyValueRow;

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
}
