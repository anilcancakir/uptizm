import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import 'package:uptizm/app/mocks/metrics.dart';
import 'package:uptizm/resources/views/monitor_metric_detail.dart';
import 'package:uptizm/resources/views/monitor_metric_form.dart';
import 'package:uptizm/resources/views/monitor_metrics_support.dart';
import 'package:uptizm/resources/views/monitor_metrics_tab.dart';
import 'package:uptizm/ui/components/metric_chart/index.dart';

/// Language loader for all trans() keys exercised by the metrics widgets.
///
/// Short, wrappable strings avoid RenderFlex overflow at the narrow test
/// viewport, mirroring the pattern in monitor_detail_view_test.dart.
class _MetricsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // MonitorMetricsTab — system section.
      'uptizm.monitors.metrics_system_title': 'System metrics',
      'uptizm.monitors.metrics_system_collected_by_default': 'collected',

      // MonitorMetricsTab — custom section.
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
      'uptizm.monitors.metrics_form_key_hint': 'Lowercase letters, digits, underscores.',
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
      'uptizm.monitors.metrics_confirm_delete_description': 'This cannot be undone.',
      'uptizm.monitors.metrics_confirm_delete_label': 'Delete',

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

    // Load short strings so trans() returns human labels rather than raw keys.
    Translator.instance.setLoader(_MetricsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
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
          child: Scaffold(
            body: SingleChildScrollView(child: widget),
          ),
        ),
      ),
    );
  }

  /// Wraps [widget] with the WindTheme mounted ABOVE the MaterialApp Navigator
  /// (via the app `builder`), mirroring how MagicApplication wraps
  /// MaterialApp.router at the app root. Modal routes (bottom sheets) mount on
  /// the root Overlay, so they only inherit a WindTheme placed above the
  /// Navigator — a WindTheme inside `home` would not reach them.
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
  /// Overlay's offstage context — a constraint the test Overlay does not
  /// provide, producing "BoxConstraints forces an infinite width". By staying
  /// below `sm`, the footer renders as a flex-col and the buttons get `w-full`
  /// inside a Column (unbounded height only), which is safe.
  Widget wrapForm(Widget widget, {Size size = const Size(600, 3000)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(
            body: SingleChildScrollView(child: widget),
          ),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // MonitorMetricsTab — 'api' monitor (has custom metrics).
  // ---------------------------------------------------------------------------

  group('MonitorMetricsTab — monitorId: api', () {
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
      // mounts on the root Overlay) inherits it — exactly as MagicApplication
      // wraps MaterialApp.router at the app root in production.
      await tester.pumpWidget(wrapRootTheme(const MonitorMetricsTab(monitorId: 'api')));
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
        find.text(trans('uptizm.monitors.metrics_recent_readings').toUpperCase()),
        findsOneWidget,
        reason: 'The opened detail sheet must render the Recent readings section',
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
      final hoverRows = find.byWidgetPredicate((w) =>
          w is WDiv &&
          (w.className?.contains('hover:bg-surface-container') ?? false) &&
          (w.className?.contains('border-color-border') ?? false));
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
        reason: 'Add metric button must appear when custom metrics list is non-empty',
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
        reason: 'Empty state must not appear when the monitor has custom metrics',
      );
    });
  });

  // ---------------------------------------------------------------------------
  // MonitorMetricsTab — 'docs' monitor (no custom metrics).
  // ---------------------------------------------------------------------------

  group('MonitorMetricsTab — monitorId: docs (no custom metrics)', () {
    testWidgets('renders the empty-state title', (tester) async {
      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'docs')));
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_empty_title')),
        findsOneWidget,
        reason: 'Empty state title must appear for a monitor with no custom metrics',
      );
    });

    testWidgets('does NOT render the Add metric button', (tester) async {
      await tester.pumpWidget(wrap(const MonitorMetricsTab(monitorId: 'docs')));
      await tester.pump();

      expect(
        find.text(trans('uptizm.monitors.metrics_add')),
        findsNothing,
        reason: 'Add metric button must be absent when the custom metrics list is empty',
      );
    });
  });

  // ---------------------------------------------------------------------------
  // MonitorMetricForm — pumped directly (no BottomSheet overlay).
  // ---------------------------------------------------------------------------

  group('MonitorMetricForm — create mode', () {
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
        Input,
        trans('uptizm.monitors.metrics_form_name_placeholder'),
      );
      await tester.tap(nameField);
      await tester.pumpAndSettle();
      await tester.enterText(nameField, 'Memory Usage');
      await tester.pump();

      // The Key TextField should now contain the slugified value.
      final Finder keyField = find.widgetWithText(
        Input,
        trans('uptizm.monitors.metrics_form_key_placeholder'),
      );
      final Input keyInput = tester.widget<Input>(keyField);
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
        Button,
        trans('uptizm.monitors.metrics_form_save_create'),
      );
      expect(saveButton, findsOneWidget);
      final Button btn = tester.widget<Button>(saveButton);
      expect(
        btn.disabled,
        isTrue,
        reason: 'Save must be disabled when label and key are empty',
      );
    });

    testWidgets('Save button is enabled when label and key are both non-empty', (
      tester,
    ) async {
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
        Button,
        trans('uptizm.monitors.metrics_form_save_create'),
      );
      expect(saveButton, findsOneWidget);
      final Button btn = tester.widget<Button>(saveButton);
      expect(
        btn.disabled,
        isFalse,
        reason: 'Save must be enabled when label and key are both non-empty',
      );
    });

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
  // MonitorMetricDetail — pumped directly.
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
        wrap(
          MonitorMetricDetail(
            metric: form,
            onEdit: () {},
            onDelete: () {},
          ),
        ),
      );
      await tester.pump();

      // The detail computes latest from the augmented series; we just confirm
      // a fmt'd WText with the unit suffix is present.
      final Iterable<WText> texts = tester.widgetList<WText>(find.byType(WText));
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
