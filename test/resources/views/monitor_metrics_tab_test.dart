import 'dart:async';

import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/controllers/monitor_metrics_controller.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/support/metric_types.dart' show MonitorMetric;
import 'package:uptizm/app/mocks/metrics.dart';
import 'package:uptizm/resources/views/monitors/monitor_metric_detail.dart';
import 'package:uptizm/resources/views/monitors/monitor_metric_form.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_tab.dart';
import 'package:uptizm/ui/components/metric_chart/index.dart';
import 'package:uptizm/ui/components/status_dot/index.dart';
import 'package:uptizm/ui/components/string_value_list/index.dart';
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

      // MonitorMetricsTab: discovery panel.
      'uptizm.monitors.metrics_suggest_title': 'Suggested',
      'uptizm.monitors.metrics_suggest_action': 'Suggest',
      'uptizm.monitors.metrics_suggest_again': 'Again',
      'uptizm.monitors.metrics_suggest_help': 'Tap one.',
      'uptizm.monitors.metrics_suggest_empty': 'Nothing to suggest.',
      'uptizm.monitors.metrics_suggest_failed': 'Could not reach it.',
      'uptizm.monitors.metrics_suggest_gated': 'Needs :plan.',
      'uptizm.monitors.metrics_suggest_rule_badge': 'rule',
      'uptizm.monitors.create_ai_metric_observed': 'now :observed',
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
      'uptizm.monitors.metrics_recent_readings_frozen_note':
          'Bands are recorded at check time.',
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

      // MonitorMetricForm string-band block: the three value lists, the
      // unmatched-band select and the two cross-field rules the server repeats.
      'uptizm.monitors.metrics_form_string_band_label': 'String matching',
      'uptizm.monitors.metrics_form_string_band_help': 'Which values alert.',
      'uptizm.monitors.metrics_form_ok_values_label': 'OK values',
      'uptizm.monitors.metrics_form_ok_values_placeholder': 'ok',
      'uptizm.monitors.metrics_form_warn_values_label': 'Warn values',
      'uptizm.monitors.metrics_form_warn_values_placeholder': 'degraded',
      'uptizm.monitors.metrics_form_critical_values_label': 'Critical values',
      'uptizm.monitors.metrics_form_critical_values_placeholder': 'down',
      'uptizm.monitors.metrics_form_unmatched_band_label': 'Unmatched',
      'uptizm.monitors.metrics_form_unmatched_band_help': 'Band for the rest.',
      'uptizm.monitors.metrics_band_ok': 'OK band',
      'uptizm.monitors.metrics_band_warn': 'Warn band',
      'uptizm.monitors.metrics_band_critical': 'Critical band',
      'uptizm.monitors.metrics_form_string_match_note': 'Case-insensitive.',
      'uptizm.monitors.metrics_form_unmatched_band_silent':
          'Unset means no alert.',
      'uptizm.monitors.metrics_form_string_values_error_overlap':
          'Value in two lists.',
      'uptizm.monitors.metrics_form_string_values_error_unmatched_needs_list':
          'Needs a value list.',
      'uptizm.monitors.string_values_add': 'Add',
      'uptizm.monitors.string_values_placeholder': 'Value',

      // MonitorMetricForm candidate browser.
      'uptizm.monitors.metrics_form_candidates_title': 'Candidates',
      'uptizm.monitors.metrics_form_candidates_hint': 'From the last response.',
      'uptizm.monitors.metrics_form_candidates_empty': 'None extracted.',
      'uptizm.monitors.metrics_form_candidates_fetch': 'Fetch values',
      'uptizm.monitors.metrics_form_candidates_fetching': 'Fetching values...',
      'uptizm.monitors.metrics_form_candidates_error': 'Load failed.',
      'uptizm.monitors.metrics_form_candidate_use': 'Use',

      // Common.
      'uptizm.common.cancel': 'Cancel',
    };
  }
}

/// A paginator over the readings endpoint, for the detail-sheet cases.
///
/// The sheet owns and disposes what this returns. These cases assert on the
/// hero value and the chart, which come from `onLoadSeries`; the readings table
/// pages its own endpoint, and with no stub registered it simply answers empty,
/// which is the state those cases already expect below the fold.
/// Answers the readings endpoint with [points], newest first.
///
/// The readings table pages its OWN endpoint rather than reusing the chart's
/// series, so a case asserting on that list has to serve it. Newest-first
/// because that is the order the endpoint returns and the order the table
/// renders.
void _stubReadings(List<MetricSeriesPoint> points) {
  // A leading wildcard, because a stub pattern is a FULL match: a bare
  // 'readings' matches nothing, the fake falls through to its empty default,
  // and the table renders empty with no request-level clue that a stub missed.
  Http.fake({
    '*readings': Http.response({
      'data': [
        for (final MetricSeriesPoint p in points.reversed)
          {
            'id': 'r-${p.recordedAt?.millisecondsSinceEpoch ?? 0}',
            'recorded_at': p.recordedAt?.toIso8601String(),
            'numeric_value': p.numericValue,
            'string_value': p.stringValue,
            'status_value': p.statusValue,
            'band': p.band,
          },
      ],
    }),
  });
}

