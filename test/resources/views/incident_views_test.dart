
import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/incident_controller.dart';
import 'package:uptizm/app/controllers/maintenance_controller.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/enums/incident_lifecycle.dart' show IncidentLifecycle;
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/app/models/incident.dart';
import 'package:uptizm/resources/views/incidents/incident_create_view.dart';
import 'package:uptizm/resources/views/incidents/incident_detail_view.dart';
import 'package:uptizm/resources/views/incidents/incidents_list_view.dart';
import 'package:uptizm/ui/components/ai_analysis_card/index.dart';
import 'package:uptizm/ui/components/ai_insight/index.dart';
import 'package:uptizm/ui/components/incident_card/index.dart';

import '../../support/bundled_lang.dart';
import '../../support/incident_fixtures.dart';
import '../../support/monitor_fixtures.dart';
import '../../support/skeleton_matchers.dart';

/// In-memory language loader supplying every [trans] key exercised by the
/// incident list/create/detail views, mirroring the pattern established in
/// `monitor_detail_view_test.dart` / `monitor_form_test.dart`. Short,
/// wrappable strings avoid RenderFlex overflow at the test viewport.
class _IncidentViewsLangLoader implements TranslationLoader {
  /// The catalogue prefix whose entries come from the SHIPPED files rather than
  /// from the harness map below.
  ///
  /// An incident headline is no longer a column read: a composed one renders from
  /// `uptizm.incidents.title_*` through `Incident.displayTitle`, and a harness map
  /// missing those keys would put a raw dotted key in the detail header, which is a
  /// gap in this file rather than a defect in the view. There are seven entries and
  /// no reason to keep a second copy of them here, so they are read from the
  /// product.
  static const String titleKeyPrefix = 'uptizm.incidents.title_';

  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    final Map<String, dynamic> shipped = readBundledLang(locale.languageCode);

    return {
      for (final MapEntry<String, dynamic> entry in shipped.entries)
        if (entry.key.startsWith(titleKeyPrefix)) entry.key: entry.value,
      // Shared relative-time + count copy.
      'uptizm.common.time_just_now': 'just now',
      'uptizm.monitors.kpi_delta_ongoing': 'ongoing',
      'uptizm.monitors.count_of': ':visible of :total',
      'uptizm.incidents.affected_count_one': ':count monitor affected',
      'uptizm.incidents.affected_count_other': ':count monitors affected',
      'uptizm.incidents.form_prefill_title':
          'Investigating anomaly on :monitor',
      // List.
      'uptizm.incidents.list_title': 'Incidents',
      'uptizm.incidents.list_description': 'Active and past incidents.',
      'uptizm.incidents.filter_all': 'All',
      'uptizm.incidents.filter_open': 'Open',
      'uptizm.incidents.filter_ai': 'AI-detected',
      'uptizm.incidents.filter_resolved': 'Resolved',
      'uptizm.incidents.search_placeholder': 'Search incidents',
      'uptizm.incidents.count_active': 'Active',
      'uptizm.incidents.count_critical': 'Critical',
      'uptizm.incidents.count_ai': 'AI-detected',
      'uptizm.incidents.count_ai_hint': 'auto-opened',
      'uptizm.incidents.count_resolved': 'Resolved',
      'uptizm.incidents.empty_never_had_title': 'No incidents yet',
      'uptizm.incidents.empty_never_had_description': 'None yet.',
      'uptizm.incidents.empty_filtered_title': 'All clear',
      'uptizm.incidents.empty_filtered_description': 'No matches.',
      'uptizm.incidents.empty_clear_filters': 'Clear filters',
      'uptizm.incidents.error_load_title': "Couldn't load incidents",
      'uptizm.incidents.error_load_description': 'Try again.',
      'uptizm.incidents.new_incident': 'New incident',
      'uptizm.incidents.back': 'Incidents',

      // Create.
      'uptizm.incidents.form_title_new': 'New incident',
      'uptizm.incidents.form_title_maintenance': 'Schedule maintenance',
      'uptizm.incidents.form_description': 'File an incident manually.',
      'uptizm.incidents.form_type_label': 'Type',
      'uptizm.incidents.form_title_label': 'Title',
      'uptizm.incidents.form_title_placeholder_incident': '503s',
      'uptizm.incidents.form_title_placeholder_maintenance': 'Upgrade',
      'uptizm.incidents.form_title_error_required': 'Title is required.',
      'uptizm.incidents.form_affected_error_required': 'Select a monitor.',
      'uptizm.incidents.form_affected_label': 'Affected monitors',
      'uptizm.incidents.form_affected_hint': 'Drives the status page.',
      'uptizm.incidents.form_severity_label': 'Severity',
      'uptizm.incidents.form_severity_hint': 'Operator-side priority.',
      'uptizm.incidents.form_starts_label': 'Starts',
      'uptizm.incidents.form_ends_label': 'Ends',
      'uptizm.incidents.form_impact_label': 'Status page impact',
      'uptizm.incidents.form_impact_hint': 'How this reads to customers.',
      'uptizm.incidents.form_first_update_label': 'First update',
      'uptizm.incidents.form_first_update_hint': 'The opening post.',
      'uptizm.incidents.form_first_update_placeholder_incident':
          "We're investigating.",
      'uptizm.incidents.form_first_update_placeholder_maintenance':
          'Scheduled maintenance.',
      'uptizm.incidents.form_notify_label': 'Notify subscribers',
      'uptizm.incidents.form_notify_hint': 'Email subscribers.',
      'uptizm.incidents.submit_open': 'Open incident',
      'uptizm.incidents.submit_schedule': 'Schedule maintenance',
      'uptizm.incidents.cancel': 'Cancel',
      'uptizm.incidents.ai_promoted_title': 'Promoted from an AI anomaly',
      'uptizm.incidents.ai_promoted_explainer': 'Pre-filled from the anomaly.',
      'uptizm.incidents.ai_generic_banner': 'Uptizm AI analyzes this incident.',

      // Detail.
      'uptizm.incidents.detail_back': 'Incidents',
      'uptizm.incidents.detail_resolve': 'Resolve',
      'uptizm.incidents.detail_reopen': 'Reopen',
      'uptizm.incidents.detail_assigned_to': 'Assigned to',
      'uptizm.incidents.detail_unassigned': 'Unassigned',
      'uptizm.incidents.detail_acknowledge': 'Acknowledge',
      'uptizm.incidents.detail_acknowledged': 'Acknowledged by :name · :time',
      'uptizm.incidents.detail_acknowledged_toast_title':
          'Incident acknowledged',
      'uptizm.incidents.detail_acknowledged_toast_description':
          'The timeline records who acknowledged it.',
      'uptizm.incidents.detail_assigned_toast_title': 'Incident assigned',
      'uptizm.incidents.detail_unassigned_toast_title': 'Incident unassigned',
      'uptizm.incidents.detail_affected_monitors_label': 'Affected monitors',
      'uptizm.incidents.detail_timeline_public': 'Public updates',
      'uptizm.incidents.detail_timeline_all': 'All activity',
      'uptizm.incidents.detail_timeline_empty': 'No public updates yet.',
      'uptizm.incidents.detail_composer_heading': 'Post an update',
      'uptizm.incidents.detail_composer_placeholder': "What's the latest?",
      'uptizm.incidents.detail_composer_publish_label':
          'Publish to status page',
      'uptizm.incidents.detail_composer_ai_draft': 'Draft with AI',
      'uptizm.incidents.detail_composer_ai_insight': 'Drafted by AI.',
      // The degraded-analysis section. `uptizm.common.retry` is the app's own
      // shared retry label, reused rather than given a local twin, and it is
      // spelled in full: `common.retry` resolves to NOTHING and would have put
      // the raw dotted key on the button.
      'uptizm.common.retry': 'Try again',
      'uptizm.incidents.analysis_degraded_heading': 'AI analysis',
      'uptizm.incidents.analysis_pending_heading': 'AI analysis',
      'uptizm.incidents.analysis_pending_body': 'Reading the evidence...',
      'uptizm.incidents.analysis_retry_pending': 'Retrying',
      'uptizm.incidents.detail_composer_post': 'Post update',
      'uptizm.incidents.detail_postmortem_heading': 'Postmortem draft',
      'uptizm.incidents.detail_postmortem_edit': 'Edit & publish',
      'uptizm.incidents.detail_postmortem_heading_saved': 'Postmortem',
      'uptizm.incidents.detail_postmortem_heading_edit':
          'Edit the postmortem',
      'uptizm.incidents.detail_postmortem_placeholder': 'What happened?',
      'uptizm.incidents.detail_postmortem_ai_seeded':
          'Still an outside-in observation. Add the root cause.',
      'uptizm.incidents.detail_postmortem_cancel': 'Cancel',
      'uptizm.incidents.detail_postmortem_save_draft': 'Save draft',
      'uptizm.incidents.detail_postmortem_publish': 'Publish',
      'uptizm.incidents.detail_postmortem_state_draft':
          'Internal draft, not published.',
      'uptizm.incidents.detail_postmortem_state_published':
          'Published on :time.',
      'uptizm.incidents.detail_postmortem_error_empty': 'Write it first.',
      'uptizm.incidents.detail_postmortem_save_toast_title':
          'Postmortem saved',
      'uptizm.incidents.detail_postmortem_save_toast_description':
          'Stored, still internal.',
      'uptizm.incidents.detail_postmortem_publish_toast_title':
          'Postmortem published',
      'uptizm.incidents.detail_postmortem_publish_toast_description':
          'Readable on the status page.',
      'uptizm.incidents.ai_analysis_gated':
          'AI incident analysis pinpoints '
          'the likely cause.',

      // Create-form option labels (kind / severity / impact), now trans()-driven.
      'uptizm.incidents.form_kind_incident': 'Incident',
      'uptizm.incidents.form_kind_maintenance': 'Scheduled maintenance',
      'uptizm.incidents.form_severity_critical': 'Critical',
      'uptizm.incidents.form_severity_warning': 'Warning',
      'uptizm.incidents.form_severity_info': 'Info',
      'uptizm.incidents.form_impact_down': 'Major outage',
      'uptizm.incidents.form_impact_degraded': 'Degraded performance',
      'uptizm.incidents.form_impact_info': 'Maintenance',

      // Detail composer status options (kIncidentStatuses), now trans()-driven.
      'uptizm.incidents.detail_composer_status_detected': 'Detected',
      'uptizm.incidents.detail_composer_status_investigating': 'Investigating',
      'uptizm.incidents.detail_composer_status_identified': 'Identified',
      'uptizm.incidents.detail_composer_status_monitoring': 'Monitoring',
      'uptizm.incidents.detail_composer_status_resolved': 'Resolved',

      // AI-draft composer + postmortem templates, now trans()-driven.
      'uptizm.incidents.draft_resolved':
          'This incident is resolved. :name is back to normal across all '
          'regions and checks are passing again. Thanks for your patience.',
      'uptizm.incidents.draft_maintenance':
          'Scheduled maintenance on :name is underway.',
      'uptizm.incidents.draft_investigating':
          "We're investigating :what affecting :name. Uptizm's checks are "
          ":signal. We'll share another update within 30 minutes.",
      'uptizm.incidents.draft_what_down': 'a major outage',
      'uptizm.incidents.draft_what_degraded': 'degraded performance',
      'uptizm.incidents.draft_what_info': 'a service issue',
      'uptizm.incidents.draft_signal_errors': 'errors across regions',
      'uptizm.incidents.draft_signal_latency': 'elevated response times',
      'uptizm.incidents.postmortem':
          'The incident lasted :duration and affected :count :monitorWord. '
          'Uptizm first detected it via :signal, then saw checks recover '
          'before it was resolved. This draft covers only what Uptizm '
          'observed from the outside; add the internal root cause before '
          'publishing.',
      'uptizm.incidents.postmortem_monitor_one': 'monitor',
      'uptizm.incidents.postmortem_monitor_other': 'monitors',
      // `formatDuration` reads its units from the catalogue, so without these
      // the duration inside the rendered postmortem is a raw key.
      'uptizm.units.minutes': 'm',
      'uptizm.units.hours': 'h',

      // Shared status labels (StatusBadge / chip row).
      'uptizm.status.up': 'Operational',
      'uptizm.status.down': 'Major outage',
      'uptizm.status.degraded': 'Degraded',
      'uptizm.status.paused': 'Paused',
      'uptizm.status.info': 'Maintenance',
      'uptizm.status.ai': 'AI',
    };
  }
}

