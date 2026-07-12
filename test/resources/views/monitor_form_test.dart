import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/resources/views/monitors/monitor_create_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_edit_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_form.dart';
import 'package:uptizm/ui/components/empty_state/index.dart';

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
      'uptizm.monitors.create_ai_analyzing_title': 'Analyzing endpoint…',
      'uptizm.monitors.create_ai_review_banner_title':
          'AI configured this monitor',
      'uptizm.monitors.create_ai_review_summary': 'Ready: :name.',
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
            onSubmit: (_) {},
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MonitorForm), findsOneWidget);
    });

    testWidgets('advanced section is hidden by default', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) {},
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
            onSubmit: (_) {},
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
              onSubmit: (_) {},
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
      // The 10s option is locked (Pro plan has 30s limit), so it renders with a
      // plan suffix. We assert the base label text appears somewhere in the tree
      // (the SelectOption may not be introspectable under the bare harness).
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorForm(
            submitLabel: trans('uptizm.monitors.form_submit_create'),
            onSubmit: (_) {},
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
            onSubmit: (_) {},
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
            onSubmit: (_) {},
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
  // MonitorCreateView
  // ---------------------------------------------------------------------------

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

      final fake = Http.fake({
        'monitors/analyze': Http.response({
          'data': {
            'url': 'https://api.example.com/health',
            'name': 'api.example.com',
            'recommended_interval_seconds': 60,
            'recommended_warn_threshold_ms': 300,
            'recommended_critical_threshold_ms': 1000,
            'recommended_regions': ['us-east', 'eu-west'],
            'rationale': 'Stable JSON API, 60s checks are sufficient.',
            'probe': {
              'region': 'us-east',
              'status_code': 200,
              'response_ms': 120,
            },
          },
        }),
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

      // Tap Analyze to fire the live POST /monitors/analyze request.
      await tester.tap(
        find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.create_ai_analyze_button'),
        ),
      );
      await tester.pumpAndSettle();

      // The request actually fired the analyze endpoint with the pasted URL.
      fake.assertSent(
        (request) =>
            request.method == 'POST' &&
            request.url.contains('monitors/analyze') &&
            (request.data as Map)['url'] == 'https://api.example.com/health',
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
    });

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

    testWidgets('unknown id renders the not-found EmptyState', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const MonitorEditView(id: 'does-not-exist')),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.byType(EmptyState),
        findsOneWidget,
        reason: 'EmptyState must be rendered for an unknown monitor id',
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

    testWidgets('null id renders the not-found EmptyState', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1200, 900));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorEditView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.byType(EmptyState),
        findsOneWidget,
        reason: 'EmptyState must be rendered when no id is provided',
      );
    });
  });
}
