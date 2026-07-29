import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/controllers/dashboard_controller.dart';
import 'package:uptizm/app/enums/incident_lifecycle.dart'
    show IncidentLifecycle;
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/resources/views/dashboard/dashboard_view.dart';
import 'package:uptizm/ui/components/ai_insight/index.dart';
import 'package:uptizm/ui/components/incident_card/index.dart';
import 'package:uptizm/ui/layouts/page_container.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/monitor_list_row/index.dart';
import '../../support/skeleton_matchers.dart';

/// In-memory loader feeding the dashboard's prose so [trans] resolves the real
/// English strings (which wrap on multiple words) instead of falling back to
/// the raw, unbreakable key tokens. This mirrors production, where the bundled
/// `assets/lang/en.json` is loaded; without it the long single-token fleet
/// summary key cannot wrap and overflows its banner row.
class _DashboardLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    // The Translator caches whatever the loader returns verbatim; flattening is
    // the loader's job (see JsonAssetLoader), so the keys are pre-flattened.
    return {
      'uptizm.dashboard.title': 'Dashboard',
      'uptizm.dashboard.description':
          'Everything Uptizm is watching, at a glance.',
      'uptizm.dashboard.ai_fleet_summary':
          'Checkout is in a major outage (origin-side 503s across all regions) '
          'and API gateway is degraded as your cpu_load metric climbs. Docs is '
          'paused for maintenance. Marketing site is the only fully operational '
          'service right now.',
      'uptizm.dashboard.section_active_incidents': 'Active incidents',
      'uptizm.dashboard.section_monitors': 'Monitors',
      'uptizm.dashboard.section_ai_inbox': 'AI inbox',
      'uptizm.dashboard.ai_inbox_subtitle':
          'Anomalies Uptizm flagged from its own checks and metrics, not yet '
          'incidents. Your call.',
      'uptizm.dashboard.ai_inbox_pending': ':count pending',
      'uptizm.dashboard.ai_inbox_weekly_digest': 'Weekly digest',
      'uptizm.monitors.empty_no_monitors_title': 'No monitors yet',
      'uptizm.monitors.empty_no_monitors_description': 'Add your first monitor.',
      'uptizm.monitors.new_monitor': 'New monitor',
      'uptizm.dashboard.ai_inbox_empty':
          'Inbox zero. No anomalies need your attention.',
      'uptizm.dashboard.kpi_monitors_up': 'Monitors up',
      'uptizm.dashboard.kpi_uptime_24h': 'Uptime (24h)',
      'uptizm.dashboard.kpi_open_incidents': 'Open incidents',
      'uptizm.dashboard.kpi_avg_response': 'Avg response',
      'uptizm.dashboard.kpi_hint_vs_yesterday': 'vs. yesterday',
      'uptizm.dashboard.kpi_hint_vs_24h': 'vs. last 24h',
      'uptizm.dashboard.kpi_hint_ai_detected': ':count AI-detected',
      'uptizm.dashboard.kpi_delta_down': ':count down',
      'uptizm.ai.right_now_label': 'Right now',
      'uptizm.ai.open_incident': 'Open incident',
      'uptizm.ai.dismiss': 'Dismiss',
      'uptizm.ai.ai_detected': 'AI-detected',
      // StatusBadge falls back to `uptizm.status.<name>` when no explicit
      // label is passed (IncidentCard's impact badge, MonitorListRow's status
      // badge); every StatusKey the fixtures use needs a short entry here.
      'uptizm.status.up': 'Up',
      'uptizm.status.down': 'Down',
      'uptizm.status.degraded': 'Degraded',
      'uptizm.status.paused': 'Paused',
      'uptizm.status.info': 'Info',
      'uptizm.status.ai': 'AI',
    };
  }
}

/// The three non-resolved fixture incidents, as `IncidentResource`-shaped wire
/// maps the [DashboardController] decodes for `GET /dashboard/active-incidents`.
///
/// Mirrors the active subset of the [incidents] fixture (checkout-503,
/// api-latency, maintenance-db) so the rendered IncidentCard count matches the
/// test's fixture-derived `activeCount`. Only the summary fields the card reads
/// are supplied; assignee/ai/acknowledged have no wire counterpart and stay
/// null on decode.
final List<Map<String, dynamic>> _activeIncidentPayload = [
  {
    'id': 'checkout-503',
    'title': 'Checkout service returning 503s across all regions',
    'impact': 'major',
    'severity': 'critical',
    'signal_source': 'ai_anomaly',
    'lifecycle': 'investigating',
    'ai_owned': true,
    'primary_monitor_id': 'checkout',
    'started_at': '2026-07-09T14:32:00Z',
    'monitors': [
      {
        'id': 'checkout',
        'name': 'Checkout service',
        'component_status_at_start': 'down',
        'component_status_current': 'down',
      },
    ],
  },
  {
    'id': 'api-latency',
    'title': 'Elevated p95 latency on API gateway',
    'impact': 'minor',
    'severity': 'warn',
    'signal_source': 'ai_anomaly',
    'lifecycle': 'identified',
    'ai_owned': true,
    'primary_monitor_id': 'api',
    'started_at': '2026-07-09T13:30:00Z',
    'monitors': [
      {
        'id': 'api',
        'name': 'API gateway',
        'component_status_at_start': 'degraded',
        'component_status_current': 'degraded',
      },
    ],
  },
  {
    'id': 'maintenance-db',
    'title': 'Scheduled database maintenance',
    'impact': 'none',
    'severity': 'info',
    'signal_source': 'manual',
    'lifecycle': 'monitoring',
    'ai_owned': false,
    'primary_monitor_id': 'api',
    'started_at': '2026-07-09T14:00:00Z',
    'monitors': [
      {
        'id': 'api',
        'name': 'API gateway',
        'component_status_at_start': 'info',
        'component_status_current': 'info',
      },
      {
        'id': 'checkout',
        'name': 'Checkout service',
        'component_status_at_start': 'info',
        'component_status_current': 'info',
      },
    ],
  },
];