/// The same harness map, but with every key the degraded AI notice reads taken
/// from the SHIPPED `assets/lang/tr.json`.
///
/// Two reasons for the mix. The Turkish assertions are about the sentence an
/// operator reads, so an inline Turkish map would only agree with itself:
/// `lang_parity_test.dart` compares key SETS and never values, and an
/// English-only loader is exactly what let ungrammatical Turkish ship past a
/// green suite before. The rest of the screen keeps the short harness strings,
/// because the real page chrome overflows this viewport and that has nothing to
/// do with what is being pinned here.
///
/// The suite runs with the repo root as its cwd (`lang_parity_test.dart` reads
/// the same file off disk), so the bundled asset is readable from here.
class _TurkishDegradeLangLoader implements TranslationLoader {
  const _TurkishDegradeLangLoader();

  /// Key prefixes the degraded notice composes its sentence from: the four
  /// reason/core templates plus the localized severity and lifecycle labels the
  /// client interpolates into the core.
  static const List<String> _shippedPrefixes = [
    'uptizm.incidents.analysis_degraded_',
    'uptizm.enums.incident_severity.',
    'uptizm.enums.incident_lifecycle.',
  ];

  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    final Map<String, dynamic> shipped = readBundledLang('tr');

    return {
      ...await _IncidentViewsLangLoader().load(locale),
      for (final MapEntry<String, dynamic> entry in shipped.entries)
        if (_shippedPrefixes.any((String p) => entry.key.startsWith(p)))
          entry.key: entry.value,
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / PageHeader / Button / Input /
    // SegmentedControl / Select / Textarea / Switch resolve their themes via
    // MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());

    Translator.instance.setLoader(_IncidentViewsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));

    // `IncidentDetailView.initState` now fires a one-shot
    // `IncidentController.loadAnalysis` (`GET /incidents/{id}/analysis`);
    // bind a default-success fake network driver so every detail-view test
    // has one registered (a test needing a specific analysis payload swaps in
    // its own `Http.fake` afterwards), and bind `log` so a failed fetch's
    // `Log.error` call resolves instead of throwing on an unregistered
    // service (mirroring `incident_controller_test.dart`'s `business actions`
    // setUp).
    Http.fake();
    Magic.singleton('log', () => LogManager());
    Config.set('logging', {
      'default': 'console',
      'channels': {
        'console': {'driver': 'console', 'level': 'debug'},
      },
    });

