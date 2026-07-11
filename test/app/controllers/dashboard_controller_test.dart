import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/dashboard_controller.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Binds a fake network driver seeding every dashboard aggregate endpoint
  /// with a canned `{data: ...}` envelope and returns the controller. The
  /// controller decodes these via the wired `reload`; the assertions below
  /// exercise that wiring in place of the removed fixture-derivation logic.
  DashboardController seedDashboard() {
    Http.fake({
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
    });
    return DashboardController.instance;
  }

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
    // monitorCount sums every last-known status bucket (7 + 2 + 1 + 3).
    expect(controller.monitorCount, equals(13));
    expect(controller.avgResponseMs, equals(214));
    expect(controller.openIncidentsCount, equals(5));
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
}
