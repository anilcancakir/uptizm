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
      // The detail body renders the section header uppercased.
      expect(
        find.text(
          trans('uptizm.monitors.metrics_recent_readings').toUpperCase(),
        ),
        findsOneWidget,
        reason:
            'The opened detail sheet must render the Recent readings section',
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
            onSave: (_) {},
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

    testWidgets('Save button is disabled when both label and key are empty', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(600, 3000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrapForm(
          MonitorMetricForm(
            initial: kEmptyMetricForm,
            isEdit: false,
            onSave: (_) {},
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final Finder saveButton = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.metrics_form_save_create'),
      );
      expect(saveButton, findsOneWidget);
      final MSButton btn = tester.widget<MSButton>(saveButton);
      expect(
        btn.disabled,
        isTrue,
        reason: 'Save must be disabled when label and key are empty',
      );
    });

    testWidgets(
      'Save button is enabled when label and key are both non-empty',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(600, 3000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final MetricForm seeded = kEmptyMetricForm.copyWith(
          label: 'Memory usage',
          key: 'memory_usage',
          path: r'$.system.memory.used_pct',
        );

        await tester.pumpWidget(
          wrapForm(
            MonitorMetricForm(
              initial: seeded,
              isEdit: false,
              onSave: (_) {},
              onCancel: () {},
            ),
          ),
        );
        await tester.pump();

        final Finder saveButton = find.widgetWithText(
          MSButton,
          trans('uptizm.monitors.metrics_form_save_create'),
        );
        expect(saveButton, findsOneWidget);
        final MSButton btn = tester.widget<MSButton>(saveButton);
        expect(
          btn.disabled,
          isFalse,
          reason: 'Save must be enabled when label and key are both non-empty',
        );
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
              onSave: (_) {},
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

    testWidgets('renders a MetricChart for a numeric metric', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorMetricDetail(
            metric: memoryUsageForm(),
            onEdit: () {},
            onDelete: () {},
          ),
        ),
      );
      await tester.pump();

      expect(
        find.byType(MetricChart),
        findsOneWidget,
        reason: 'MetricChart must be present for a numeric metric',
      );
    });

    testWidgets('renders the latest value text for a numeric metric', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final MetricForm form = memoryUsageForm();

      await tester.pumpWidget(
        wrap(MonitorMetricDetail(metric: form, onEdit: () {}, onDelete: () {})),
      );
      await tester.pump();

      // The detail computes latest from the augmented series; we just confirm
      // a fmt'd WText with the unit suffix is present.
      final Iterable<WText> texts = tester.widgetList<WText>(
        find.byType(WText),
      );
      final bool hasValueText = texts.any(
        (w) => w.data.contains(kUnitSuffix[form.unit] ?? form.unit),
      );
      expect(
        hasValueText,
        isTrue,
        reason: 'A formatted value text with the unit suffix must be visible',
      );
    });

    testWidgets('renders the "latest · last 24h" label', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          MonitorMetricDetail(
            metric: memoryUsageForm(),
            onEdit: () {},
            onDelete: () {},
          ),
        ),
      );
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_detail_latest')),
        findsOneWidget,
        reason: 'The "latest · last 24h" label must be present',
      );
    });
  });
}