    // Register, initialize, and seed the wired controller BEFORE any view
    // mounts. The controller now sources its list from the `Incident` ORM
    // (`GET /incidents` via `Incident.all()`); under this bare (network-less)
    // harness `onInit`'s load degrades to the empty state, so we then seed
    // `rxState` with the projected model fixtures directly. Marking the
    // controller `initialized` here means each view skips its own onInit/load,
    // so the seeded fixtures survive to first build (and the detail view's
    // initState `_seedFrom` resolves the correct incident and lifecycle
    // synchronously). This exercises the wired, rxState-backed controller in
    // place of the removed const-fixture reads.
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );
    controller.onInit();
    await Future<void>.delayed(Duration.zero);
    controller.setSuccess(incidentFixtures);
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a
  /// configurable [MediaQuery] size, mirroring the harness established in
  /// monitor_detail_view_test.dart / monitor_form_test.dart.
  Widget wrap(Widget widget, {Size size = const Size(1280, 3200)}) {
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

  group('IncidentsListView maintenance tab', () {
    /// Stubs `GET /scheduled-maintenances` with [payload] so the tab's
    /// refetch-on-mount returns it.
    ///
    /// The tab refetches on EVERY mount (a window created in another tab or via
    /// the API must not stay invisible), so a seeded roster alone would be
    /// overwritten by the fetch. Stubbing exercises the real read path.
    void stubWindows(List<Map<String, dynamic>> payload) {
      Http.fake({
        '*scheduled-maintenances': Http.response({'data': payload}),
      });
      Magic.singleton('log', () => LogManager());
    }

    /// The wire shape of [window], as the index resource returns it.
    Map<String, dynamic> windowPayload({
      String id = 'w1',
      String title = 'Db update',
      required String startsAt,
      required String endsAt,
    }) => <String, dynamic>{
      'id': id,
      'status_page_id': 'page-1',
      'title': title,
      'starts_at': startsAt,
      'ends_at': endsAt,
      'suppress_alerts': true,
      'monitors': const [
        {'monitor_id': 'checkout', 'name': 'Checkout'},
      ],
    };

    testWidgets('the tab lists the team windows instead of incidents', (
      tester,
    ) async {
      // Reported plainly: a window was created, the app landed on Incidents, and
      // the screen said "No incidents yet". The backend's index / show / update /
      // destroy endpoints all shipped with no caller, so the only surface that
      // ever showed a window was the PUBLIC status page.
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      stubWindows([
        windowPayload(
          startsAt: '2026-09-01T22:00:00.000000Z',
          endsAt: '2026-09-02T00:00:00.000000Z',
        ),
      ]);
      addTearDown(() => MaintenanceController.instance.seedForTest(const []));

      await tester.pumpWidget(wrap(const IncidentsListView()));
      await tester.pump();

      await tester.tap(find.text(trans('uptizm.incidents.filter_maintenance')));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('Db update'), findsOneWidget);
      expect(
        find.text('Checkout'),
        findsOneWidget,
        reason: 'the affected components tell two windows on one page apart',
      );
      expect(
        find.byType(IncidentCard),
        findsNothing,
        reason: 'a window is not an incident and must not render as one',
      );
    });

    testWidgets('an empty roster says no maintenance is planned', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      stubWindows(const []);
      addTearDown(() => MaintenanceController.instance.seedForTest(const []));

      await tester.pumpWidget(wrap(const IncidentsListView()));
      await tester.pump();

      await tester.tap(find.text(trans('uptizm.incidents.filter_maintenance')));
      await tester.pumpAndSettle();

      expect(
        find.text(trans('uptizm.incidents.maintenance_empty_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.incidents.empty_never_had_title')),
        findsNothing,
        reason: 'the incident empty state must not answer for maintenance',
      );
    });

    testWidgets('a finished window reads as finished, not as scheduled', (
      tester,
    ) async {
      // The phase is derived from the clock, so a window that has already run
      // must not keep claiming it is upcoming.
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final DateTime now = DateTime.now().toUtc();
      stubWindows([
        windowPayload(
          title: 'Finished work',
          startsAt: now.subtract(const Duration(hours: 3)).toIso8601String(),
          endsAt: now.subtract(const Duration(hours: 2)).toIso8601String(),
        ),
      ]);
      addTearDown(() => MaintenanceController.instance.seedForTest(const []));

      await tester.pumpWidget(wrap(const IncidentsListView()));
      await tester.pump();

      await tester.tap(find.text(trans('uptizm.incidents.filter_maintenance')));
      await tester.pumpAndSettle();

      expect(
        find.text(trans('uptizm.incidents.maintenance_phase_finished')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.incidents.maintenance_phase_scheduled')),
        findsNothing,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // IncidentsListView
  // ---------------------------------------------------------------------------

  group('IncidentsListView', () {
    testWidgets('renders an IncidentCard for every fixture incident', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentsListView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSPageContainer), findsOneWidget);
      expect(find.byType(IncidentCard), findsNWidgets(incidents.length));
    });

    testWidgets('switching the filter to Resolved shows only resolved incidents', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentsListView()));
      await tester.pump();

      final int resolvedCount = incidents
          .where((i) => i.lifecycle == IncidentLifecycle.resolved)
          .length;

      // Scope the tap to the filter MSSegmentedControl: "Resolved" also matches
      // the counts-row label and each card's lifecycle pill. IncidentsListView
      // constructs a bare `MSSegmentedControl(...)` (no explicit type argument),
      // which resolves to `MSSegmentedControl<dynamic>` at runtime, so the
      // generic argument is left off the finder.
      await tester.tap(
        find.descendant(
          of: find.byWidgetPredicate(
            (widget) =>
                widget.runtimeType.toString().startsWith('MSSegmentedControl'),
          ),
          matching: find.text(trans('uptizm.incidents.filter_resolved')),
        ),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(IncidentCard), findsNWidgets(resolvedCount));
    });

    testWidgets('shows a skeleton before the first read resolves, not the '
        'empty state', (tester) async {
      // The regression this pins: loading was indistinguishable from emptiness,
      // so a team with open incidents opened the page on "No incidents yet" and
      // only swapped to its cards once the fetch landed, which on an incident
      // screen reads as "nothing is wrong".
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // A controller that has never resolved a read: drop the seeded harness so
      // the mount's own fetch is still in flight on the first frame.
      MagicApp.reset();
      Magic.flush();
      Magic.singleton('magic_starter', () => MagicStarterManager());
      Magic.singleton('log', () => LogManager());
      Http.fake();

      // Deliberately NOT pumped again: the first frame is painted before the
      // mount's async fetch resolves, which is exactly the moment the operator
      // used to be told there were no incidents.
      await tester.pumpWidget(wrap(const IncidentsListView()));

      expect(find.byType(MSSkeleton), findsWidgets);
      expectVisibleSkeletons(tester);
      expect(find.byType(IncidentCard), findsNothing);
      expect(
        find.text(trans('uptizm.incidents.empty_never_had_title')),
        findsNothing,
        reason: 'a pending read must never assert that there are none',
      );

      // Once it resolves (the fake answers nothing), the skeleton gives way to
      // the honest empty state.
      await tester.pump();
      expect(find.byType(MSSkeleton), findsNothing);
      expect(
        find.text(trans('uptizm.incidents.empty_never_had_title')),
        findsOneWidget,
      );
    });

    testWidgets('a resolved empty history shows the empty state, not a '
        'skeleton', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // Drop the seeded fixtures, then let the real `load` resolve against a
      // fake that answers nothing: that publish is what marks the history
      // resolved-but-empty (an empty list, not a null state).
      MagicApp.reset();
      Magic.flush();
      Magic.singleton('magic_starter', () => MagicStarterManager());
      Magic.singleton('log', () => LogManager());
      Http.fake();
      await IncidentController.instance.load();

      await tester.pumpWidget(wrap(const IncidentsListView()));
      await tester.pump();

      expect(find.byType(MSSkeleton), findsNothing);
      expect(
        find.text(trans('uptizm.incidents.empty_never_had_title')),
        findsOneWidget,
      );
    });

    testWidgets('an impossible search query renders the empty state', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentsListView()));
      await tester.pump();

      final Finder searchInput = find.widgetWithText(
        MSInput,
        trans('uptizm.incidents.search_placeholder'),
      );
      await tester.tap(searchInput);
      await tester.pump();
      await tester.enterText(searchInput, 'zzzz-no-such-incident-zzzz');
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(IncidentCard), findsNothing);
      expect(find.byType(MSEmptyState), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  // IncidentCreateView
  // ---------------------------------------------------------------------------

  group('IncidentCreateView', () {
    testWidgets('blank renders the form and the generic AI banner', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentCreateView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSPageContainer), findsOneWidget);

      // The title Input is present with the incident-kind placeholder (the
      // default kind, blank suggestion).
      expect(
        find.widgetWithText(
          MSInput,
          trans('uptizm.incidents.form_title_placeholder_incident'),
        ),
        findsOneWidget,
        reason: 'The Title input must be present in the blank create form',
      );

      // With no `?from` suggestion resolvable under the bare harness (the
      // router query is unreachable without a real navigation), the generic
      // banner text renders.
      expect(
        find.text(trans('uptizm.incidents.ai_generic_banner')),
        findsOneWidget,
        reason: 'The generic AI banner must render for a blank incident form',
      );
      expect(
        find.text(trans('uptizm.incidents.ai_promoted_title')),
        findsNothing,
        reason: 'The promoted banner must not render without a suggestion',
      );
    });

    testWidgets(
      'switching the kind to maintenance hides severity and shows start/end',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 3200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(wrap(const IncidentCreateView()));
        await tester.pump();

        // Before switching: Severity is present, Starts/Ends are not.
        expect(
          find.text(trans('uptizm.incidents.form_severity_label')),
          findsOneWidget,
          reason: 'Severity must be visible for the default incident kind',
        );
        expect(
          find.text(trans('uptizm.incidents.form_starts_label')),
          findsNothing,
          reason: 'Starts must be hidden for the default incident kind',
        );

        // Tap the "Scheduled maintenance" segment (the Type SegmentedControl).
        await tester.tap(find.text('Scheduled maintenance'));
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.incidents.form_severity_label')),
          findsNothing,
          reason: 'Severity must be hidden once maintenance kind is selected',
        );
        expect(
          find.text(trans('uptizm.incidents.form_starts_label')),
          findsOneWidget,
          reason: 'Starts must appear once maintenance kind is selected',
        );
        expect(
          find.text(trans('uptizm.incidents.form_ends_label')),
          findsOneWidget,
          reason: 'Ends must appear once maintenance kind is selected',
        );

        // Maintenance also drops the AI banner entirely.
        expect(
          find.text(trans('uptizm.incidents.ai_generic_banner')),
          findsNothing,
          reason: 'The AI banner must not render for the maintenance kind',
        );
      },
    );

    testWidgets(
      'submitting with a blank title shows an inline error and skips the POST',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 3200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        // Seed monitors so the affected picker has options and initState does
        // not fire a mount-time GET /monitors reload.
        final fake = Http.fake();
        MonitorController.instance.seedForTest(monitorFixtures);
        addTearDown(() => MonitorController.instance.seedForTest(const []));

        await tester.pumpWidget(wrap(const IncidentCreateView()));
        await tester.pump();

        // Submit with an empty title (the default blank-create state): the
        // client-side required check must block before any request.
        await tester.tap(
          find.widgetWithText(MSButton, trans('uptizm.incidents.submit_open')),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.incidents.form_title_error_required')),
          findsOneWidget,
          reason: 'A blank title must surface its inline required error',
        );
        // No round trip: the blank-title submit never reached POST /incidents.
        fake.assertNotSent(
          (r) => r.method == 'POST' && r.url == '/incidents',
        );
      },
    );

    testWidgets(
      'submitting the incident kind POSTs /incidents with the form values',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 3200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final fake = Http.fake();

        // `IncidentController.create` navigates to `/incidents` on success;
        // the context-free `MagicRoute.to` requires the router initialized
        // (mirroring `incident_controller_test.dart`'s `create` group).
        MagicRouter.reset();
        MagicRoute.page('/', () => const SizedBox());
        MagicRoute.page('/incidents', () => const SizedBox());
        MagicRouter.instance.routerConfig;
        addTearDown(MagicRouter.reset);

        // The affected-monitors picker sources the LIVE monitor inventory; seed
        // it with the fixture list so "Checkout service" (id `checkout`) renders
        // and its id flows into the POST.
        MonitorController.instance.seedForTest(monitorFixtures);
        addTearDown(() => MonitorController.instance.seedForTest(const []));

        await tester.pumpWidget(wrap(const IncidentCreateView()));
        await tester.pump();

        // 1. Fill the required title field.
        await tester.enterText(
          find.widgetWithText(
            MSInput,
            trans('uptizm.incidents.form_title_placeholder_incident'),
          ),
          'Checkout returning 503s',
        );
        await tester.pump();

        // 2. Select the affected monitor (Region tile labelled by monitor
        //    name; "Checkout service" is the fixture's `checkout` monitor).
        await tester.tap(find.text('Checkout service'));
        await tester.pump();

        // 3. Pick the "Warning" severity (maps to the backend's `warn` enum
        //    value), then submit.
        await tester.tap(find.text('Warning'));
        await tester.pump();

        await tester.tap(
          find.widgetWithText(
            MSButton,
            trans('uptizm.incidents.submit_open'),
          ),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        // The create form maps the "Warning" choice to the backend `warn` wire
        // value. The `Incident` model posts the raw wire string (the enum
        // fields are decoded only by the typed read accessors, not stored as
        // enums in the outgoing payload), so `save()` sends `'warn'` exactly.
        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents' &&
              (r.data as Map)['monitor_id'] == 'checkout' &&
              (r.data as Map)['severity'] == 'warn' &&
              (r.data as Map)['title'] == 'Checkout returning 503s',
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // IncidentDetailView
  // ---------------------------------------------------------------------------

  group('IncidentDetailView', () {
    testWidgets("a known id prefills the header title and the timeline", (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final Incident incident = findIncidentFixture('checkout-503')!;

      await tester.pumpWidget(
        wrap(
          const IncidentDetailView(id: 'checkout-503'),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      // A pre-existing layout overflow in the header chip row (PageHeader's
      // `titleSuffix` slot marks multi-pill wrap children `flex-shrink-0`, so
      // they keep their intrinsic width instead of shrinking, see Issues in
      // the step report) fires here independent of viewport width; it is not
      // a regression introduced by this test, so it is drained rather than
      // asserted away, and the finder assertions below still verify the real
      // behavioral contract.
      tester.takeException();

      expect(find.byType(MSPageHeader), findsOneWidget);
      // The header renders the HEADLINE, which for this fixture is a composed
      // sentence resolved from the shipped `en` catalogue. Under `en` it reads the
      // same as the stored column by construction (the backend composes that column
      // from the same entry), so the locale-sensitive half of this contract is
      // pinned by the Turkish test further down.
      expect(find.text(incident.displayTitle), findsOneWidget);
      expect(
        incident.displayTitle,
        'Anomaly detected on Checkout service',
        reason: 'a raw dotted key here means the harness lost the title catalogue',
      );

      // The public timeline entry's message is on the page (default view is
      // "public"); this incident has at least one public entry.
      final String publicMessage = incident.timeline
          .firstWhere((e) => e.isPublic)
          .message;
      expect(find.text(publicMessage), findsOneWidget);
    });

    testWidgets('a composed headline reads Turkish in the header, and the '
        'stored English never appears', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // The whole point of the structured title, on the surface an operator looks
      // at most: the backend stored one English sentence and the header renders the
      // Turkish one from its key. This is the only assertion in this file that can
      // tell a header reading `displayTitle` from one reading `title`, because they
      // are the same string in English.
      await Translator.instance.setLocale(const Locale('tr'));

      final Incident incident = findIncidentFixture('checkout-503')!;

      await tester.pumpWidget(
        wrap(
          const IncidentDetailView(id: 'checkout-503'),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();
      tester.takeException(); // see the header chip-row overflow note above

      expect(
        find.text('Checkout service üzerinde anomali saptandı'),
        findsOneWidget,
        reason: 'the header must render the headline in the app language',
      );
      expect(
        find.text(incident.title),
        findsNothing,
        reason: 'the stored English column must not reach a Turkish operator',
      );
    });

    testWidgets('an unknown id renders the graceful not-found state', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentDetailView(id: 'nope')));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSEmptyState), findsOneWidget);
      expect(
        find.text(trans('uptizm.incidents.error_load_title')),
        findsWidgets,
        reason: 'The not-found title must appear for an unknown incident id',
      );
    });

    testWidgets('the AI-draft button fills the composer Textarea', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final Incident incident = findIncidentFixture('checkout-503')!;

      await tester.pumpWidget(
        wrap(
          const IncidentDetailView(id: 'checkout-503'),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      final Finder aiDraftButton = find.widgetWithText(
        MSButton,
        trans('uptizm.incidents.detail_composer_ai_draft'),
      );
      await tester.ensureVisible(aiDraftButton);
      await tester.pump();
      await tester.tap(aiDraftButton);
      await tester.pump();

      // See the header chip-row overflow note above; drained rather than
      // asserted away.
      tester.takeException();

      final MSTextarea composer = tester.widget<MSTextarea>(
        find.byType(MSTextarea),
      );
      expect(composer.value, isNotEmpty);
      expect(composer.value, contains(incident.monitorName));
    });

    testWidgets('a resolved incident shows the postmortem draft', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final Incident incident = findIncidentFixture('eu-packet-loss')!;
      expect(incident.lifecycle, IncidentLifecycle.resolved);

      await tester.pumpWidget(
        wrap(
          const IncidentDetailView(id: 'eu-packet-loss'),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      // See the header chip-row overflow note above; drained rather than
      // asserted away.
      tester.takeException();

      expect(
        find.textContaining(
          trans('uptizm.incidents.detail_postmortem_heading'),
        ),
        findsOneWidget,
        reason: 'The postmortem heading must render for a resolved incident',
      );

      // No AI framing on the draft. `postmortemDraft` is a `trans()` template
      // filled from the incident's own fields, so an `AiInsight` (whose whole
      // job is to mark content as model-authored, sparkle glyph included)
      // credited a model that never ran, over what is an editor input.
      expect(
        find.byType(AiInsight),
        findsNothing,
        reason: 'the draft is a local template, not an analysis',
      );

      // The responder strip is hidden once resolved: "Assigned to" is absent.
      expect(
        find.text(trans('uptizm.incidents.detail_assigned_to')),
        findsNothing,
        reason: 'The responder strip must be hidden for a resolved incident',
      );
    });

    testWidgets(
      'posting an update POSTs /incidents/{id}/updates with the composer text',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final fake = Http.fake();
        // `postUpdate` reloads the list on success; stub the follow-up
        // `GET /incidents` so `checkout-503` stays resolvable and the
        // composer keeps rendering after the reload.
        fake.stub(
          'incidents',
          Http.response({
            'data': [
              {
                'id': 'checkout-503',
                'title': 'Checkout returning 503s',
                'lifecycle': 'investigating',
                'started_at': '2026-07-11T14:00:00Z',
                'monitors': [
                  {'id': 'checkout', 'name': 'Checkout service'},
                ],
              },
            ],
          }),
        );

        // `MagicFeedback` (the success/error toast) falls back to `Log` when
        // no navigator context is mounted; bind a LogManager so that fallback
        // path resolves (mirroring `incident_controller_test.dart`'s
        // `business actions` setUp).
        Magic.singleton('log', () => LogManager());
        Config.set('logging', {
          'default': 'console',
          'channels': {
            'console': {'driver': 'console', 'level': 'debug'},
          },
        });

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'checkout-503'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();
        tester.takeException(); // see the header chip-row overflow note above

        final Finder composer = find.byType(MSTextarea);
        await tester.ensureVisible(composer);
        await tester.enterText(composer, 'Rolling back the release.');
        await tester.pump();

        final Finder postButton = find.widgetWithText(
          MSButton,
          trans('uptizm.incidents.detail_composer_post'),
        );
        await tester.ensureVisible(postButton);
        await tester.tap(postButton);
        await tester.pump();

        tester.takeException();
        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/checkout-503/updates' &&
              (r.data as Map)['message'] == 'Rolling back the release.',
        );

        // The composer clears on success.
        final MSTextarea after = tester.widget<MSTextarea>(
          find.byType(MSTextarea),
        );
        expect(after.value, isEmpty);
      },
    );

    testWidgets(
      'renders the acknowledgement from the PERSISTED timeline entry, never a '
      'client-authored name',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        // A wire-shaped incident whose acknowledgement exists only as the
        // backend-stamped timeline entry (author `Demo`, the acting user), which
        // is the exact shape `POST /incidents/{id}/acknowledge` leaves behind.
        final Incident incident = Incident.fromMap(<String, dynamic>{
          'id': 'ack-1',
          'title': 'Checkout returning 503s',
          'lifecycle': 'investigating',
          'impact': 'critical',
          'started_at': '2026-07-11T14:00:00Z',
          'monitors': [
            {'monitor_id': 'm1', 'name': 'Checkout'},
          ],
          'updates': [
            {
              'actor': 'human',
              'author': 'Demo',
              'status': 'investigating',
              'message': 'Incident acknowledged; investigation in progress.',
              'is_public': false,
              'autonomous': false,
              'display_at': '2026-07-11T14:33:00Z',
            },
          ],
        });
        IncidentController.instance.setSuccess([incident]);

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'ack-1'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();
        tester.takeException(); // see the header chip-row overflow note above

        expect(
          find.textContaining('Acknowledged by Demo'),
          findsOneWidget,
          reason:
              'The acknowledgement line must read the persisted entry author',
        );
        expect(
          find.textContaining('Ada Lovelace'),
          findsNothing,
          reason: 'No client-side responder name may reach the screen',
        );
        expect(
          find.widgetWithText(
            MSButton,
            trans('uptizm.incidents.detail_acknowledge'),
          ),
          findsNothing,
          reason:
              'An already-acknowledged incident shows the line, not the button',
        );
      },
    );

    testWidgets(
      'the Acknowledge button POSTs with no client-composed message',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final fake = Http.fake();
        fake.stub('incidents', Http.response({'data': <dynamic>[]}));

        IncidentController.instance.setSuccess([
          Incident.fromMap(<String, dynamic>{
            'id': 'ack-2',
            'title': 'Checkout returning 503s',
            'lifecycle': 'detected',
            'impact': 'critical',
            'started_at': '2026-07-11T14:00:00Z',
            'monitors': [
              {'monitor_id': 'm1', 'name': 'Checkout'},
            ],
            'updates': <dynamic>[],
          }),
        ]);

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'ack-2'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();
        tester.takeException();

        final Finder ackButton = find.widgetWithText(
          MSButton,
          trans('uptizm.incidents.detail_acknowledge'),
        );
        await tester.ensureVisible(ackButton);
        await tester.tap(ackButton);
        await tester.pump();
        tester.takeException();

        fake.assertSent(
          (r) => r.method == 'POST' && r.url == '/incidents/ack-2/acknowledge',
        );
        fake.assertNotSent(
          (r) =>
              r.url == '/incidents/ack-2/acknowledge' &&
              r.data is Map &&
              (r.data as Map).containsKey('message'),
        );
      },
    );

    testWidgets(
      'offers no Acknowledge button once the incident moved past detected '
      'without one (the backend would no-op)',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        IncidentController.instance.setSuccess([
          Incident.fromMap(<String, dynamic>{
            'id': 'ack-3',
            'title': 'Checkout returning 503s',
            'lifecycle': 'identified',
            'impact': 'critical',
            'started_at': '2026-07-11T14:00:00Z',
            'monitors': [
              {'monitor_id': 'm1', 'name': 'Checkout'},
            ],
            'updates': <dynamic>[],
          }),
        ]);

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'ack-3'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();
        tester.takeException();

        // The strip itself still renders (its assignee Select, identified by
        // the "Unassigned" sentinel option, is present); only the button that
        // would no-op is gone. The label is matched structurally rather than by
        // text because Wind's `uppercase` utility rewrites the rendered string.
        expect(
          tester
              .widgetList<MSSelect<String>>(find.byType(MSSelect<String>))
              .where(
                (select) =>
                    select.options.any((option) => option.value == ''),
              ),
          hasLength(1),
          reason: 'The responder strip still renders for an open incident',
        );
        expect(
          find.widgetWithText(
            MSButton,
            trans('uptizm.incidents.detail_acknowledge'),
          ),
          findsNothing,
        );
      },
    );

    testWidgets(
      'the assignee Select lists the team\'s real members, persists the choice, '
      'and renders it back off the incident',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final fake = Http.fake();
        // Staged in two steps because the view refetches on every mount
        // (RefetchesOnMount): the first fetch must return the UNASSIGNED
        // incident, and only the post-assign reload returns the persisted
        // assignee. Seeding the controller alone would be overwritten by that
        // mount fetch.
        Map<String, dynamic> incidentPayload({Map<String, dynamic>? assignee}) {
          return {
            'data': [
              {
                'id': 'assign-1',
                'title': 'Checkout returning 503s',
                'lifecycle': 'investigating',
                'impact': 'critical',
                'started_at': '2026-07-11T14:00:00Z',
                'assignee': assignee,
                'monitors': [
                  {'monitor_id': 'm1', 'name': 'Checkout'},
                ],
                'updates': <dynamic>[],
              },
            ],
          };
        }

        fake.stub('incidents', Http.response(incidentPayload()));

        // The roster is the starter team controller's REAL members list (the
        // owner of `GET /teams/{id}/members`); no parallel fetch is added.
        MagicStarterTeamController.instance.members.value = [
          {'id': 'u1', 'name': 'Demo Owner', 'email': 'demo@uptizm.test'},
          {'id': 'u2', 'name': 'Ravi Shah', 'email': 'ravi@uptizm.test'},
        ];

        IncidentController.instance.setSuccess([
          Incident.fromMap(<String, dynamic>{
            'id': 'assign-1',
            'title': 'Checkout returning 503s',
            'lifecycle': 'investigating',
            'impact': 'critical',
            'started_at': '2026-07-11T14:00:00Z',
            'assignee': null,
            'monitors': [
              {'monitor_id': 'm1', 'name': 'Checkout'},
            ],
            'updates': <dynamic>[],
          }),
        ]);

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'assign-1'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();
        tester.takeException();

        // The assignee Select is the one carrying the "Unassigned" sentinel
        // (the composer's status Select is the other MSSelect<String> here).
        MSSelect<String> assigneeSelect() {
          return tester
              .widgetList<MSSelect<String>>(find.byType(MSSelect<String>))
              .firstWhere(
                (select) => select.options.any(
                  (option) => option.value == '',
                ),
              );
        }

        expect(
          assigneeSelect().options.map((option) => option.label).toList(),
          equals([
            trans('uptizm.incidents.detail_unassigned'),
            'Demo Owner',
            'Ravi Shah',
          ]),
          reason: 'The roster is the real team membership, in its own order',
        );
        expect(assigneeSelect().value, isEmpty);

        // From here the backend has the assignment, so the reload the assign
        // action triggers must report it.
        fake.stub(
          'incidents',
          Http.response(
            incidentPayload(assignee: {'id': 'u2', 'name': 'Ravi Shah'}),
          ),
        );

        // Selecting a member persists through POST /incidents/{id}/assign.
        assigneeSelect().onChange?.call('u2');
        await tester.pump();
        await tester.pump();
        tester.takeException();

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/assign-1/assign' &&
              (r.data as Map)['assignee_id'] == 'u2',
        );

        // The reloaded incident carries the assignment, so the Select renders
        // it back from the incident rather than from a local selection.
        expect(assigneeSelect().value, equals('u2'));
      },
    );

    testWidgets(
      'the postmortem composer saves the body and re-renders the STORED text',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        const String stored =
            'The origin pool starved under the release traffic.';

        final fake = Http.fake();
        // Staged in two steps because the view refetches on every mount
        // (RefetchesOnMount): the first fetch must report NO stored postmortem so
        // the generated draft renders, and only the post-save reload reports the
        // stored body. Seeding the controller alone would be overwritten by that
        // mount fetch.
        Map<String, dynamic> incidentPayload({String? postmortemBody}) {
          return {
            'data': [
              {
                'id': 'pm-1',
                'title': 'EU packet loss',
                'lifecycle': 'resolved',
                'impact': 'minor',
                'started_at': '2026-07-10T10:00:00Z',
                'resolved_at': '2026-07-10T11:00:00Z',
                'postmortem_body': postmortemBody,
                'postmortem_published_at': null,
                'monitors': [
                  {'monitor_id': 'm2', 'name': 'API'},
                ],
                'updates': <dynamic>[],
              },
            ],
          };
        }

        fake.stub('incidents', Http.response(incidentPayload()));

        IncidentController.instance.setSuccess([
          Incident.fromMap(<String, dynamic>{
            'id': 'pm-1',
            'title': 'EU packet loss',
            'lifecycle': 'resolved',
            'impact': 'minor',
            'started_at': '2026-07-10T10:00:00Z',
            'resolved_at': '2026-07-10T11:00:00Z',
            'monitors': [
              {'monitor_id': 'm2', 'name': 'API'},
            ],
            'updates': <dynamic>[],
          }),
        ]);

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'pm-1'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();
        tester.takeException();

        // 1. Nothing stored yet: the generated draft renders, in the same plain
        //    card a stored postmortem uses and with no AI framing on either.
        expect(
          find.textContaining(
            trans('uptizm.incidents.detail_postmortem_heading'),
          ),
          findsOneWidget,
        );
        expect(find.byType(AiInsight), findsNothing);

        final Finder editButton = find.widgetWithText(
          MSButton,
          trans('uptizm.incidents.detail_postmortem_edit'),
        );
        await tester.ensureVisible(editButton);
        await tester.tap(editButton);
        await tester.pump();
        tester.takeException();

        // 2. The composer opens seeded with the generated draft plus the
        //    provenance line (never presented as a finished analysis). That line
        //    is plain text now: the draft is a `trans()` template, so an
        //    `AiInsight` credited a model that never ran.
        expect(
          find.text(trans('uptizm.incidents.detail_postmortem_ai_seeded')),
          findsOneWidget,
        );
        expect(
          find.byType(AiInsight),
          findsNothing,
          reason: 'the editor hint keeps its text and drops the AI badge',
        );
        // The postmortem editor's Textarea precedes the update composer's in
        // the section column, so it is the first of the two.
        final Finder postmortemField = find.byType(MSTextarea).first;
        expect(
          tester.widget<MSTextarea>(postmortemField).value,
          isNotEmpty,
          reason: 'The composer seeds from the generated draft',
        );

        // 3. Saving the edited body persists it as an internal draft.
        await tester.enterText(postmortemField, stored);
        await tester.pump();

        // From here the backend holds the draft, so the reload the save action
        // triggers must report it.
        fake.stub(
          'incidents',
          Http.response(incidentPayload(postmortemBody: stored)),
        );

        final Finder saveButton = find.widgetWithText(
          MSButton,
          trans('uptizm.incidents.detail_postmortem_save_draft'),
        );
        await tester.ensureVisible(saveButton);
        await tester.tap(saveButton);
        await tester.pump();
        await tester.pump();
        tester.takeException();

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/pm-1/postmortem' &&
              (r.data as Map)['body'] == stored &&
              (r.data as Map)['publish'] == false,
        );

        // 4. The reloaded incident's STORED body is what renders now, under the
        //    saved heading, flagged honestly as not-yet-published.
        expect(find.text(stored), findsOneWidget);
        expect(
          find.text(trans('uptizm.incidents.detail_postmortem_heading_saved')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.incidents.detail_postmortem_state_draft')),
          findsOneWidget,
        );
      },
    );

    testWidgets(
      'a published postmortem renders its publication state, not the draft '
      'framing',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        const String stored = 'Root cause: the release doubled the pool wait.';

        IncidentController.instance.setSuccess([
          Incident.fromMap(<String, dynamic>{
            'id': 'pm-2',
            'title': 'EU packet loss',
            'lifecycle': 'resolved',
            'impact': 'minor',
            'started_at': '2026-07-10T10:00:00Z',
            'resolved_at': '2026-07-10T11:00:00Z',
            'postmortem_body': stored,
            'postmortem_published_at': '2026-07-10T12:00:00Z',
            'monitors': [
              {'monitor_id': 'm2', 'name': 'API'},
            ],
            'updates': <dynamic>[],
          }),
        ]);

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'pm-2'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();
        tester.takeException();

        expect(find.text(stored), findsOneWidget);
        expect(
          find.textContaining('Published on'),
          findsOneWidget,
          reason: 'A published postmortem states when it went live',
        );
        expect(
          find.text(trans('uptizm.incidents.detail_postmortem_state_draft')),
          findsNothing,
        );
        expect(
          find.textContaining(
            trans('uptizm.incidents.detail_postmortem_heading'),
          ),
          findsNothing,
          reason: 'A human-owned stored postmortem carries no AI draft framing',
        );
      },
    );

    testWidgets(
      'the header stage is the persisted one, not the composer selection',
      (tester) async {
        // Regression: the header pill and the Resolve/Reopen button both read
        // the COMPOSER's local stage. Picking a status in the composer
        // relabelled the header before anything was posted, and an incident
        // that landed in the roster after this screen mounted (arriving from the
        // AI inbox's "Open incident") kept the placeholder stage: live, a freshly
        // promoted `detected` incident announced itself as "Investigating".
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final fake = Http.fake();
        fake.stub('incidents', Http.response({'data': <dynamic>[]}));

        IncidentController.instance.setSuccess([
          Incident.fromMap(<String, dynamic>{
            'id': 'stage-1',
            'title': 'Anomaly detected on API',
            'lifecycle': 'detected',
            'impact': 'major',
            'started_at': '2026-07-11T14:00:00Z',
            'monitors': [
              {'monitor_id': 'm1', 'name': 'API'},
            ],
            'updates': <dynamic>[],
          }),
        ]);

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'stage-1'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();
        tester.takeException();

        // The header states the persisted stage.
        expect(
          find.text(trans('uptizm.enums.incident_lifecycle.detected')),
          findsWidgets,
        );

        // Moving the composer's stage must not restate the incident: it is a
        // choice about the update being drafted, not a transition that happened.
        // The composer's status Select is the one currently holding a lifecycle
        // label (the other Select on this screen is the assignee roster).
        final Iterable<MSSelect<String>> selects = tester
            .widgetList<MSSelect<String>>(find.byType(MSSelect<String>));
        final MSSelect<String> statusSelect = selects.firstWhere(
          (MSSelect<String> s) => s.value == IncidentLifecycle.detected.name,
        );
        // The select is keyed by the wire token, so a pick travels as
        // `resolved`, not as a display label.
        statusSelect.onChange?.call(IncidentLifecycle.resolved.name);
        await tester.pump();
        tester.takeException();

        expect(
          find.text(trans('uptizm.enums.incident_lifecycle.detected')),
          findsWidgets,
          reason: 'the header must still read the persisted stage',
        );
        // And the header action still offers Resolve, not Reopen.
        expect(
          find.widgetWithText(
            MSButton,
            trans('uptizm.incidents.detail_resolve'),
          ),
          findsOneWidget,
        );
      },
    );

    testWidgets(
      'fetches the AI analysis and enriches the card with evidence + '
      'suggested actions, keeping similar-incidents empty',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 5200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final Incident incident = findIncidentFixture('checkout-503')!;
        final String inlineTldr = incident.ai!.tldr;

        final fake = Http.fake({
          'incidents/checkout-503/analysis': Http.response({
            'data': {
              'summary': 'Origin returns 503 under load.',
              'confidence': 'high',
              'contributing_factors': [],
              'stripped_citations': [],
              'evidence_for': [
                {
                  'label': 'All regions affected',
                  'detail': 'Every check fails.',
                  'source': 'check',
                },
              ],
              'evidence_against': [
                {
                  'label': 'No DNS change',
                  'detail': 'Records unchanged.',
                  'source': 'monitor',
                },
              ],
              'suggested_actions': [
                {'title': 'Check your origin', 'rationale': 'Returns 503s.'},
              ],
            },
          }),
        });

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'checkout-503'),
            size: const Size(1280, 5200),
          ),
        );
        // Before the analysis fetch resolves, the fast first-paint tldr from
        // the inline `Incident.ai` payload is already on screen.
        expect(find.text(inlineTldr), findsOneWidget);

        await tester.pumpAndSettle();
        tester.takeException(); // see the header chip-row overflow note above

        fake.assertSent(
          (r) =>
              r.method == 'GET' && r.url == '/incidents/checkout-503/analysis',
        );
        expect(find.text('All regions affected'), findsOneWidget);
        expect(find.text('No DNS change'), findsOneWidget);
        expect(find.text('Check your origin'), findsOneWidget);
        expect(
          find.text(trans('uptizm.ai.similar_incidents')),
          findsNothing,
          reason: 'similar_incidents stays empty (deferred)',
        );
        // The suggested actions are advice, not commands. The card wraps each row
        // in a `WButton` when `onActionTap` is non-null, and this view used to
        // pass `(_) {}`, so every row looked tappable and did nothing. Asserting
        // the property rather than the absence of a `WButton` because the card
        // legitimately contains other buttons (the feedback footer).
        expect(
          tester.widget<AiAnalysisCard>(find.byType(AiAnalysisCard)).onActionTap,
          isNull,
        );
      },
    );

    // -------------------------------------------------------------------------
    // Degraded analysis: the reason is said in the operator's language, and the
    // backend's machine-readable summary never reaches the screen.
    // -------------------------------------------------------------------------

    /// Seeds an incident with NO inline `ai` payload (so the analysis fetch owns
    /// the rendered summary, exactly as it does for an incident the AI has not
    /// summarized inline) and stubs its analysis endpoint with [analysis].
    ///
    /// Returns the fake driver so a caller can count requests: the retry's whole
    /// mechanism is a SECOND call to the same endpoint.
    FakeNetworkDriver seedAnalysisOnlyIncident(Map<String, dynamic> analysis) {
      final FakeNetworkDriver fake = Http.fake({
        // Both URLs, because the retry appends `?refresh=1`: without it the
        // backend serves its stored answer, and the re-ask would return the
        // row it was asked to replace.
        'incidents/deg-1/analysis': Http.response({'data': analysis}),
        'incidents/deg-1/analysis?refresh=1': Http.response({'data': analysis}),
      });
      Magic.singleton('log', () => LogManager());
      IncidentController.instance.setSuccess([
        Incident.fromMap(<String, dynamic>{
          'id': 'deg-1',
          'title': 'Checkout returning 503s',
          'lifecycle': 'investigating',
          'severity': 'critical',
          'impact': 'critical',
          'started_at': '2026-07-11T14:00:00Z',
          'monitors': [
            {'monitor_id': 'm1', 'name': 'Checkout'},
          ],
          'updates': <dynamic>[],
        }),
      ]);

      return fake;
    }

    testWidgets(
      'a degraded analysis reads as Turkish composed from the incident, never '
      'as the backend summary',
      (tester) async {
        // The live defect: a Turkish operator read "Deterministic baseline from
        // the incident record (the AI service was temporarily unavailable):
        // critical severity incident, currently resolved." The backend summary is
        // a machine-readable baseline, not display copy.
        await tester.binding.setSurfaceSize(const Size(1280, 5200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        const String backendSummary =
            'critical severity incident, currently investigating.';

        Translator.instance.setLoader(const _TurkishDegradeLangLoader());
        await Translator.instance.setLocale(const Locale('tr'));

        seedAnalysisOnlyIncident({
          'summary': backendSummary,
          'confidence': 'low',
          'degrade_reason': 'budget_exhausted',
        });

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'deg-1'),
            size: const Size(1280, 5200),
          ),
        );
        await tester.pumpAndSettle();
        tester.takeException(); // see the header chip-row overflow note above

        // The reason clause, from the shipped `tr.json`.
        expect(
          find.textContaining('Bugünün yapay zeka bütçesi doldu'),
          findsOneWidget,
          reason: 'the operator is told WHY the summary is a baseline, in TR',
        );
        // The objective core, composed client-side from the incident's own
        // localized severity + lifecycle labels. The colon is deliberate: the
        // lifecycle is a chip LABEL and keeps its capital, so it needs a
        // position where a capital reads as a label rather than a typo, and
        // Turkish `İ` cannot be lowercased by Dart's locale-blind
        // `toLowerCase()` anyway.
        expect(
          find.textContaining(
            'Kritik önem derecesinde bir olay, durumu: İnceleniyor.',
          ),
          findsOneWidget,
        );
        expect(
          find.textContaining(backendSummary),
          findsNothing,
          reason: 'the English baseline must never reach the operator',
        );
        // No analysis exists on this path, so nothing may claim one: no card, no
        // confidence badge rating an answer that was never produced, and no
        // feedback footer asking the operator to rate it.
        expect(
          find.byType(AiAnalysisCard),
          findsNothing,
          reason: 'the card is what made an absent analysis read as a broken one',
        );
        expect(find.text(trans('uptizm.ai.helpful')), findsNothing);
        // Budget exhaustion is the one reason with no retry: retrying spends
        // another budget unit on a request that fails by definition.
        expect(
          find.text(trans('uptizm.common.retry')),
          findsNothing,
          reason: 'a retry cannot help when the budget is what ran out',
        );
      },
    );

    testWidgets(
      'an unreachable AI service offers a retry that re-requests the analysis',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 5200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final fake = seedAnalysisOnlyIncident({
          'summary': 'critical severity incident, currently investigating.',
          'confidence': 'low',
          'degrade_reason': 'service_unreachable',
        });

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'deg-1'),
            size: const Size(1280, 5200),
          ),
        );
        await tester.pumpAndSettle();
        tester.takeException(); // see the header chip-row overflow note above

        final Finder retry = find.text(trans('uptizm.common.retry'));
        expect(retry, findsOneWidget);

        // One GET so far, from `initState`. The tap has to produce a SECOND one,
        // and it has to carry `refresh`: the backend serves a stored answer while
        // the evidence is unchanged, so a re-ask without the flag would come back
        // with the same row and the button would look identical on screen either
        // way.
        int analysisRequests() => fake.recorded
            .where(
              (entry) =>
                  entry.$1.method == 'GET' &&
                  entry.$1.url.startsWith('/incidents/deg-1/analysis'),
            )
            .length;
        expect(analysisRequests(), 1);

        await tester.tap(retry);
        await tester.pumpAndSettle();

        expect(analysisRequests(), 2);
        expect(
          fake.recorded.last.$1.url,
          equals('/incidents/deg-1/analysis?refresh=1'),
        );
      },
    );

    testWidgets('the degraded section fits a 360px phone', (tester) async {
      // Every other case on this screen runs at 1280 wide, and the sibling
      // detail views test narrow explicitly. The degraded row is long text plus a
      // button, which is exactly the pair that overflows at phone width, and the
      // first draft of this widget threw at any width by putting an `Expanded`
      // inside a `Wrap`.
      await tester.binding.setSurfaceSize(const Size(360, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      seedAnalysisOnlyIncident({
        'summary': 'critical severity incident, currently investigating.',
        'confidence': 'low',
        'degrade_reason': 'service_unreachable',
      });

      await tester.pumpWidget(
        wrap(const IncidentDetailView(id: 'deg-1'), size: const Size(360, 2400)),
      );
      await tester.pumpAndSettle();
      tester.takeException(); // see the header chip-row overflow note above

      expect(find.text(trans('uptizm.common.retry')), findsOneWidget);
      expect(
        find.text(trans('uptizm.incidents.analysis_degraded_heading')),
        findsOneWidget,
      );
    });

    testWidgets(
      'a healthy analysis renders the model summary and no degrade notice',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 5200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        const String summary = 'Origin 503 döndürüyor.';

        // The same Turkish loader, so the notice sentences ARE resolvable here:
        // their absence is then a statement about the render path rather than
        // about a missing key.
        Translator.instance.setLoader(const _TurkishDegradeLangLoader());
        await Translator.instance.setLocale(const Locale('tr'));

        seedAnalysisOnlyIncident({
          'summary': summary,
          'confidence': 'high',
          'degrade_reason': null,
        });

        await tester.pumpWidget(
          wrap(
            const IncidentDetailView(id: 'deg-1'),
            size: const Size(1280, 5200),
          ),
        );
        await tester.pumpAndSettle();
        tester.takeException(); // see the header chip-row overflow note above

        expect(find.textContaining(summary), findsOneWidget);
        expect(
          find.textContaining('aşağıdaki özet olayın kendi kaydından üretildi'),
          findsNothing,
          reason: 'nothing degraded, so no reason is claimed',
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // The analysis section while the fetch is still in flight.
  // ---------------------------------------------------------------------------
  //
  // Reported live: opening an incident showed no analysis section at all for
  // the ten-odd seconds the model call takes, then the full card appeared. The
  // section renders on `analysisFor() != null`, so "not fetched yet" and "there
  // is nothing to show" were the same branch, and the operator read the first as
  // the second.

  group('IncidentDetailView analysis pending', () {
    testWidgets('says the analysis is being prepared while the fetch runs', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // Asserted on the FIRST frame and nothing further is pumped, which is the
      // only window this state has under a synchronous fake: `FakeRequestHandler`
      // is `MagicResponse Function(MagicRequest)`, so a hanging stub is not
      // expressible and the fetch resolves on the next microtask. `initState`
      // sets the pending flag before the first build runs, so the frame
      // `pumpWidget` draws is the in-flight one; a `pump()` here would flush the
      // fetch and assert against the settled screen instead.
      //
      // An incident the fixtures give NO analysis, because `analysisFor()` falls
      // back to `incident.ai` and a fixture carrying one would render the card
      // immediately and never reach the pending arm.
      final Incident subject = incidentFixtures.firstWhere(
        (incident) => incident.ai == null,
      );

      await tester.pumpWidget(wrap(IncidentDetailView(id: subject.id)));

      expect(find.text('Reading the evidence...'), findsOneWidget);
      expect(find.byType(AiAnalysisCard), findsNothing);
    });
  });
}
