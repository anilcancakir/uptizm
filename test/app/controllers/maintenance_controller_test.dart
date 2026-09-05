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

  /// A complete `StoreScheduledMaintenanceRequest`-shaped payload, so a test
  /// exercising a SERVER-only rejection is not itself refused by
  /// `MaintenanceController._createRules` before the request is ever built.
  Map<String, dynamic> validPayload([Map<String, dynamic>? overrides]) => {
    'status_page_id': 'page-1',
    'title': 'Database upgrade',
    'starts_at': '2026-09-10T00:00:00Z',
    'ends_at': '2026-09-10T02:00:00Z',
    'monitor_ids': ['checkout'],
    ...?overrides,
  };

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

        final bool ok = await MaintenanceController.instance.create(
          validPayload(),
        );

        expect(ok, isTrue);
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

    test('a blank title is refused here, and nothing is sent', () async {
      // The client-side half of the validation contract:
      // `_createRules` mirrors `StoreScheduledMaintenanceRequest`'s `title`
      // => `Required()`, so a blank title never reaches the network.
      final fake = Http.fake();

      final bool ok = await MaintenanceController.instance.create(
        validPayload({'title': ''}),
      );

      expect(ok, isFalse, reason: 'a refused write did not happen');
      expect(MaintenanceController.instance.hasError('title'), isTrue);
      fake.assertNothingSent();
    });

    test(
      'a 422 on a wire key this client does not mirror (monitor_ids.0) lands '
      'on the collapsed field, keyed by wire field name',
      () async {
        // `monitor_ids.*`'s `Rule::exists` is deliberately absent from
        // `_createRules` (an AsyncRule would be silently skipped anyway), so
        // a payload naming a monitor outside the team reaches the network and
        // is refused there. This is what proves a wire key still survives to
        // the form: `fieldErrorsFromModel` collapses the dot-numeric
        // `monitor_ids.0` down onto `monitor_ids`, the same key
        // `IncidentCreateView` reads for the Affected field in maintenance
        // mode.
        Http.fake({
          'scheduled-maintenances': Http.response({
            'message': 'The given data was invalid.',
            'errors': {
              'monitor_ids.0': ['The selected monitor_ids.0 is invalid.'],
            },
          }, 422),
        });

        final bool ok = await MaintenanceController.instance.create(
          validPayload({
            'monitor_ids': ['not-on-this-team'],
          }),
        );

        expect(ok, isFalse);
        expect(
          MaintenanceController.instance.getError('monitor_ids'),
          equals('The selected monitor_ids.0 is invalid.'),
        );
      },
    );

    test(
      'a non-field failure (500) answers false without throwing',
      () async {
        Http.fake({
          'scheduled-maintenances': Http.response(
            {'message': 'Server Error'},
            500,
          ),
        });

        final bool ok = await MaintenanceController.instance.create(
          validPayload(),
        );

        expect(ok, isFalse);
        expect(MaintenanceController.instance.hasErrors, isFalse);
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
