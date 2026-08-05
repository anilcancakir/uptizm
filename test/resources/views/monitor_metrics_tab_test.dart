import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/controllers/monitor_metrics_controller.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/support/metric_types.dart' show MonitorMetric;
import 'package:uptizm/app/mocks/metrics.dart';
import 'package:uptizm/resources/views/monitors/monitor_metric_detail.dart';
import 'package:uptizm/resources/views/monitors/monitor_metric_form.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_tab.dart';
import 'package:uptizm/ui/components/metric_chart/index.dart';
import '../../support/skeleton_matchers.dart';

/// Language loader for all trans() keys exercised by the metrics widgets.
///
/// Short, wrappable strings avoid RenderFlex overflow at the narrow test
/// viewport, mirroring the pattern in monitor_detail_view_test.dart.
class _MetricsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // MonitorMetricsTab: system section.
      'uptizm.monitors.metrics_system_title': 'System metrics',
      'uptizm.monitors.metrics_system_collected_by_default': 'collected',
      'uptizm.monitors.metrics_response_time': 'Response time',

      // MonitorMetricsTab: custom section.
      'uptizm.monitors.metrics_custom_title': 'Custom metrics',
      'uptizm.monitors.metrics_add': 'Add metric',
      'uptizm.monitors.metrics_empty_title': 'No custom metrics',
      'uptizm.monitors.metrics_empty_description': 'None yet.',
      'uptizm.monitors.metrics_create': 'Create metric',

      // MonitorMetricForm labels.
      'uptizm.monitors.metrics_form_new_title': 'New metric',
      'uptizm.monitors.metrics_form_edit_title': 'Edit metric',
      'uptizm.monitors.metrics_form_name_label': 'Name',
      'uptizm.monitors.metrics_form_name_placeholder': 'e.g. Memory usage',
      'uptizm.monitors.metrics_form_key_label': 'Key',
      'uptizm.monitors.metrics_form_key_hint':
          'Lowercase letters, digits, underscores.',
      'uptizm.monitors.metrics_form_key_error': 'Invalid key.',
      'uptizm.monitors.form_name_error_required': 'Name required',
      'uptizm.monitors.metrics_form_key_error_required': 'Key required',
      'uptizm.monitors.metrics_form_path_error_required': 'Path required',
      'uptizm.monitors.toast_save_failed_title': "Couldn't save",
      'uptizm.monitors.metrics_form_key_placeholder': 'memory_usage',
      'uptizm.monitors.metrics_form_type_label': 'Type',
      'uptizm.monitors.metrics_form_source_label': 'Source',
      'uptizm.monitors.metrics_form_unit_label': 'Unit',
      'uptizm.monitors.metrics_form_extraction_label': 'Extraction path',
      'uptizm.monitors.metrics_form_direction_label': 'Direction',
      'uptizm.monitors.metrics_form_warn_label': 'Warn',
      'uptizm.monitors.metrics_form_critical_label': 'Critical',
      'uptizm.monitors.metrics_form_ai_use': 'Use',
      'uptizm.monitors.metrics_form_ai_suggestion': 'Suggestion.',
      'uptizm.monitors.metrics_form_test_title': 'Test extraction',
      'uptizm.monitors.metrics_form_test_hint': 'Run a sample fetch.',
      'uptizm.monitors.metrics_form_no_sample': 'No checks yet.',
      'uptizm.monitors.metrics_form_sample_from':
          'Verified against the check from :when (HTTP :code)',
      'uptizm.monitors.metrics_form_fetch_test': 'Fetch & test',
      'uptizm.monitors.metrics_form_fetching': 'Fetching...',
      'uptizm.monitors.metrics_form_fetching_sample': 'Fetching sample...',
      'uptizm.monitors.metrics_form_test_again': 'Test again',
      'uptizm.monitors.metrics_form_resolved': 'Resolved',
      'uptizm.monitors.metrics_form_save_create': 'Create metric',
      'uptizm.monitors.metrics_form_save_edit': 'Save',
      'uptizm.monitors.metrics_test_not_found_body': 'Not found.',

      // MonitorMetricDetail.
      'uptizm.monitors.action_edit': 'Edit',
      'uptizm.monitors.action_delete': 'Delete',
      'uptizm.monitors.metrics_detail_latest': 'latest · last 24h',
      'uptizm.monitors.metrics_detail_loading': 'Loading readings...',
      'uptizm.monitors.metrics_detail_no_readings': 'No readings yet.',
      'uptizm.monitors.metrics_recent_readings': 'Recent readings',
      'uptizm.monitors.metrics_confirm_delete_title': 'Delete metric',
      'uptizm.monitors.metrics_confirm_delete_description':
          'This cannot be undone.',
      'uptizm.monitors.metrics_confirm_delete_label': 'Delete',

      // MonitorMetricForm option labels (type / source / unit / direction) and
      // the source-specific path hints, now resolved through trans() by the
      // getters in monitor_metrics_support.dart.
      'uptizm.monitors.metrics_type_numeric': 'Numeric',
      'uptizm.monitors.metrics_type_status': 'Status',
      'uptizm.monitors.metrics_type_string': 'String',
      'uptizm.monitors.metrics_source_json': 'JSON path',
      'uptizm.monitors.metrics_source_regex': 'Regex',
      'uptizm.monitors.metrics_source_xpath': 'XPath',
      'uptizm.monitors.metrics_source_header': 'Header',
      'uptizm.monitors.metrics_source_http_status': 'HTTP status',
      'uptizm.monitors.metrics_unit_ms': 'Milliseconds (ms)',
      'uptizm.monitors.metrics_unit_s': 'Seconds (s)',
      'uptizm.monitors.metrics_unit_percent': 'Percent (%)',
      'uptizm.monitors.metrics_unit_count': 'Count',
      'uptizm.monitors.metrics_unit_bytes': 'Bytes',
      'uptizm.monitors.metrics_unit_req_s': 'Requests / sec',
      'uptizm.monitors.metrics_unit_custom': 'Custom',
      'uptizm.monitors.metrics_direction_high': 'Higher is worse',
      'uptizm.monitors.metrics_direction_low': 'Lower is worse',
      'uptizm.monitors.metrics_source_hint_json': 'JSON path.',
      'uptizm.monitors.metrics_source_hint_regex': 'Regex.',
      'uptizm.monitors.metrics_source_hint_xpath': 'XPath.',
      'uptizm.monitors.metrics_source_hint_header': 'Header name.',
      'uptizm.monitors.metrics_source_hint_http_status': 'No path needed.',

      // Common.
      'uptizm.common.cancel': 'Cancel',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind MagicStarter so magic_starter widgets (Button, BottomSheet, etc.)
    // resolve their theme without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Bind a fake network driver: the tab's `initState` kicks off
    // `MonitorMetricsController.reload` (and `MonitorController.monitorById`
    // fires a background single-resource refresh), both of which need a
    // registered `network` service even though the tests below seed their
    // state directly via `seedForTest` rather than through a live fetch.
    Http.fake();

    // Load short strings so trans() returns human labels rather than raw keys.
    Translator.instance.setLoader(_MetricsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));

    // Seed the live controllers with the fixture-equivalent data the tab
    // used to read directly from `mocks/metrics.dart`'s
    // `customMetricsForMonitors`/`systemMetricsForMonitors`, bypassing the
    // network exactly like `monitor_controller_test.dart`'s `seedForTest`.
    MonitorController.instance.seedForTest([
      Monitor.fromMap(const {
        'id': 'api',
        'name': 'API',
        'url': 'https://api.uptizm.com',
        'last_status': 'up',
        'last_response_ms': 412,
        'uptime': '99.9%',
        'check_interval_sec': 30,
        'regions': ['us-east'],
      }),
    ]);
    MonitorMetricsController.instance.seedForTest('api', [
      MonitorMetricRecord(
        id: 'm1',
        form: kEmptyMetricForm.copyWith(
          label: 'Memory usage',
          key: 'memory_usage',
          path: r'$.system.memory.used_pct',
          unit: '%',
          direction: 'high',
          warn: '80',
          critical: '95',
          value: 73,
        ),
      ),
      MonitorMetricRecord(
        id: 'm2',
        form: kEmptyMetricForm.copyWith(
          label: 'CPU load',
          key: 'cpu_load',
          path: r'$.system.cpu.load_pct',
          unit: '%',
          direction: 'high',
          warn: '70',
          critical: '90',
          value: 41,
        ),
      ),
      MonitorMetricRecord(
        id: 'm3',
        form: kEmptyMetricForm.copyWith(
          label: 'Request rate',
          key: 'req_rate',
          path: r'$.requests.rate',
          unit: 'req_s',
          direction: 'low',
          warn: '50',
          critical: '20',
          value: 96,
        ),
      ),
    ]);
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  // ---------------------------------------------------------------------------
  // Wrap helper: MaterialApp > MediaQuery > WindTheme > Scaffold > body.
  // ---------------------------------------------------------------------------

  /// Wraps [widget] in a standard test harness.
  ///
  /// The Scaffold body is a plain [SingleChildScrollView] for scroll-friendly
  /// tab-level widgets. For widgets that contain `w-full` buttons inside a Row
  /// (e.g. [MonitorMetricForm]'s responsive footer), use [wrapForm] instead so
  /// the LayoutBuilder sees a bounded constraint rather than infinite width.
  Widget wrap(Widget widget, {Size size = const Size(1280, 2400)}) {
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

  /// Wraps [widget] with the WindTheme mounted ABOVE the MaterialApp Navigator
  /// (via the app `builder`), mirroring how MagicApplication wraps
  /// MaterialApp.router at the app root. Modal routes (bottom sheets) mount on
  /// the root Overlay, so they only inherit a WindTheme placed above the
  /// Navigator; a WindTheme inside `home` would not reach them.
  Widget wrapRootTheme(Widget widget, {Size size = const Size(1280, 2400)}) {
    return MaterialApp(
      builder: (context, child) => MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: child ?? const SizedBox.shrink(),
        ),
      ),
      home: Scaffold(body: SingleChildScrollView(child: widget)),
    );
  }

  /// Wraps [widget] in a harness sized at 400×3000 (below the `sm` 640px
  /// breakpoint).
  ///
  /// Using a sub-640px viewport prevents wind's responsive `sm:flex-row` from
  /// activating on the MonitorMetricForm footer. At that breakpoint each button
  /// gets `w-full` inside a Row, which requires a bounded max-width in the
  /// Overlay's offstage context (a constraint the test Overlay does not
  /// provide), producing "BoxConstraints forces an infinite width". By staying
  /// below `sm`, the footer renders as a flex-col and the buttons get `w-full`
  /// inside a Column (unbounded height only), which is safe.
  Widget wrapForm(Widget widget, {Size size = const Size(600, 3000)}) {
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
  // MonitorMetricsTab: 'api' monitor (has custom metrics).
  // ---------------------------------------------------------------------------

  group('MonitorMetricsTab, monitorId: api', () {
    testWidgets('renders system metrics title', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_system_title')),
        findsOneWidget,
        reason: 'System metrics section header must be visible for api monitor',
      );
    });

    testWidgets('renders all three custom metric labels', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      // The api monitor has Memory usage, CPU load, Request rate.
      expect(find.text('Memory usage'), findsOneWidget);
      expect(find.text('CPU load'), findsOneWidget);
      expect(find.text('Request rate'), findsOneWidget);
    });

    testWidgets('tapping a custom metric row opens its detail sheet', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // WindTheme must sit ABOVE the Navigator so the modal bottom sheet (which
      // mounts on the root Overlay) inherits it, exactly as MagicApplication
      // wraps MaterialApp.router at the app root in production.
      await tester.pumpWidget(
        wrapRootTheme(const MonitorMetricsTab(monitorId: 'api')),
      );
      await tester.pump();

      // Tapping a custom row must open the historical MonitorMetricDetail sheet
      // (the "Recent readings" section is unique to the detail body).
      await tester.tap(find.text('Memory usage'));
      await tester.pumpAndSettle();

      expect(
        find.byType(MonitorMetricDetail),
        findsOneWidget,
        reason: 'Tapping a custom metric row must open its detail sheet',
      );
      // The sheet loads the metric's real readings on mount, and this bare test
      // host serves none, so it renders its honest no-readings state rather than
      // a section of invented rows. The readings section itself is covered
      // against a real series in the MonitorMetricDetail group below.
      expect(
        find.text(trans('uptizm.monitors.metrics_detail_no_readings')),
        findsOneWidget,
        reason: 'the opened detail sheet reports having no readings rather '
            'than inventing a series',
      );
    });

    testWidgets('custom metric rows are WAnchor-backed (cursor + hover)', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      // Each custom row's tappable shell is a WAnchor carrying the
      // hover:bg-surface-container token, so it shows the pointer cursor and a
      // hover surface (a bare GestureDetector gives neither). Assert by the
      // distinctive row className rather than a raw WAnchor count (Buttons and
      // other controls are WAnchor-backed too).
      final hoverRows = find.byWidgetPredicate(
        (w) =>
            w is WDiv &&
            (w.className?.contains('hover:bg-surface-container') ?? false) &&
            (w.className?.contains('border-color-border') ?? false),
      );
      expect(
        hoverRows,
        findsNWidgets(3),
        reason: 'Each custom metric row must carry the hover-surface token',
      );
    });

    testWidgets('renders "Add metric" button when custom metrics exist', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_add')),
        findsOneWidget,
        reason:
            'Add metric button must appear when custom metrics list is non-empty',
      );
    });

    testWidgets('does NOT render the empty-state title', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_empty_title')),
        findsNothing,
        reason:
            'Empty state must not appear when the monitor has custom metrics',
      );
    });

    testWidgets(
      'Save in the create sheet POSTs the new metric and it renders after '
      'the post-create reload',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 2400));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        // A callback stub: POST creates (201), everything else (the
        // post-create reload's GET) answers with the pre-existing 3 metrics
        // plus the newly created one, so the assertion below proves the row
        // came from a live reload rather than optimistic local state.
        final FakeNetworkDriver fake = Http.fake((request) {
          if (request.method == 'POST') {
            return Http.response({'data': {}}, 201);
          }
          return Http.response({
            'data': [
              {
                'id': 'm1',
                'label': 'Memory usage',
                'key': 'memory_usage',
                'type': 'numeric',
                'latest': {'numeric_value': 73},
              },
              {
                'id': 'm2',
                'label': 'CPU load',
                'key': 'cpu_load',
                'type': 'numeric',
                'latest': {'numeric_value': 41},
              },
              {
                'id': 'm3',
                'label': 'Request rate',
                'key': 'req_rate',
                'type': 'numeric',
                'latest': {'numeric_value': 96},
              },
              {
                'id': 'm4',
                'label': 'Queue depth',
                'key': 'queue_depth',
                'type': 'numeric',
                'latest': {'numeric_value': 12},
              },
            ],
          });
        });

        // The BottomSheet mounts on the root Overlay, so WindTheme must sit
        // above the Navigator (React MagicApplication.builder pattern).
        await tester.pumpWidget(
          wrapRootTheme(const MonitorMetricsTab(monitorId: 'api')),
        );
        await tester.pump();

        await tester.tap(find.text(trans('uptizm.monitors.metrics_add')));
        await tester.pumpAndSettle();

        final Finder nameField = find.widgetWithText(
          MSInput,
          trans('uptizm.monitors.metrics_form_name_placeholder'),
        );
        await tester.tap(nameField);
        await tester.pumpAndSettle();
        await tester.enterText(nameField, 'Queue depth');
        await tester.pump();

        // The default source is `json`, which requires a non-empty
        // extraction path before Save enables (`_ruleReady`); fill it via
        // its source-specific placeholder.
        final Finder pathField = find.widgetWithText(
          MSInput,
          kPathPlaceholder['json']!,
        );
        await tester.tap(pathField);
        await tester.pumpAndSettle();
        await tester.enterText(pathField, r'$.queue.depth');
        await tester.pump();

        final Finder saveButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.metrics_form_save_create'),
        );
        await tester.tap(saveButton);
        await tester.pumpAndSettle();

        fake.assertSent(
          (r) => r.method == 'POST' && r.url == '/monitors/api/metrics',
        );
        expect(
          find.text('Queue depth'),
          findsOneWidget,
          reason:
              'The newly created metric must render after the post-create '
              'reload, proving the write persisted through the live endpoint',
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // MonitorMetricsTab: 'docs' monitor (no custom metrics).
  // ---------------------------------------------------------------------------

  group('MonitorMetricsTab, monitorId: docs (no custom metrics)', () {
    testWidgets('renders the empty-state title', (tester) async {
      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'docs')));
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_empty_title')),
        findsOneWidget,
        reason:
            'Empty state title must appear for a monitor with no custom metrics',
      );
    });

    testWidgets('does NOT render the Add metric button', (tester) async {
      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'docs')));
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_add')),
        findsNothing,
        reason:
            'Add metric button must be absent when the custom metrics list is empty',
      );
    });
  });

  // ---------------------------------------------------------------------------
  // MonitorMetricForm: pumped directly (no BottomSheet overlay).
  // ---------------------------------------------------------------------------

  group('MonitorMetricForm, create mode', () {
    testWidgets('typing a Name slugifies the Key field', (tester) async {
      await tester.binding.setSurfaceSize(const Size(600, 3000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrapForm(
          MonitorMetricForm(
            initial: kEmptyMetricForm,
            isEdit: false,
            onSave: (_) async => <String, String>{},
            onPreview: (_) async => null,
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      // Find the Name field by its label text; tap it and type.
      final Finder nameField = find.widgetWithText(
        MSInput,
        trans('uptizm.monitors.metrics_form_name_placeholder'),
      );
      await tester.tap(nameField);
      await tester.pumpAndSettle();
      await tester.enterText(nameField, 'Memory Usage');
      await tester.pump();

      // The Key TextField should now contain the slugified value.
      final Finder keyField = find.widgetWithText(
        MSInput,
        trans('uptizm.monitors.metrics_form_key_placeholder'),
      );
      final MSInput keyInput = tester.widget<MSInput>(keyField);
      expect(
        keyInput.controller?.text ?? keyInput.value,
        equals(slugify('Memory Usage')),
        reason: 'Key field must auto-slugify from the Name value',
      );
    });

    testWidgets(
      'tapping Save on a blank form paints inline required errors and does '
      'not round-trip',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(600, 3000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        bool submitted = false;
        await tester.pumpWidget(
          wrapForm(
            MonitorMetricForm(
              initial: kEmptyMetricForm,
              isEdit: false,
              onSave: (_) async {
                submitted = true;
                return <String, String>{};
              },
              onPreview: (_) async => null,
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();

        await tester.tap(
          find.widgetWithText(
            MSButton,
            trans('uptizm.monitors.metrics_form_save_create'),
          ),
        );
        await tester.pump();

        expect(
          submitted,
          isFalse,
          reason: 'A blank required form must never reach the write path',
        );
        expect(
          find.text(trans('uptizm.monitors.form_name_error_required')),
          findsOneWidget,
          reason: 'The blank Name must surface its required error inline',
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_key_error_required')),
          findsOneWidget,
          reason: 'The blank Key must surface its required error inline',
        );
      },
    );

    testWidgets(
      'tapping Save on a valid form hands the form to onSave',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(600, 3000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final MetricForm seeded = kEmptyMetricForm.copyWith(
          label: 'Memory usage',
          key: 'memory_usage',
          path: r'$.system.memory.used_pct',
        );

        MetricForm? submitted;
        await tester.pumpWidget(
          wrapForm(
            MonitorMetricForm(
              initial: seeded,
              isEdit: false,
              onSave: (form) async {
                submitted = form;
                return <String, String>{};
              },
              onPreview: (_) async => null,
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();

        await tester.tap(
          find.widgetWithText(
            MSButton,
            trans('uptizm.monitors.metrics_form_save_create'),
          ),
        );
        await tester.pump();

        expect(
          submitted,
          isNotNull,
          reason: 'A valid form must reach the write path',
        );
        expect(submitted!.label, equals('Memory usage'));
        expect(submitted!.key, equals('memory_usage'));
      },
    );

    testWidgets(
      'for type=status the Unit select and Direction control are absent',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(600, 3000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final MetricForm statusForm = kEmptyMetricForm.copyWith(
          label: 'Service health',
          key: 'service_health',
          type: 'status',
        );

        await tester.pumpWidget(
          wrapForm(
            MonitorMetricForm(
              initial: statusForm,
              isEdit: false,
              onSave: (_) async => <String, String>{},
              onPreview: (_) async => null,
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();

        // Unit and Direction are numeric-only; they must be absent when
        // type=status.
        expect(
          find.text(trans('uptizm.monitors.metrics_form_unit_label')),
          findsNothing,
          reason: 'Unit select label must be absent for type=status',
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_direction_label')),
          findsNothing,
          reason: 'Direction control label must be absent for type=status',
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // MonitorMetricDetail: pumped directly.
  // ---------------------------------------------------------------------------

  group('MonitorMetricDetail', () {
    /// Builds a MetricForm from the first api custom metric (Memory usage).
    MetricForm memoryUsageForm() {
      final MonitorMetric m = customMetricsForMonitors(['api']).first;
      return fromCatalog(m);
    }

    /// Three real readings, oldest first, the shape the series endpoint sends.
    List<MetricSeriesPoint> readings() {
      final DateTime base = DateTime.utc(2026, 7, 29, 10);
      return [
        MetricSeriesPoint(
          recordedAt: base,
          numericValue: 61.2,
          statusValue: null,
          stringValue: null,
          band: 'ok',
        ),
        MetricSeriesPoint(
          recordedAt: base.add(const Duration(minutes: 5)),
          numericValue: 74.5,
          statusValue: null,
          stringValue: null,
          band: 'warn',
        ),
        MetricSeriesPoint(
          recordedAt: base.add(const Duration(minutes: 10)),
          numericValue: 91.8,
          statusValue: null,
          stringValue: null,
          band: 'critical',
        ),
      ];
    }

    Future<void> pumpDetail(
      WidgetTester tester,
      List<MetricSeriesPoint> series,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorMetricDetail(
            metric: memoryUsageForm(),
            onLoadSeries: () async => series,
            onEdit: () {},
            onDelete: () {},
          ),
        ),
      );
      // One pump resolves the load future, a second paints the result.
      await tester.pump();
      await tester.pump();
    }

    testWidgets('charts the metric\'s real readings', (tester) async {
      await pumpDetail(tester, readings());

      expect(find.byType(MetricChart), findsOneWidget);
    });

    testWidgets('shows the newest reading as the latest value', (tester) async {
      // The regression this pins: the latest value used to be read off the last
      // point of a locally generated sine wave, so it disagreed with the real
      // reading the metrics list showed for the same metric.
      await pumpDetail(tester, readings());

      final Iterable<WText> texts = tester.widgetList<WText>(
        find.byType(WText),
      );
      expect(
        texts.any((w) => w.data.contains('91.8')),
        isTrue,
        reason: 'the newest recorded reading is the latest value',
      );
      expect(
        texts.any((w) => w.data.contains('61.2')),
        isTrue,
        reason: 'older readings still appear in the recent-readings list',
      );
    });

    testWidgets('says so when the metric has no readings', (tester) async {
      // A metric whose rule never extracted anything has no history. It used to
      // render a full 24-hour series anyway.
      await pumpDetail(tester, const []);

      expect(
        find.text(trans('uptizm.monitors.metrics_detail_no_readings')),
        findsOneWidget,
      );
      expect(
        find.byType(MetricChart),
        findsNothing,
        reason: 'no readings means no chart, not an invented one',
      );
    });

    testWidgets('renders the "latest · last 24h" label', (tester) async {
      await pumpDetail(tester, readings());

      expect(
        find.text(trans('uptizm.monitors.metrics_detail_latest')),
        findsOneWidget,
      );
    });

    /// A `string`-typed metric form: no `warn`/`critical`/`unit` matter, only
    /// the type discriminates which field of `MetricSeriesPoint` is real.
    MetricForm stringMetricForm() {
      return MetricForm(
        label: 'Active region',
        key: 'active_region',
        type: 'string',
        source: 'json',
        path: r'$.region',
        unit: 'count',
        direction: 'high',
        warn: '',
        critical: '',
        value: null,
      );
    }

    /// Two real string readings, oldest first: an older `eu-central` and a
    /// newest `degraded`.
    List<MetricSeriesPoint> stringReadings() {
      final DateTime base = DateTime.utc(2026, 7, 29, 10);
      return [
        MetricSeriesPoint(
          recordedAt: base,
          numericValue: null,
          statusValue: null,
          stringValue: 'eu-central',
          band: 'ok',
        ),
        MetricSeriesPoint(
          recordedAt: base.add(const Duration(minutes: 5)),
          numericValue: null,
          statusValue: null,
          stringValue: 'degraded',
          band: 'warn',
        ),
      ];
    }

    testWidgets(
      'shows a string metric\'s newest reading as the hero value',
      (tester) async {
        // The regression this pins: the hero value was gated on
        // `numericValue`, so a string metric's real latest reading rendered
        // NOTHING at all, not even the wrong thing.
        await tester.binding.setSurfaceSize(const Size(1280, 2400));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            MonitorMetricDetail(
              metric: stringMetricForm(),
              onLoadSeries: () async => stringReadings(),
              onEdit: () {},
              onDelete: () {},
            ),
          ),
        );
        await tester.pump();
        await tester.pump();

        final Iterable<WText> texts = tester.widgetList<WText>(
          find.byType(WText),
        );
        expect(
          texts.any((w) => w.data == 'degraded'),
          isTrue,
          reason: 'the newest string reading is the hero value',
        );
      },
    );

    testWidgets(
      'lists a string metric\'s real readings, not an empty list',
      (tester) async {
        // The regression this pins: the recent-readings list was built from
        // the numeric-filtered series, so a string metric's list was always
        // empty regardless of how many readings it had.
        await tester.binding.setSurfaceSize(const Size(1280, 2400));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            MonitorMetricDetail(
              metric: stringMetricForm(),
              onLoadSeries: () async => stringReadings(),
              onEdit: () {},
              onDelete: () {},
            ),
          ),
        );
        await tester.pump();
        await tester.pump();

        final Iterable<WText> texts = tester.widgetList<WText>(
          find.byType(WText),
        );
        expect(
          texts.any((w) => w.data == 'eu-central'),
          isTrue,
          reason: 'the older string reading still appears in the '
              'recent-readings list',
        );
      },
    );

    testWidgets(
      'a non-numeric metric has no chart, not an empty one',
      (tester) async {
        // A string cannot sit on a y-axis; the chart is legitimately absent
        // for a non-numeric metric even though it has real readings.
        await tester.binding.setSurfaceSize(const Size(1280, 2400));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            MonitorMetricDetail(
              metric: stringMetricForm(),
              onLoadSeries: () async => stringReadings(),
              onEdit: () {},
              onDelete: () {},
            ),
          ),
        );
        await tester.pump();
        await tester.pump();

        expect(find.byType(MetricChart), findsNothing);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // Extraction test panel: it must report the BACKEND's answer, never its own.
  // ---------------------------------------------------------------------------

  group('MonitorMetricForm extraction test panel', () {
    /// Pumps the form with a ready json rule and a canned preview answer, taps
    /// "Fetch & test", and returns once the verdict has rendered.
    Future<void> runTest(
      WidgetTester tester,
      MetricPreviewResult? answer, {
      MetricForm? initial,
    }) async {
      await tester.binding.setSurfaceSize(const Size(600, 3000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrapForm(
          MonitorMetricForm(
            initial: (initial ?? kEmptyMetricForm).copyWith(
              label: 'Latency',
              key: 'latency_ms',
              path: r'$.data.latency_ms',
            ),
            isEdit: false,
            onSave: (_) async => <String, String>{},
            onPreview: (_) async => answer,
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final Finder button = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.metrics_form_fetch_test'),
      );
      await tester.ensureVisible(button);
      await tester.tap(button);
      await tester.pump();
      await tester.pump();
    }

    MetricPreviewResult answer({
      String? value,
      bool typeValid = true,
      String? error,
      String? band,
      bool hasSample = true,
    }) {
      return MetricPreviewResult(
        value: value,
        typeValid: typeValid,
        error: error,
        band: band,
        hasSample: hasSample,
        sampleCheckedAt: DateTime.now(),
        sampleStatusCode: 200,
      );
    }

    testWidgets('renders the value the backend extracted', (tester) async {
      await runTest(tester, answer(value: '185', band: 'ok'));

      expect(find.text(trans('uptizm.monitors.metrics_form_resolved').toUpperCase()), findsOneWidget);
      expect(
        find.textContaining('185'),
        findsWidgets,
        reason: 'the panel shows the backend value, not a constant per unit',
      );
    });

    testWidgets('reports a failed rule as failed, with the backend reason', (
      tester,
    ) async {
      // The regression this pins. The panel used to resolve the path against a
      // hardcoded sample map and report "RESOLVED" with a constant value, so it
      // confirmed rules the real pipeline could never extract: live QA saw it
      // claim "RESOLVED 73.4 %" for a path absent from the monitor's response.
      await runTest(
        tester,
        answer(error: 'No value at path `\$.data.latency_ms`.', typeValid: false),
      );

      expect(
        find.text(trans('uptizm.monitors.metrics_form_resolved').toUpperCase()),
        findsNothing,
        reason: 'a rule that resolved nothing must never read as resolved',
      );
      expect(find.textContaining('No value at path'), findsOneWidget);
    });

    testWidgets('a type mismatch is not a resolution', (tester) async {
      // The rule found something, but not of the declared type, so the metric
      // would record nothing. That is a failure, not a value.
      await runTest(tester, answer(value: 'service stable', typeValid: false));

      expect(
        find.text(trans('uptizm.monitors.metrics_form_resolved').toUpperCase()),
        findsNothing,
      );
    });

    testWidgets('says so when the monitor has never been checked', (
      tester,
    ) async {
      await runTest(tester, answer(hasSample: false));

      expect(find.text(trans('uptizm.monitors.metrics_form_no_sample')), findsOneWidget);
      expect(
        find.text(trans('uptizm.monitors.metrics_form_resolved').toUpperCase()),
        findsNothing,
      );
    });

    testWidgets('names the sample it verified against', (tester) async {
      // The panel used to print a hardcoded sample JSON body, implying it had
      // just fetched the endpoint. It states which check it used instead.
      await runTest(tester, answer(value: '185', band: 'ok'));

      expect(find.textContaining('Verified against the check'), findsOneWidget);
    });

    testWidgets('offers no threshold suggestion before anything is measured', (
      tester,
    ) async {
      // The suggestion copy states a baseline ("typically reads near X"), which
      // was previously derived from a constant-per-unit fallback, so a brand-new
      // metric was told it "typically reads near 73.4 %".
      await tester.binding.setSurfaceSize(const Size(600, 3000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrapForm(
          MonitorMetricForm(
            initial: kEmptyMetricForm.copyWith(
              label: 'Latency',
              key: 'latency_ms',
              path: r'$.data.latency_ms',
            ),
            isEdit: false,
            onSave: (_) async => <String, String>{},
            onPreview: (_) async => null,
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_form_ai_suggestion')),
        findsNothing,
      );
    });

    testWidgets('offers a suggestion once a real value has been measured', (
      tester,
    ) async {
      await runTest(tester, answer(value: '185', band: 'ok'));

      expect(
        find.text(trans('uptizm.monitors.metrics_form_ai_suggestion')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // Metric rows: the value shown is the reading, or an em-dash.
  // ---------------------------------------------------------------------------

  group('MonitorMetricsTab custom rows', () {
    Future<void> pumpWith(
      WidgetTester tester,
      List<MonitorMetricRecord> records,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      MonitorMetricsController.instance.seedForTest('api', records);
      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();
    }

    MonitorMetricRecord record({
      required String type,
      num? value,
      String? latestStatus,
      String? latestString,
      String? latestBand,
    }) {
      return MonitorMetricRecord(
        id: 'm1',
        latestStatus: latestStatus,
        latestString: latestString,
        latestBand: latestBand,
        form: MetricForm(
          label: 'Probe',
          key: 'probe',
          type: type,
          source: 'json',
          path: 'a.b',
          unit: 'ms',
          direction: 'high',
          warn: '',
          critical: '',
          value: value,
        ),
      );
    }

    testWidgets('a metric with no reading shows an em-dash, never 0', (
      tester,
    ) async {
      // The regression this pins. A rule that extracts nothing (a wrong path, an
      // absent header) used to display `0`, and for a latency or error-count
      // metric `0` reads as perfect health rather than "this rule is dead".
      await pumpWith(tester, [record(type: 'numeric')]);

      expect(find.text('—'), findsWidgets);
      expect(
        find.text('0'),
        findsNothing,
        reason: 'a missing reading must not be rendered as zero',
      );
    });

    testWidgets('a status metric shows its real reading, not "operational"', (
      tester,
    ) async {
      // The row used to render the LITERAL word "operational" for every status
      // metric, so one reading `down` displayed as healthy.
      await pumpWith(
        tester,
        [record(type: 'status', latestStatus: 'down')],
      );

      expect(find.text('down'), findsOneWidget);
      expect(find.text('operational'), findsNothing);
    });

    testWidgets('a string metric shows its real reading, not "ok"', (
      tester,
    ) async {
      await pumpWith(
        tester,
        [record(type: 'string', latestString: 'eu-central')],
      );

      expect(find.text('eu-central'), findsOneWidget);
      expect(find.text('ok'), findsNothing);
    });

    testWidgets('a numeric reading renders formatted with its unit', (
      tester,
    ) async {
      await pumpWith(
        tester,
        [record(type: 'numeric', value: 185, latestBand: 'ok')],
      );

      expect(find.text('185 ms'), findsOneWidget);
    });

    testWidgets(
      'a non-numeric metric with no reading shows an em-dash, never a '
      'fabricated word',
      (tester) async {
        await pumpWith(tester, [record(type: 'string')]);

        expect(find.text('—'), findsWidgets);
        expect(find.text('ok'), findsNothing);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // First-load skeleton: a pending catalog read is not an empty catalog.
  // ---------------------------------------------------------------------------

  group('MonitorMetricsTab first load', () {
    testWidgets('shows a skeleton before the catalog read resolves', (
      tester,
    ) async {
      // The regression this pins: a monitor WITH custom metrics rendered the
      // "no custom metrics" empty state until its catalog read answered.
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      MagicApp.reset();
      Magic.flush();
      Magic.singleton('magic_starter', () => MagicStarterManager());
      Magic.singleton('log', () => LogManager());
      Http.fake();

      // Deliberately NOT pumped again: the first frame paints before the tab's
      // own catalog fetch resolves.
      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));

      expect(find.byType(MSSkeleton), findsWidgets);
      expectVisibleSkeletons(tester);
      expect(
        find.text(trans('uptizm.monitors.metrics_empty_title')),
        findsNothing,
        reason: 'a pending read must not claim the monitor has no metrics',
      );

      // Once it resolves (the fake answers nothing) the honest empty state shows.
      await tester.pump();
      expect(
        find.text(trans('uptizm.monitors.metrics_empty_title')),
        findsOneWidget,
      );
    });

    testWidgets('a seeded catalog renders rows, never a skeleton', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      // The suite's setUp seeds this monitor, which counts as resolved.
      expect(find.byType(MSSkeleton), findsNothing);
    });
  });
}
