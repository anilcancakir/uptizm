import 'package:flutter/widgets.dart' show Locale;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/dashboard_controller.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/models/monitor.dart';

import '../../support/bundled_lang.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// The canned `{data: ...}` envelope for every dashboard aggregate endpoint.
  ///
  /// Exposed separately from [seedDashboard] so a test that needs the
  /// [FakeNetworkDriver] itself (to count recorded requests) can bind the same
  /// stubs and keep the returned driver.
  Map<String, MagicResponse> dashboardStubs() {
    return {
      'dashboard/stats': Http.response({
        'data': {
          'monitors_up': 7,
          'monitors_down': 2,
          'monitors_degraded': 1,
          'monitors_paused': 3,
          'avg_response_ms': 214,
          'open_incidents': 5,
        },
      }),
      'dashboard/active-incidents': Http.response({
        'data': [
          {
            'id': 'inc-1',
            'title': 'Checkout returning 503s',
            'lifecycle': 'investigating',
            'ai_owned': true,
            'started_at': '2026-07-11T14:00:00Z',
            'monitors': [
              {
                'id': 'm1',
                'name': 'Checkout',
                'component_status_at_start': 'down',
                'component_status_current': 'down',
              },
            ],
          },
          {
            'id': 'inc-2',
            'title': 'Elevated latency in EU',
            'lifecycle': 'monitoring',
            'ai_owned': false,
            'started_at': '2026-07-11T13:00:00Z',
            'monitors': [
              {
                'id': 'm2',
                'name': 'API',
                'component_status_at_start': 'degraded',
                'component_status_current': 'up',
              },
            ],
          },
        ],
      }),
      'dashboard/monitors-snapshot': Http.response({
        'data': [
          {'id': 'm1', 'name': 'API', 'last_status': 'up'},
          {'id': 'm2', 'name': 'Web', 'last_status': 'down'},
        ],
      }),
      'dashboard/ai-inbox': Http.response({
        'data': [
          {
            'id': 'ai-1',
            'title': 'Anomaly on payments',
            'lifecycle': 'detected',
            'ai_owned': true,
            'started_at': '2026-07-11T12:00:00Z',
          },
        ],
      }),
    };
  }

  /// Binds a fake network driver seeding every dashboard aggregate endpoint
  /// with a canned `{data: ...}` envelope and returns the controller. The
  /// controller decodes these via the wired `reload`; the assertions below
  /// exercise that wiring in place of the removed fixture-derivation logic.
  DashboardController seedDashboard() {
    Http.fake(dashboardStubs());
    return DashboardController.instance;
  }

  // ---------------------------------------------------------------------------
  // noteCheckRecorded: the socket reading path
  // ---------------------------------------------------------------------------

  /// A `check.recorded` frame as the backend shapes it.
  Map<String, dynamic> reading({
    String id = 'm1',
    String lastStatus = 'up',
    String checkedAt = '2026-08-19T09:30:00+00:00',
    int? responseMs = 143,
  }) => <String, dynamic>{
    'monitor_id': id,
    'region': 'eu-west',
    'result': lastStatus,
    'response_ms': responseMs,
    'checked_at': checkedAt,
    'last_status': lastStatus,
    'last_checked_at': checkedAt,
    'last_response_ms': responseMs,
  };

  /// How many `dashboard/stats` reads [driver] recorded.
  int statsReads(FakeNetworkDriver driver) => driver.recorded
      .where((entry) => entry.$1.url.contains('dashboard/stats'))
      .length;

  test('noteCheckRecorded patches the snapshot row in place', () async {
    final FakeNetworkDriver driver = Http.fake(dashboardStubs());
    final DashboardController controller = DashboardController.instance;
    await controller.reload();

    controller.noteCheckRecorded(reading(lastStatus: 'degraded', responseMs: 512));

    final Monitor patched = controller.monitorsSnapshot
        .firstWhere((Monitor m) => m.id == 'm1');
    expect(patched.status, equals(StatusKey.degraded));
    expect(patched.responseMs, equals(512));
    expect(driver.recorded, isNotEmpty);
  });

  test(
    'a burst of readings costs at most one extra stats read per throttle window',
    () async {
      final FakeNetworkDriver driver = Http.fake(dashboardStubs());
      final DashboardController controller = DashboardController.instance;
      await controller.reload();
      final int afterReload = statsReads(driver);

      // Three regions of two monitors inside one window: six frames. Without the
      // throttle this is six aggregate reads, which is the polling the socket was
      // supposed to replace.
      for (int i = 0; i < 3; i++) {
        controller.noteCheckRecorded(reading(id: 'm1'));
        controller.noteCheckRecorded(reading(id: 'm2'));
      }
      await Future<void>.delayed(const Duration(milliseconds: 20));

      expect(statsReads(driver) - afterReload, equals(1));
    },
  );

  test(
    'a reading for a monitor outside the snapshot patches and reads nothing',
    () async {
      final FakeNetworkDriver driver = Http.fake(dashboardStubs());
      final DashboardController controller = DashboardController.instance;
      await controller.reload();
      final int afterReload = statsReads(driver);

      // An unknown monitor means the snapshot is stale in a way one row cannot
      // repair, so this path deliberately does nothing at all and leaves the next
      // reload to settle it.
      controller.noteCheckRecorded(reading(id: 'never-listed'));
      await Future<void>.delayed(const Duration(milliseconds: 20));

      expect(statsReads(driver) - afterReload, equals(0));
      expect(controller.monitorsSnapshot.map((Monitor m) => m.id).toList(),
          equals(['m1', 'm2']));
    },
  );

  test('noteCheckRecorded ignores a reading older than the one held', () async {
    Http.fake({
      ...dashboardStubs(),
      'dashboard/monitors-snapshot': Http.response({
        'data': [
          {
            'id': 'm1',
            'name': 'API',
            'last_status': 'down',
            'last_checked_at': '2026-08-19T09:30:00+00:00',
            'last_response_ms': 512,
          },
        ],
      }),
    });
    final DashboardController controller = DashboardController.instance;
    await controller.reload();

    controller.noteCheckRecorded(
      reading(lastStatus: 'up', checkedAt: '2026-08-19T09:29:00+00:00', responseMs: 90),
    );

    final Monitor held = controller.monitorsSnapshot.single;
    expect(held.status, equals(StatusKey.down));
    expect(held.responseMs, equals(512));
  });

  test('DashboardController.instance registers and returns a singleton', () {
    final DashboardController first = DashboardController.instance;
    final DashboardController second = DashboardController.instance;

    expect(identical(first, second), isTrue);
  });

  test('reload populates the KPI counters from GET /dashboard/stats', () async {
    final DashboardController controller = seedDashboard();

    await controller.reload();

    expect(controller.upCount, equals(7));
    expect(controller.downCount, equals(2));
    // No monitors_total in this payload, so monitorCount falls back to the
    // bucket sum plus pending (7 + 2 + 1 + 3 + 0).
    expect(controller.monitorCount, equals(13));
    expect(controller.avgResponseMs, equals(214));
    expect(controller.openIncidentsCount, equals(5));
  });

  test('pausedCount reads monitors_paused', () async {
    // A paused monitor sits in the total but can never sit in `upCount`, so the
    // view needs the count to explain the gap. The field was parsed and then
    // only ever used in the bucket-sum fallback, so no surface could name it.
    Http.fake({
      'dashboard/stats': Http.response({
        'data': {
          'monitors_up': 2,
          'monitors_down': 0,
          'monitors_degraded': 0,
          'monitors_paused': 3,
          'monitors_pending': 0,
          'monitors_total': 5,
          'avg_response_ms': 120,
          'open_incidents': 0,
        },
      }),
      'dashboard/active-incidents': Http.response({'data': []}),
      'dashboard/monitors-snapshot': Http.response({'data': []}),
      'dashboard/ai-inbox': Http.response({'data': []}),
    });
    final DashboardController controller = DashboardController.instance;

    await controller.reload();

    expect(controller.pausedCount, equals(3));
    expect(controller.upCount, equals(2));
    expect(controller.monitorCount, equals(5));
  });

  test('monitorCount reads monitors_total, so pending monitors count', () async {
    // A team that just created three monitors: none checked yet, so every
    // status bucket is zero and only the total and pending counts carry them.
    Http.fake({
      'dashboard/stats': Http.response({
        'data': {
          'monitors_up': 0,
          'monitors_down': 0,
          'monitors_degraded': 0,
          'monitors_paused': 0,
          'monitors_pending': 3,
          'monitors_total': 3,
          'avg_response_ms': null,
          'open_incidents': 0,
        },
      }),
      'dashboard/active-incidents': Http.response({'data': []}),
      'dashboard/monitors-snapshot': Http.response({'data': []}),
      'dashboard/ai-inbox': Http.response({'data': []}),
    });
    final DashboardController controller = DashboardController.instance;

    await controller.reload();

    // Summing the buckets would read 0 here and fire the "add your first
    // monitor" empty state at a team that already has three.
    expect(controller.monitorCount, equals(3));
    expect(controller.pendingCount, equals(3));
    expect(controller.upCount, equals(0));
    expect(controller.hasAvgResponse, isFalse);
  });

  test('fleetSummary never calls an unchecked monitor operational', () async {
    Http.fake({
      'dashboard/stats': Http.response({
        'data': {
          'monitors_up': 0,
          'monitors_down': 0,
          'monitors_degraded': 0,
          'monitors_paused': 0,
          'monitors_pending': 3,
          'monitors_total': 3,
          'open_incidents': 0,
        },
      }),
      'dashboard/active-incidents': Http.response({'data': []}),
      'dashboard/monitors-snapshot': Http.response({'data': []}),
      'dashboard/ai-inbox': Http.response({'data': []}),
    });
    final DashboardController controller = DashboardController.instance;

    await controller.reload();

    // Nothing is down and no incident is open, but three monitors have reported
    // nothing at all: claiming "All 3 monitors are operational" would assert a
    // state the backend never sent. No lang loader runs here, so the summary
    // carries raw keys and the assertion reads which sentence was chosen.
    final String summary = controller.fleetSummary;
    expect(summary, contains('fleet_operational_ratio'));
    expect(summary, contains('fleet_pending_suffix'));
    expect(summary, isNot(contains('fleet_all_operational')));
  });

  test('fleetSummary reads grammatically for a single monitor', () async {
    // The all-operational sentence is the one a healthy account sees every day,
    // and a brand new account has exactly one monitor. Asserted against the
    // SHIPPED catalogue rather than a fixture, because a fixture would agree
    // with the test author instead of with the product, which is how "All 1
    // monitors are operational" got as far as an iPhone screenshot.
    Translator.instance.setLoader(_BundledLoader('en'));
    await Translator.instance.setLocale(const Locale('en'));

    Http.fake({
      'dashboard/stats': Http.response({
        'data': {
          'monitors_up': 1,
          'monitors_down': 0,
          'monitors_degraded': 0,
          'monitors_paused': 0,
          'monitors_pending': 0,
          'monitors_total': 1,
          'open_incidents': 0,
        },
      }),
      'dashboard/active-incidents': Http.response({'data': []}),
      'dashboard/monitors-snapshot': Http.response({'data': []}),
      'dashboard/ai-inbox': Http.response({'data': []}),
    });
    final DashboardController controller = DashboardController.instance;

    await controller.reload();

    expect(controller.fleetSummary, isNot(contains('1 monitors')));
    expect(controller.fleetSummary, contains('monitor is operational'));
  });

  test(
    'reload decodes the active incidents from GET /dashboard/active-incidents',
    () async {
      final DashboardController controller = seedDashboard();

      await controller.reload();

      expect(
        controller.activeIncidents.map((i) => i.id).toList(),
        equals(['inc-1', 'inc-2']),
      );
      // aiActiveCount stays derived client-side from the decoded list.
      expect(controller.aiActiveCount, equals(1));
    },
  );

  test(
    'reload decodes the monitor snapshot from GET /dashboard/monitors-snapshot',
    () async {
      final DashboardController controller = seedDashboard();

      await controller.reload();

      expect(
        controller.monitorsSnapshot.map((m) => m.id).toList(),
        equals(['m1', 'm2']),
      );
    },
  );

  test('reload decodes the AI inbox from GET /dashboard/ai-inbox', () async {
    final DashboardController controller = seedDashboard();

    await controller.reload();

    expect(controller.aiSuggestions.map((i) => i.id).toList(), equals(['ai-1']));
  });

  test(
    'reload degrades to zeroed counters and empty lists when the network is '
    'unavailable',
    () async {
      // No network bound: every aggregate fetch resolves an unregistered
      // service; the defensive `reload` must swallow each and never throw.
      final DashboardController controller = DashboardController.instance;

      await controller.reload();

      expect(controller.upCount, equals(0));
      expect(controller.downCount, equals(0));
      expect(controller.monitorCount, equals(0));
      expect(controller.openIncidentsCount, equals(0));
      expect(controller.activeIncidents, isEmpty);
      expect(controller.monitorsSnapshot, isEmpty);
      expect(controller.aiSuggestions, isEmpty);
    },
  );

  // ---------------------------------------------------------------------------
  // .instance: the one-shot initial load, shared with onInit.
  // ---------------------------------------------------------------------------

  group('DashboardController.instance', () {
    test('resolving the singleton kicks off the load on its own', () async {
      // Regression guard: magic's `onInit` fires only for a MagicView's
      // BACKING controller, so a view consulting this one as a secondary (the
      // monitors list sources its OPEN INCIDENTS + AI-active KPIs here so they
      // agree with the dashboard) never triggered the load, and the KPI
      // rendered the untouched `0` as if the team had no open incident.
      final DashboardController controller = seedDashboard();

      // The load is async and fire-and-forget; pump until it settles.
      for (var i = 0; i < 50 && controller.openIncidentsCount == 0; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 1));
      }

      expect(
        controller.openIncidentsCount,
        equals(5),
        reason: 'resolving .instance must start the load on its own',
      );
      expect(controller.aiActiveCount, equals(1));
    });

    test('onInit shares the guard, so a mounted view does not refetch', () async {
      final fake = Http.fake(dashboardStubs());
      final DashboardController controller = DashboardController.instance;

      for (var i = 0; i < 50 && controller.openIncidentsCount == 0; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 1));
      }
      final int afterFirstLoad = fake.recorded.length;

      // The dashboard view mounting afterwards runs `onInit`; both paths share
      // the one-shot guard, so the four aggregates must not be fetched twice.
      controller.onInit();
      await Future<void>.delayed(const Duration(milliseconds: 5));

      expect(afterFirstLoad, equals(4));
      expect(fake.recorded.length, equals(afterFirstLoad));
    });
  });

  // ---------------------------------------------------------------------------
  // resetForSession: clear the previous identity's data, then refetch.
  // ---------------------------------------------------------------------------

  group('resetForSession', () {
    test('clears every counter and panel even when the refetch fails', () async {
      final DashboardController controller = seedDashboard();
      await controller.reload();
      expect(controller.openIncidentsCount, equals(5));
      expect(controller.activeIncidents, isNotEmpty);

      // The new identity's refetch cannot reach the backend. `reload` alone
      // would keep the previous team's counters and incidents on screen; the
      // reset must leave the dashboard empty instead.
      Http.unfake();
      var notified = 0;
      controller.addListener(() => notified++);

      await controller.resetForSession();

      expect(notified, greaterThan(0));
      expect(controller.upCount, equals(0));
      expect(controller.downCount, equals(0));
      expect(controller.pendingCount, equals(0));
      expect(controller.monitorCount, equals(0));
      expect(controller.openIncidentsCount, equals(0));
      expect(controller.hasAvgResponse, isFalse);
      expect(controller.uptime24h, isNull);
      expect(controller.uptime24hDelta, isNull);
      expect(controller.activeIncidents, isEmpty);
      expect(controller.monitorsSnapshot, isEmpty);
      expect(controller.aiSuggestions, isEmpty);
    });

    test('refetches for the identity that is now authenticated', () async {
      final DashboardController controller = seedDashboard();
      await controller.reload();

      Http.fake({
        'dashboard/stats': Http.response({
          'data': {
            'monitors_up': 1,
            'monitors_down': 0,
            'monitors_degraded': 0,
            'monitors_paused': 0,
            'monitors_pending': 0,
            'monitors_total': 1,
            'open_incidents': 0,
          },
        }),
        'dashboard/active-incidents': Http.response({'data': []}),
        'dashboard/monitors-snapshot': Http.response({
          'data': [
            {'id': 'other-team-api', 'name': 'API', 'last_status': 'up'},
          ],
        }),
        'dashboard/ai-inbox': Http.response({'data': []}),
      });

      await controller.resetForSession();

      expect(controller.monitorCount, equals(1));
      expect(controller.openIncidentsCount, equals(0));
      expect(controller.activeIncidents, isEmpty);
      expect(
        controller.monitorsSnapshot.map((m) => m.id).toList(),
        equals(['other-team-api']),
      );
    });
  });
}

/// Serves the shipped catalogue for one locale, so the sentence asserted above
/// is the one an operator reads rather than a key.
class _BundledLoader implements TranslationLoader {
  _BundledLoader(this.locale);

  final String locale;

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}