/// The four fixture monitors, as `MonitorResource`-shaped wire maps the
/// [DashboardController] decodes for `GET /dashboard/monitors-snapshot`.
///
/// `last_response_ms` is intentionally omitted so each [MonitorListRow] renders
/// `—` for its latency and never collides with the `248ms` average-response KPI
/// value the "same KPI values as the controller" test matches on.
final List<Map<String, dynamic>> _monitorsSnapshotPayload = [
  {
    'id': 'marketing',
    'name': 'Marketing site',
    'url': 'https://uptizm.com',
    'last_status': 'up',
  },
  {
    'id': 'api',
    'name': 'API gateway',
    'url': 'https://api.uptizm.com/health',
    'last_status': 'degraded',
  },
  {
    'id': 'checkout',
    'name': 'Checkout service',
    'url': 'https://pay.uptizm.com',
    'last_status': 'down',
  },
  {
    'id': 'docs',
    'name': 'Docs',
    'url': 'https://docs.uptizm.com',
    'status': 'paused',
  },
];

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / PageHeader resolve their themes
    // via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Bind LogManager so the EntitlementController's offline-degradation path
    // (Log.error on the failed billing fetch this view triggers via
    // EntitlementController.instance) resolves instead of throwing.
    Magic.singleton('log', () => LogManager());

    // Load the real dashboard prose so trans() returns wrappable text.
    Translator.instance.setLoader(_DashboardLangLoader());
    await Translator.instance.setLocale(const Locale('en'));

    // Seed the four dashboard aggregate endpoints the wired controller fetches
    // on the view's onInit. The controller decodes each `{data: ...}` envelope
    // and republishes, so the view renders against these fixtures once the
    // async reload resolves (each test pumpAndSettles to let it land):
    //  - stats: one monitor in each of the four status buckets (up/down/
    //    degraded/paused, total 4) with a 248ms fleet average, 3 open
    //    incidents, and 99.95% 24h uptime (+0.03 vs the prior 24h), so
    //    `upCount / monitorCount` reads `1 / 4`, the avg-response KPI reads
    //    `248ms`, the open-incidents KPI reads `3`, and the uptime KPI reads
    //    `99.95%`.
    //  - active-incidents: the three non-resolved fixture incidents, so the
    //    active-incidents grid renders exactly three IncidentCards.
    //  - monitors-snapshot: the four fixture monitors (no `last_response_ms`,
    //    so each row reads `—` and never collides with the `248ms` avg KPI).
    //  - ai-inbox: empty (AI triage is deferred server-side), so the inbox
    //    renders its empty state while the weekly-digest link still shows.
    Http.fake({
      'dashboard/stats': Http.response({
        'data': {
          'monitors_up': 1,
          'monitors_down': 1,
          'monitors_degraded': 1,
          'monitors_paused': 1,
          'avg_response_ms': 248,
          'open_incidents': 3,
          'uptime_24h': 99.95,
          'uptime_24h_delta': 0.03,
        },
      }),
      'dashboard/active-incidents': Http.response({
        'data': _activeIncidentPayload,
      }),
      'dashboard/monitors-snapshot': Http.response({
        'data': _monitorsSnapshotPayload,
      }),
      'dashboard/ai-inbox': Http.response({'data': <dynamic>[]}),
    });

    // Register the controller the view resolves in initState. DashboardView
    // registers itself too, but registering here mirrors the canonical
    // harness (Conventions -> Test mount discipline) and makes the
    // dependency explicit for readers of this file.
    Magic.findOrPut(DashboardController.new);
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a
  /// MediaQuery sized between the `sm` (640px) and `lg` (1024px) Wind
  /// breakpoints.
  ///
  /// Below `lg`, `_buildLowerRegion`'s `lg:flex-row` split stays collapsed to
  /// `flex-col`, so the AI inbox rail renders at full container width. At
  /// `lg`+ the rail's `justify-between` heading row (section title vs.
  /// pending-count + "Weekly digest" link) splits into two equal `Flexible`
  /// halves instead of sizing each side to its content — a real Wind/view
  /// layout defect (see Issues in the Step 7 report), out of scope for this
  /// test-only step. Staying below `lg` avoids exercising that defect while
  /// still clearing `sm` so the KPI grid and incident grid widen as intended.
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

  testWidgets('DashboardView renders four KPI stat cards', (tester) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pumpAndSettle();

    expect(find.byType(KpiStatCard), findsNWidgets(4));
  });

  testWidgets('DashboardView renders at least one IncidentCard', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pumpAndSettle();

    expect(find.byType(IncidentCard), findsWidgets);
  });

  testWidgets('DashboardView renders one MonitorListRow per monitor', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pumpAndSettle();

    expect(find.byType(MonitorListRow), findsNWidgets(monitors.length));
  });

  testWidgets('DashboardView only lists active (non-resolved) incidents', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pumpAndSettle();

    final activeCount = incidents
        .where((i) => i.lifecycle != IncidentLifecycle.resolved)
        .length;
    expect(find.byType(IncidentCard), findsNWidgets(activeCount));
  });

  testWidgets('DashboardView wraps its content in a PageContainer', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pumpAndSettle();

    expect(find.byType(PageContainer), findsOneWidget);
  });

  testWidgets('DashboardView renders the AI fleet-summary banner', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pumpAndSettle();

    // The "Right now" banner ([AiInsight] tone: banner) sits between the header
    // and the KPI row, matching the React DashboardPage source. AiInsight
    // renders its label with a trailing space, so match on a substring.
    expect(find.byType(AiInsight), findsOneWidget);
    expect(
      find.textContaining(trans('uptizm.ai.right_now_label')),
      findsOneWidget,
    );
  });

  testWidgets('DashboardView surfaces the AI inbox weekly-digest link', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pumpAndSettle();

    expect(
      find.text(trans('uptizm.dashboard.ai_inbox_weekly_digest')),
      findsOneWidget,
    );
  });

  testWidgets('DashboardView renders the same KPI values as the controller', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2400));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pumpAndSettle();

    final DashboardController controller = DashboardController.instance;

    expect(
      find.text('${controller.upCount} / ${controller.monitorCount}'),
      findsOneWidget,
    );
    expect(find.text('${controller.activeIncidents.length}'), findsOneWidget);
    expect(find.text('${controller.avgResponseMs}ms'), findsOneWidget);
    // The uptime KPI renders the real backend value and its vs-yesterday
    // delta, not a fabricated constant.
    expect(find.text('99.95%'), findsOneWidget);
    expect(find.textContaining('0.03%'), findsOneWidget);
  });

  testWidgets(
    'DashboardView shows a no-data placeholder when uptime is unavailable',
    (tester) async {
      // A team whose monitors have not been checked yet: `stats` reports a
      // null uptime, so the KPI shows the "—" placeholder rather than a
      // fabricated percentage, and no vs-yesterday delta is rendered.
      Http.fake({
        'dashboard/stats': Http.response({
          'data': {
            'monitors_up': 1,
            'monitors_down': 0,
            'monitors_degraded': 0,
            'monitors_paused': 0,
            'avg_response_ms': null,
            'open_incidents': 0,
            'uptime_24h': null,
            'uptime_24h_delta': null,
          },
        }),
        'dashboard/active-incidents': Http.response({'data': <dynamic>[]}),
        'dashboard/monitors-snapshot': Http.response({
          'data': _monitorsSnapshotPayload,
        }),
        'dashboard/ai-inbox': Http.response({'data': <dynamic>[]}),
      });

      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const DashboardView()));
      await tester.pumpAndSettle();

      // Both the uptime and the avg-response KPI have no data, so each shows
      // the placeholder (two "—" values on the KPI row).
      expect(find.text('—'), findsWidgets);
      expect(find.text('vs. yesterday'), findsNothing);
    },
  );

  testWidgets(
    'a pending first read shows a skeleton, never the zero-monitor hero',
    (tester) async {
      // The regression this pins, and the sharpest instance of loading-vs-empty
      // in the product: every dashboard counter starts at 0, so before the first
      // read `monitorCount == 0` held and a POPULATED team landed on the
      // create-your-first-monitor onboarding hero until the fetch answered.
      await tester.binding.setSurfaceSize(const Size(1280, 2400));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      MagicApp.reset();
      Magic.flush();
      Magic.singleton('magic_starter', () => MagicStarterManager());
      Magic.singleton('log', () => LogManager());
      Http.fake();

      // Deliberately NOT pumped again: the first frame paints before the
      // controller's four aggregate reads resolve.
      await tester.pumpWidget(wrap(const DashboardView()));

      expect(find.byType(MSSkeleton), findsWidgets);
      expectVisibleSkeletons(tester);
      expect(
        find.text(trans('uptizm.monitors.empty_no_monitors_title')),
        findsNothing,
        reason: 'a pending read must not claim the team has no monitors',
      );
    },
  );
}
