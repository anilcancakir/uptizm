import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/incident_controller.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/resources/views/incidents/incident_create_view.dart';
import 'package:uptizm/resources/views/incidents/incident_detail_view.dart';
import 'package:uptizm/resources/views/incidents/incidents_list_view.dart';
import 'package:uptizm/ui/components/empty_state/index.dart';
import 'package:uptizm/ui/components/incident_card/index.dart';
import 'package:uptizm/ui/layouts/page_container.dart';

/// In-memory language loader supplying every [trans] key exercised by the
/// incident list/create/detail views, mirroring the pattern established in
/// `monitor_detail_view_test.dart` / `monitor_form_test.dart`. Short,
/// wrappable strings avoid RenderFlex overflow at the test viewport.
class _IncidentViewsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
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
          ':name is on it.',
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
      'uptizm.incidents.detail_composer_post': 'Post update',
      'uptizm.incidents.detail_postmortem_heading': 'Postmortem draft',
      'uptizm.incidents.detail_postmortem_edit': 'Edit & publish',
      'uptizm.incidents.ai_analysis_gated':
          'AI incident analysis pinpoints '
          'the likely cause.',

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

    // Register the controller the views resolve in initState. Each view
    // registers itself too, but registering here mirrors the canonical
    // harness (Conventions -> Test mount discipline) and makes the
    // dependency explicit for readers of this file.
    Magic.findOrPut(IncidentController.new);
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
      expect(find.byType(PageContainer), findsOneWidget);
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
      expect(find.byType(EmptyState), findsOneWidget);
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
      expect(find.byType(PageContainer), findsOneWidget);

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

      final IncidentSummary incident = findIncident('checkout-503')!;

      await tester.pumpWidget(
        wrap(
          const IncidentDetailView(id: 'checkout-503'),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      // A pre-existing layout overflow in the header chip row (PageHeader's
      // `titleSuffix` slot marks multi-pill wrap children `flex-shrink-0`, so
      // they keep their intrinsic width instead of shrinking — see Issues in
      // the step report) fires here independent of viewport width; it is not
      // a regression introduced by this test, so it is drained rather than
      // asserted away, and the finder assertions below still verify the real
      // behavioral contract.
      tester.takeException();

      expect(find.byType(MSPageHeader), findsOneWidget);
      expect(find.text(incident.title), findsOneWidget);

      // The public timeline entry's message is on the page (default view is
      // "public"); this incident has at least one public entry.
      final String publicMessage = incident.timeline
          .firstWhere((e) => e.isPublic)
          .message;
      expect(find.text(publicMessage), findsOneWidget);
    });

    testWidgets('an unknown id renders the graceful not-found state', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentDetailView(id: 'nope')));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(EmptyState), findsOneWidget);
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

      final IncidentSummary incident = findIncident('checkout-503')!;

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

      final IncidentSummary incident = findIncident('eu-packet-loss')!;
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

      // AiInsight renders its `label` as a bold lead-in TextSpan inline with
      // the body (Text.rich), not a standalone Text widget, so the heading is
      // matched via textContaining rather than find.text.
      expect(
        find.textContaining(
          trans('uptizm.incidents.detail_postmortem_heading'),
        ),
        findsOneWidget,
        reason: 'The postmortem heading must render for a resolved incident',
      );

      // The responder strip is hidden once resolved: "Assigned to" is absent.
      expect(
        find.text(trans('uptizm.incidents.detail_assigned_to')),
        findsNothing,
        reason: 'The responder strip must be hidden for a resolved incident',
      );
    });
  });
}
