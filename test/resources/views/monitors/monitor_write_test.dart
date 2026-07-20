import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/resources/views/monitors/monitor_form.dart';

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
            onSubmit: (fields) => captured = fields,
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

    testWidgets('Cancel does not invoke onSubmit', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      bool submitCalled = false;
      bool cancelCalled = false;

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) => submitCalled = true,
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
}