MagicPaginator<MetricSeriesPoint> _readingsPaginator() {
  return MagicPaginator<MetricSeriesPoint>(
    url: '/monitors/m1/metrics/met-1/readings',
    fromMap: MetricSeriesPoint.fromMap,
  );
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind MagicStarter so magic_starter widgets (Button, BottomSheet, etc.)
    // resolve their theme without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Bind LogManager: the tab's discovery panel reads
    // `EntitlementController.instance`, which self-triggers a billing reload,
    // and that reload's offline-degradation path calls `Log.error` on the
    // failure the empty fake below produces. Without the binding the degrade
    // itself throws, which surfaces as every widget test in this file failing
    // for a reason none of them are about.
    Magic.singleton('log', () => LogManager());
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

    testWidgets('tapping the system metric opens it, read only', (
      tester,
    ) async {
      // Response time was the one metric on this screen you could not look
      // into, even though it is the one every monitor has. Its chart and its
      // paged history are `response-times` and `checks`, both of which already
      // existed for the Overview tab.
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Http.fake({
        '*response-times*': Http.response({
          'data': [
            {'response_ms': 148, 'checked_at': '2026-08-28T10:00:00Z'},
          ],
        }),
        '*checks*': Http.response({
          'data': [
            {'response_ms': 148, 'checked_at': '2026-08-28T10:00:00Z'},
          ],
        }),
      });

      await tester.pumpWidget(
        wrapRootTheme(const MonitorMetricsTab(monitorId: 'api')),
      );
      await tester.pump();

      await tester.tap(
        find.text(trans('uptizm.monitors.metrics_response_time')),
      );
      await tester.pumpAndSettle();

      expect(find.byType(MonitorMetricDetail), findsOneWidget);

      // No definition behind it, so neither action is offered. A disabled
      // button would be an affordance that never becomes available.
      expect(find.text(trans('uptizm.monitors.action_edit')), findsNothing);
      expect(find.text(trans('uptizm.monitors.action_delete')), findsNothing);

      // And the note tells the truth about which kind of band this is: fixed
      // bounds, not a verdict frozen against thresholds somebody can edit.
      expect(
        find.text(trans('uptizm.monitors.metrics_recent_readings_system_note')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.monitors.metrics_recent_readings_frozen_note')),
        findsNothing,
      );

      // And each row carries its band DOT. The band has to be translated into
      // the backend's vocabulary (`ok | warn | critical`) rather than
      // `StatusKey`'s own names: handing the sheet `up | degraded | down`
      // matches nothing, falls to null, and the dot silently disappears with
      // no warning anywhere, because both are valid strings.
      expect(
        find.descendant(
          of: find.byType(MonitorMetricDetail),
          matching: find.byType(StatusDot),
        ),
        findsWidgets,
        reason: 'a reading with a value has a band, so it has a dot',
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
    testWidgets('a second Save tap mid-write is dropped', (tester) async {
      // `SubmitsOnce` exists because three forms needed this at once, and its
      // own docblock predicted that "the fourth form would have forgotten it".
      // This was the fourth form. A double tap sent two
      // `POST /monitors/:id/metrics`: the first response closed the sheet, and
      // the second took a 422 on the per-monitor unique key that reached
      // nobody, because `!mounted` swallowed it into a log line.
      await tester.binding.setSurfaceSize(const Size(600, 3000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      int saves = 0;
      final Completer<void> inFlight = Completer<void>();

      await tester.pumpWidget(
        wrapForm(
          MonitorMetricForm(
            initial: kEmptyMetricForm.copyWith(
              label: 'Memory usage',
              key: 'memory_usage',
              path: r'$.memory',
            ),
            isEdit: false,
            onSave: (_) async {
              saves++;
              // Hold the write open so the second tap lands mid-flight, which
              // is the only window the bug lived in.
              await inFlight.future;
              return <String, String>{};
            },
            onPreview: (_) async => null,
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();

      final Finder save = find.text(
        trans('uptizm.monitors.metrics_form_save_create'),
      );
      await tester.ensureVisible(save);
      await tester.tap(save);
      await tester.pump();

      // The label is gone mid-flight because the button swaps its child for the
      // loading content, which is the same switch that drops its onTap.
      await tester.tap(find.byType(MSButton).last, warnIfMissed: false);
      await tester.pump();

      expect(saves, 1, reason: 'the second tap must be dropped');

      inFlight.complete();
      await tester.pumpAndSettle();

      expect(saves, 1, reason: 'and it must not fire once the first resolves');
    });

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
  // The string-band block: three value lists plus the unmatched-band select,
  // shown for `string` only, and the two cross-field rules the server repeats.
  // ---------------------------------------------------------------------------

  group('MonitorMetricForm string-band block', () {
    /// A `string` draft that already passes every non-band client check, so a
    /// blocked save can only be one of the two string-band rules.
    MetricForm stringDraft() => kEmptyMetricForm.copyWith(
      label: 'Health status',
      key: 'health_status',
      type: 'string',
      path: r'$.status',
    );

    /// Pumps the form and returns the list Save writes the submitted draft
    /// into; it stays EMPTY when the client blocked the write, which is how the
    /// "no request sent" half of each rule is pinned.
    Future<List<MetricForm>> pumpForm(
      WidgetTester tester,
      MetricForm initial, {
      Map<String, String> serverErrors = const {},
    }) async {
      await tester.binding.setSurfaceSize(const Size(600, 3000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final List<MetricForm> submitted = [];
      await tester.pumpWidget(
        wrapForm(
          MonitorMetricForm(
            initial: initial,
            isEdit: false,
            onSave: (form) async {
              submitted.add(form);
              return serverErrors;
            },
            onPreview: (_) async => null,
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();
      return submitted;
    }

    Future<void> tapSave(WidgetTester tester) async {
      final Finder save = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.metrics_form_save_create'),
      );
      await tester.ensureVisible(save);
      await tester.tap(save);
      // One pump paints the client verdict, the second lets an awaited onSave
      // resolve and its server errors land.
      await tester.pump();
      await tester.pump();
    }

    testWidgets(
      'type=string shows the three value lists and the unmatched-band select',
      (tester) async {
        await pumpForm(tester, stringDraft());

        expect(find.byType(StringValueList), findsNWidgets(3));
        expect(
          find.text(trans('uptizm.monitors.metrics_form_ok_values_label')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_warn_values_label')),
          findsOneWidget,
        );
        expect(
          find.text(
            trans('uptizm.monitors.metrics_form_critical_values_label'),
          ),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_unmatched_band_label')),
          findsOneWidget,
        );
        // The numeric-only controls stay out: a string metric has no direction
        // and no bounds to breach.
        expect(
          find.text(trans('uptizm.monitors.metrics_form_direction_label')),
          findsNothing,
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_warn_label')),
          findsNothing,
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_unit_label')),
          findsNothing,
        );
      },
    );

    testWidgets(
      'type=numeric shows direction and thresholds and no string block',
      (tester) async {
        await pumpForm(
          tester,
          kEmptyMetricForm.copyWith(
            label: 'Memory usage',
            key: 'memory_usage',
            path: r'$.system.memory.used_pct',
          ),
        );

        expect(
          find.text(trans('uptizm.monitors.metrics_form_direction_label')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_warn_label')),
          findsOneWidget,
        );
        expect(find.byType(StringValueList), findsNothing);
        expect(
          find.text(trans('uptizm.monitors.metrics_form_string_band_label')),
          findsNothing,
        );
      },
    );

    testWidgets(
      'type=status shows neither the numeric thresholds nor the string block',
      (tester) async {
        await pumpForm(
          tester,
          kEmptyMetricForm.copyWith(
            label: 'Service health',
            key: 'service_health',
            type: 'status',
            path: r'$.status',
          ),
        );

        expect(find.byType(StringValueList), findsNothing);
        expect(
          find.text(trans('uptizm.monitors.metrics_form_string_band_label')),
          findsNothing,
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_direction_label')),
          findsNothing,
        );
        expect(
          find.text(trans('uptizm.monitors.metrics_form_unit_label')),
          findsNothing,
        );
      },
    );

    testWidgets(
      'the same value in two lists blocks the write with an inline error',
      (tester) async {
        // Compared through normalizeMatchValue(), the same way the server
        // compares them, so `OK` against `ok` is the collision it looks like
        // rather than a pair the client waves through into a 422.
        final List<MetricForm> submitted = await pumpForm(
          tester,
          stringDraft().copyWith(
            okValues: ['ok'],
            criticalValues: ['OK'],
          ),
        );

        await tapSave(tester);

        expect(
          submitted,
          isEmpty,
          reason: 'an overlapping configuration must never reach the write path',
        );
        expect(
          find.text(
            trans('uptizm.monitors.metrics_form_string_values_error_overlap'),
          ),
          findsNWidgets(2),
          reason: 'both ends of the collision are named, not just one',
        );
      },
    );

    testWidgets(
      'an unmatched band with three empty lists blocks the write',
      (tester) async {
        final List<MetricForm> submitted = await pumpForm(
          tester,
          stringDraft().copyWith(unmatchedBand: 'critical'),
        );

        await tapSave(tester);

        expect(submitted, isEmpty);
        expect(
          find.text(
            trans(
              'uptizm.monitors.'
              'metrics_form_string_values_error_unmatched_needs_list',
            ),
          ),
          findsOneWidget,
        );
      },
    );

    testWidgets(
      'an unmatched band with one configured list is allowed through',
      (tester) async {
        // The negative case above must fail for the rule, not because a string
        // draft can never be saved at all.
        final List<MetricForm> submitted = await pumpForm(
          tester,
          stringDraft().copyWith(
            unmatchedBand: 'critical',
            okValues: ['ok'],
          ),
        );

        await tapSave(tester);

        expect(submitted, hasLength(1));
        expect(submitted.single.unmatchedBand, equals('critical'));
        expect(submitted.single.okValues, equals(['ok']));
      },
    );

    testWidgets(
      'a 422 on ok_values.1 paints an inline error on the healthy-values field',
      (tester) async {
        // The backend validates list ELEMENTS, so its key is dot-notation. The
        // form renders one chip editor per list, so the element key has to
        // collapse onto the list it belongs to or the message becomes a toast.
        final List<MetricForm> submitted = await pumpForm(
          tester,
          stringDraft().copyWith(okValues: ['ok', 'fine']),
          serverErrors: const {'ok_values.1': 'This value is too long.'},
        );

        await tapSave(tester);

        expect(submitted, hasLength(1));
        expect(find.text('This value is too long.'), findsOneWidget);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // Phone width. The shell swaps at `lg` (1024) and the repo's gate asks for
  // both sides, but every other test here pumps at 600 or wider. These two
  // surfaces are the ones with a real narrow-width risk: the value list's
  // `Row(Expanded + gap + button)` repeated three times, and a candidate row
  // holding a path up to the write path's 500-character ceiling.
  // ---------------------------------------------------------------------------

  group('MonitorMetricForm at phone width', () {
    Future<void> pumpAt390(
      WidgetTester tester,
      MetricForm initial, {
      Future<MetricCandidateSet?> Function()? onCandidates,
    }) async {
      await tester.binding.setSurfaceSize(const Size(390, 1600));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrapForm(
          MonitorMetricForm(
            initial: initial,
            isEdit: false,
            onSave: (_) async => <String, String>{},
            onPreview: (_) async => null,
            onCandidates: onCandidates,
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();
    }

    testWidgets('the three value lists lay out with values in them', (
      tester,
    ) async {
      await pumpAt390(
        tester,
        kEmptyMetricForm.copyWith(
          label: 'Health status',
          key: 'health_status',
          type: 'string',
          path: r'$.status',
          okValues: ['ok', 'operational'],
          warnValues: ['degraded'],
          criticalValues: ['down', 'maintenance-required'],
        ),
      );

      // A RenderFlex overflow surfaces here, which is the failure this width
      // exists to catch: the entry Row plus a committed chip row is the shape
      // most likely to run out of horizontal space.
      expect(tester.takeException(), isNull);
      expect(find.byType(StringValueList), findsNWidgets(3));
      expect(find.text('maintenance-required'), findsOneWidget);
    });

    testWidgets('a candidate row holds a long path without overflowing', (
      tester,
    ) async {
      // 480 characters, just under the write path's 500 ceiling, so this is the
      // widest path the endpoint can legally offer.
      final String longPath = '\$.${List.filled(60, 'segment').join('.')}';

      await pumpAt390(
        tester,
        kEmptyMetricForm.copyWith(
          label: 'Health status',
          key: 'health_status',
          type: 'string',
          source: 'header',
        ),
        onCandidates: () async => MetricCandidateSet(
          hasSample: true,
          candidates: [
            // No label: the row renders `label ?? path`, so a labelled
            // candidate never shows the path at all and could not overflow on
            // it. Unlabelled is the widest content the row can hold.
            MetricCandidate(
              ref: 'c1',
              source: 'json',
              path: longPath,
              value: 'a value long enough to compete for the same row',
              label: null,
              types: const ['string'],
            ),
          ],
        ),
      );

      final Finder fetch = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.metrics_form_candidates_fetch'),
      );
      await tester.ensureVisible(fetch);
      await tester.tap(fetch);
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.textContaining('segment.segment'), findsWidgets);
    });
  });

  // ---------------------------------------------------------------------------
  // The string verdict: the preview request must CARRY the lists, or the
  // server's banding is unreachable from the form.
  // ---------------------------------------------------------------------------

  group('MonitorMetricForm string preview', () {
    testWidgets(
      'the preview request carries the lists and the panel shows the server band',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(600, 3000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final FakeNetworkDriver fake = Http.fake((request) {
          return Http.response({
            'extracted_value': 'degraded',
            'type_valid': true,
            'error': null,
            'band': 'warn',
            'has_sample': true,
            'sample_checked_at': DateTime.utc(2026, 8, 5, 12).toIso8601String(),
            'sample_status_code': 200,
          });
        });

        await tester.pumpWidget(
          wrapForm(
            MonitorMetricForm(
              initial: kEmptyMetricForm.copyWith(
                label: 'Health status',
                key: 'health_status',
                type: 'string',
                path: r'$.status',
                okValues: ['ok'],
                warnValues: ['degraded'],
                criticalValues: ['down'],
                unmatchedBand: 'critical',
              ),
              isEdit: false,
              onSave: (_) async => <String, String>{},
              onPreview: (draft) =>
                  MonitorMetricsController.instance.preview('api', draft),
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

        final Map<String, dynamic> payload =
            fake.recorded
                    .firstWhere(
                      (entry) => entry.$1.url.contains('metrics/preview'),
                    )
                    .$1
                    .data
                as Map<String, dynamic>;
        expect(payload['ok_values'], equals(['ok']));
        expect(payload['warn_values'], equals(['degraded']));
        expect(payload['critical_values'], equals(['down']));
        expect(payload['unmatched_band'], equals('critical'));

        expect(
          tester.widget<StatusDot>(find.byType(StatusDot)).status,
          equals(StatusKey.degraded),
          reason: 'the verdict carries the band the SERVER returned, and the '
              'server can only band a string draft it was sent the lists for',
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // The candidate browser: five states, each with a real rendering.
  // ---------------------------------------------------------------------------

  group('MonitorMetricForm candidate browser', () {
    MetricCandidate candidate({List<String> types = const ['string']}) {
      return MetricCandidate(
        ref: 'c1',
        source: 'json',
        path: r'$.status',
        value: 'ok',
        label: 'status',
        types: types,
      );
    }

    /// Pumps the form with a candidate source wired, and returns the list Save
    /// writes the submitted draft into.
    Future<List<MetricForm>> pumpBrowser(
      WidgetTester tester,
      Future<MetricCandidateSet?> Function() onCandidates, {
      MetricForm? initial,
    }) async {
      await tester.binding.setSurfaceSize(const Size(600, 3000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final List<MetricForm> submitted = [];
      await tester.pumpWidget(
        wrapForm(
          MonitorMetricForm(
            initial: initial ??
                kEmptyMetricForm.copyWith(
                  label: 'Health status',
                  key: 'health_status',
                  source: 'header',
                ),
            isEdit: false,
            onSave: (form) async {
              submitted.add(form);
              return <String, String>{};
            },
            onPreview: (_) async => null,
            onCandidates: onCandidates,
            onCancel: () {},
          ),
        ),
      );
      await tester.pump();
      return submitted;
    }

    Future<void> tapFetch(WidgetTester tester) async {
      final Finder button = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.metrics_form_candidates_fetch'),
      );
      await tester.ensureVisible(button);
      await tester.tap(button);
      await tester.pump();
    }

    testWidgets('before any fetch it states where the values come from', (
      tester,
    ) async {
      await pumpBrowser(
        tester,
        () async => const MetricCandidateSet(candidates: [], hasSample: true),
      );

      expect(
        find.text(trans('uptizm.monitors.metrics_form_candidates_hint')),
        findsOneWidget,
      );
    });

    testWidgets('it says it is fetching while the request is in flight', (
      tester,
    ) async {
      final Completer<MetricCandidateSet?> pending =
          Completer<MetricCandidateSet?>();
      await pumpBrowser(tester, () => pending.future);

      await tapFetch(tester);

      expect(
        find.text(trans('uptizm.monitors.metrics_form_candidates_fetching')),
        findsOneWidget,
      );

      pending.complete(const MetricCandidateSet(candidates: [], hasSample: true));
      await tester.pump();
    });

    testWidgets('a transport failure renders the load error, not a blank box', (
      tester,
    ) async {
      await pumpBrowser(tester, () async => null);

      await tapFetch(tester);
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_form_candidates_error')),
        findsOneWidget,
      );
    });

    testWidgets('a monitor with nothing archived says there is no sample', (
      tester,
    ) async {
      await pumpBrowser(
        tester,
        () async => const MetricCandidateSet(candidates: [], hasSample: false),
      );

      await tapFetch(tester);
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_form_no_sample')),
        findsOneWidget,
      );
    });

    testWidgets('an archived body with no candidates says so', (tester) async {
      await pumpBrowser(
        tester,
        () async => const MetricCandidateSet(candidates: [], hasSample: true),
      );

      await tapFetch(tester);
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_form_candidates_empty')),
        findsOneWidget,
      );
    });

    testWidgets('it lists the rows a monitor with an archived body offers', (
      tester,
    ) async {
      await pumpBrowser(
        tester,
        () async => MetricCandidateSet(
          candidates: [candidate()],
          hasSample: true,
        ),
      );

      await tapFetch(tester);
      await tester.pump();

      expect(find.text('status'), findsOneWidget);
      expect(find.text('ok'), findsOneWidget);
      expect(
        find.text(trans('uptizm.monitors.metrics_form_candidate_use')),
        findsOneWidget,
      );
    });

    testWidgets('a long path stays one line instead of pushing the row open', (
      tester,
    ) async {
      // The backend accepts an extraction path up to 500 characters and a
      // sample value up to 128, both attacker-chosen. Left to wrap, one row
      // would be taller than the whole panel and bury every row under it.
      final String longPath = r'$.' + ('deeply_nested_key.' * 27);
      await pumpBrowser(
        tester,
        () async => MetricCandidateSet(
          candidates: [
            MetricCandidate(
              ref: 'c1',
              source: 'json',
              path: longPath,
              value: 'x' * 128,
              label: null,
              types: const ['string'],
            ),
          ],
          hasSample: true,
        ),
      );

      await tapFetch(tester);
      await tester.pump();

      expect(
        tester.getSize(find.text(longPath)).height,
        lessThan(40),
        reason: 'a 500-character path is truncated to one line, not wrapped '
            'across a dozen of them',
      );
    });

    testWidgets('tapping a row fills source, path and type and nothing else', (
      tester,
    ) async {
      final List<MetricForm> submitted = await pumpBrowser(
        tester,
        () async => MetricCandidateSet(
          candidates: [candidate()],
          hasSample: true,
        ),
      );

      await tapFetch(tester);
      await tester.pump();
      await tester.tap(
        find.text(trans('uptizm.monitors.metrics_form_candidate_use')),
      );
      await tester.pump();

      final Finder save = find.widgetWithText(
        MSButton,
        trans('uptizm.monitors.metrics_form_save_create'),
      );
      await tester.ensureVisible(save);
      await tester.tap(save);
      await tester.pump();
      await tester.pump();

      expect(
        submitted,
        hasLength(1),
        reason: 'the tapped candidate filled the path the client requires',
      );
      final MetricForm form = submitted.single;
      expect(form.source, equals('json'));
      expect(form.path, equals(r'$.status'));
      expect(form.type, equals('string'));
      // The tap writes those three and nothing else: not a threshold, not a
      // label the operator did not choose, not a key.
      expect(form.label, equals('Health status'));
      expect(form.key, equals('health_status'));
      expect(form.warn, isEmpty);
      expect(form.critical, isEmpty);
      expect(form.okValues, isEmpty);
    });
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

    /// Pumps the detail sheet.
    ///
    /// [readings] defaults to [series] because most cases want the two to
    /// agree, and separates them when a case is about the difference: the
    /// series is windowed, the readings table is not.
    Future<void> pumpDetail(
      WidgetTester tester,
      List<MetricSeriesPoint> series, {
      List<MetricSeriesPoint>? readings,
    }) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      _stubReadings(readings ?? series);

      await tester.pumpWidget(
        wrap(
          MonitorMetricDetail(
            metric: memoryUsageForm(),
            onLoadSeries: () async => series,
            onCreateReadings: _readingsPaginator,
            onEdit: () {},
            onDelete: () {},
          ),
        ),
      );
      // One pump resolves the load future, a second paints the result, and the
      // third lets the readings page land.
      await tester.pump();
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

    testWidgets('an empty window still lists the history below it', (
      tester,
    ) async {
      // An empty SERIES is not an empty history. The series is windowed (24h),
      // the readings table pages the whole history, so a metric last recorded
      // two days ago has nothing to chart and plenty to list. This used to
      // return the "no readings recorded yet" line ALONE and drop the table
      // that would have disproved it.
      // Numeric readings, because `pumpDetail` mounts the numeric form: a
      // string value on a numeric metric renders the no-value placeholder,
      // which would make this case pass or fail for the wrong reason.
      await pumpDetail(
        tester,
        const [],
        readings: [
          MetricSeriesPoint(
            recordedAt: DateTime.utc(2026, 8, 26, 9),
            numericValue: 61.2,
            statusValue: null,
            stringValue: null,
            band: 'ok',
          ),
        ],
      );

      expect(
        find.text(trans('uptizm.monitors.metrics_detail_no_readings_in_window')),
        findsOneWidget,
      );
      // The table is there, carrying a row the empty series never had.
      final Iterable<WText> texts = tester.widgetList<WText>(
        find.byType(WText),
      );
      expect(texts.any((WText w) => w.data.contains('61.2')), isTrue);
    });

    testWidgets(
      'shows a string metric\'s newest reading as the hero value',
      (tester) async {
        // The regression this pins: the hero value was gated on
        // `numericValue`, so a string metric's real latest reading rendered
        // NOTHING at all, not even the wrong thing.
        await tester.binding.setSurfaceSize(const Size(1280, 2400));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        _stubReadings(stringReadings());
        await tester.pumpWidget(
          wrap(
            MonitorMetricDetail(
              metric: stringMetricForm(),
              onLoadSeries: () async => stringReadings(),
              onCreateReadings: _readingsPaginator,
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

        _stubReadings(stringReadings());
        await tester.pumpWidget(
          wrap(
            MonitorMetricDetail(
              metric: stringMetricForm(),
              onLoadSeries: () async => stringReadings(),
              onCreateReadings: _readingsPaginator,
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

        _stubReadings(stringReadings());
        await tester.pumpWidget(
          wrap(
            MonitorMetricDetail(
              metric: stringMetricForm(),
              onLoadSeries: () async => stringReadings(),
              onCreateReadings: _readingsPaginator,
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
      DateTime? latestRecordedAt,
    }) {
      return MonitorMetricRecord(
        id: 'm1',
        latestStatus: latestStatus,
        latestString: latestString,
        latestBand: latestBand,
        latestRecordedAt: latestRecordedAt,
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

    testWidgets("a string metric's frozen critical band colors its dot", (
      tester,
    ) async {
      // The dot used to be gated on the metric being numeric, from back when a
      // numeric bound was the only thing that could produce a band. A string
      // metric bands by value-list membership now, so that gate rendered a
      // critical reading as unremarkable plain text: the one state this whole
      // feature exists to make visible was the one it did not color.
      await pumpWith(
        tester,
        [record(type: 'string', latestString: 'exploded', latestBand: 'critical')],
      );

      // Scoped to the custom row: the "Response time" system row above carries
      // its own dot, so an unscoped byType finder measures that one instead.
      final StatusDot dot = tester.widget<StatusDot>(
        find.descendant(
          of: find.ancestor(
            of: find.text('exploded'),
            matching: find.byType(WAnchor),
          ),
          matching: find.byType(StatusDot),
        ),
      );

      expect(dot.status, equals(StatusKey.down));
      expect(find.text('exploded'), findsOneWidget);
    });

    testWidgets('a string metric with no frozen band gets no dot', (
      tester,
    ) async {
      // The other half of the same rule: absent is not `ok`. A metric with no
      // configured lists records no band, and inventing a green dot for it
      // would read as "checked and healthy".
      await pumpWith(
        tester,
        [record(type: 'string', latestString: 'eu-central')],
      );

      expect(
        find.descendant(
          of: find.ancestor(
            of: find.text('eu-central'),
            matching: find.byType(WAnchor),
          ),
          matching: find.byType(StatusDot),
        ),
        findsNothing,
      );
    });

    testWidgets('a reading that stopped arriving loses its band and says so', (
      tester,
    ) async {
      // The quiet failure this closes: rename the key a rule extracts in a
      // monitored deploy and no new value is recorded, so the row kept showing
      // the last good value with its last good GREEN band indefinitely. Nothing
      // on screen was a wrong value, which is what made it invisible.
      //
      // The seeded monitor checks every 30s, so an hour-old reading is far past
      // the two-interval window.
      await pumpWith(tester, [
        record(
          type: 'string',
          latestString: 'ok',
          latestBand: 'ok',
          latestRecordedAt: DateTime.now().subtract(const Duration(hours: 1)),
        ),
      ]);

      expect(
        find.descendant(
          of: find.ancestor(
            of: find.text('ok'),
            matching: find.byType(WAnchor),
          ),
          matching: find.byType(StatusDot),
        ),
        findsNothing,
        reason: 'a band frozen an hour ago is not the metric current verdict',
      );
      expect(
        find.text(trans('uptizm.monitors.metrics_reading_stale')),
        findsOneWidget,
      );
      expect(
        find.text('ok'),
        findsOneWidget,
        reason: 'the last known reading stays visible; it is still information',
      );
    });

    testWidgets('a fresh reading keeps its band and carries no stale label', (
      tester,
    ) async {
      await pumpWith(tester, [
        record(
          type: 'string',
          latestString: 'degraded',
          latestBand: 'warn',
          latestRecordedAt: DateTime.now().subtract(const Duration(seconds: 5)),
        ),
      ]);

      final StatusDot dot = tester.widget<StatusDot>(
        find.descendant(
          of: find.ancestor(
            of: find.text('degraded'),
            matching: find.byType(WAnchor),
          ),
          matching: find.byType(StatusDot),
        ),
      );

      expect(dot.status, equals(StatusKey.degraded));
      expect(
        find.text(trans('uptizm.monitors.metrics_reading_stale')),
        findsNothing,
      );
    });
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

  // ---------------------------------------------------------------------------
  // Discovery panel: what the backend thinks is worth adding.
  // ---------------------------------------------------------------------------
  //
  // The plan gate itself is exercised in entitlement_controller_test.dart, per
  // the convention this suite already follows: under the bare harness the
  // billing fetch fails and the controller degrades to permissive limits, so
  // these tests see the UNGATED panel. That is the half worth pinning here
  // anyway, since the nudge branch is the shared MSUpgradeNudge every other
  // gated surface renders.
  group('MonitorMetricsTab discovery', () {
    testWidgets('offers the ask before anything has been requested', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      expect(find.text('Suggested'), findsOneWidget);
      expect(find.text('Suggest'), findsOneWidget);
      // Not asked yet is not the same as answered with nothing, and the panel
      // must not claim the second before the first has happened.
      expect(find.text('Nothing to suggest.'), findsNothing);
    });

    testWidgets('renders a suggestion and marks the rule-authored one', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Http.fake((request) {
        if (request.url.contains("metrics/discover")) {
          return Http.response({
            'data': {
              'suggested_metrics': [
                {
                  'key': 'queue_depth',
                  'label': 'Queue depth',
                  'type': 'numeric',
                  'source': 'json_path',
                  'path': 'queue.pending',
                  'unit': null,
                  'threshold_direction': 'high_bad',
                  'warn': 1,
                  'critical': 5,
                  'ok_values': <String>[],
                  'warn_values': <String>[],
                  'critical_values': <String>[],
                  'sample_value': '0',
                  'origin': 'model',
                },
                {
                  'key': 'service_status',
                  'label': 'Service status',
                  'type': 'string',
                  'source': 'json_path',
                  'path': 'status',
                  'unit': null,
                  'threshold_direction': null,
                  'warn': null,
                  'critical': null,
                  'ok_values': ['ok'],
                  'warn_values': ['degraded'],
                  'critical_values': <String>[],
                  'sample_value': 'degraded',
                  'origin': 'rule',
                },
              ],
            },
          }, 200);
        }
        return Http.response({'data': []}, 200);
      });

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      await tester.tap(find.text('Suggest'));
      await tester.pump();
      await tester.pump();

      expect(find.text('Queue depth'), findsOneWidget);
      expect(find.text('Service status'), findsOneWidget);
      // Exactly one marker: the rule row carries it and the model row does not,
      // which is the whole point of shipping `origin` on the wire.
      expect(find.text('rule'), findsOneWidget);
    });

    testWidgets('says so when the round trip fails, never "nothing to suggest"', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Http.fake((request) {
        if (request.url.contains("metrics/discover")) {
          return Http.response({'message': 'boom'}, 500);
        }
        return Http.response({'data': []}, 200);
      });

      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'api')));
      await tester.pump();

      await tester.tap(find.text('Suggest'));
      await tester.pump();
      await tester.pump();

      // A broken round trip and an empty answer are different facts. Reporting
      // the second for the first tells the operator their endpoint has nothing
      // worth measuring when what actually happened is that we could not ask.
      expect(find.text('Could not reach it.'), findsOneWidget);
      expect(find.text('Nothing to suggest.'), findsNothing);
    });
  });
}
