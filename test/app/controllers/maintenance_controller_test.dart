import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/maintenance_controller.dart';
import 'package:uptizm/app/models/scheduled_maintenance.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.error() / MagicFeedback's warning fallback resolve
    // a log service instead of throwing (create/delete both log on failure).
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver so the wired create/delete/load actions
    // resolve the `network` service. Individual tests override it with their
    // own `Http.fake({...})` map to seed a canned envelope.
    Http.fake();
    // Force-build the lazy GoRouter so MagicRoute.to (used by a successful
    // create) does not throw StateError('Router not initialized...').
    MagicRouter.instance.routerConfig;
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  group('create', () {
    test(
      'POSTs /scheduled-maintenances with the given fields and reloads',
      () async {
        final fake = Http.fake({
          'scheduled-maintenances': Http.response({
            'data': {'id': 'w-1', 'title': 'Database upgrade'},
          }, 201),
        });
        // create() navigates to /incidents on success, which needs a page
        // registered for MagicRoute.to to resolve against, mirroring
        // IncidentController.create's test setup for the same navigation.
        MagicRouter.reset();
        MagicRoute.page('/', () => const SizedBox());
        MagicRoute.page('/incidents', () => const SizedBox());
        MagicRouter.instance.routerConfig;

        final Map<String, String> errors = await MaintenanceController.instance
            .create({
              'status_page_id': 'page-1',
              'title': 'Database upgrade',
              'starts_at': '2026-09-10T00:00:00Z',
              'ends_at': '2026-09-10T02:00:00Z',
              'monitor_ids': ['checkout'],
            });

        expect(errors, isEmpty);
        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/scheduled-maintenances' &&
              (r.data as Map)['title'] == 'Database upgrade',
        );
        fake.assertSent(
          (r) => r.method == 'GET' && r.url == '/scheduled-maintenances',
        );

        MagicRouter.reset();
      },
    );

    test(
      'a 422 with field errors returns them keyed by wire field name',
      () async {
        Http.fake({
          'scheduled-maintenances': Http.response({
            'message': 'The given data was invalid.',
            'errors': {
              'title': ['The title field is required.'],
            },
          }, 422),
        });

        final Map<String, String> errors = await MaintenanceController.instance
            .create({'status_page_id': 'page-1'});

        expect(errors, equals({'title': 'The title field is required.'}));
      },
    );

    test(
      'a non-field failure (500) returns an empty map, not a thrown error',
      () async {
        Http.fake({
          'scheduled-maintenances': Http.response(
            {'message': 'Server Error'},
            500,
          ),
        });

        final Map<String, String> errors = await MaintenanceController.instance
            .create({'status_page_id': 'page-1', 'title': 'Database upgrade'});

        expect(errors, isEmpty);
      },
    );
  });

  group('delete', () {
    test('DELETEs /scheduled-maintenances/{id} and reloads the roster', () async {
      final MaintenanceController controller = MaintenanceController.instance;
      controller.seedForTest([
        ScheduledMaintenance.fromMap({'id': 'w-1', 'title': 'Database upgrade'}),
      ]);
      final fake = Http.fake({
        'scheduled-maintenances/w-1': Http.response(null, 204),
        'scheduled-maintenances': Http.response({'data': []}, 200),
      });

      await controller.delete('w-1');

      fake.assertSent(
        (r) => r.method == 'DELETE' && r.url == '/scheduled-maintenances/w-1',
      );
      fake.assertSent(
        (r) => r.method == 'GET' && r.url == '/scheduled-maintenances',
      );
    });

    test('an unknown id is a no-op', () async {
      final MaintenanceController controller = MaintenanceController.instance;
      controller.seedForTest([
        ScheduledMaintenance.fromMap({'id': 'w-1', 'title': 'Database upgrade'}),
      ]);
      final fake = Http.fake();

      await controller.delete('does-not-exist');

      fake.assertNothingSent();
    });

    test(
      'a failed delete surfaces the error toast without throwing',
      () async {
        final MaintenanceController controller = MaintenanceController.instance;
        controller.seedForTest([
          ScheduledMaintenance.fromMap({'id': 'w-2', 'title': 'Router swap'}),
        ]);
        // Only the delete route is stubbed: a failed delete (ok == false, no
        // exception) returns before the roster reload, so no GET is expected.
        Http.fake({
          'scheduled-maintenances/w-2': Http.response(
            {'message': 'Server Error'},
            500,
          ),
        });

        await expectLater(controller.delete('w-2'), completes);
      },
    );
  });
}
