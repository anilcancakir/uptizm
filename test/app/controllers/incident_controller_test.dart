import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/incident_controller.dart';
import 'package:uptizm/app/mocks/incidents.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Binds a fake network driver seeding `GET /incidents` with a canned
  /// `{data: [...]}` envelope covering an active, a resolved, and a second
  /// active incident. The controller decodes these via the wired `load`; the
  /// assertions below exercise that wiring in place of the removed
  /// fixture-equality checks.
  void seedIncidents() {
    Http.fake({
      'incidents': Http.response({
        'data': [
          {
            'id': 'inc-1',
            'title': 'Checkout returning 503s',
            'lifecycle': 'investigating',
            'started_at': '2026-07-11T14:00:00Z',
            'monitors': [
              {'id': 'm1', 'name': 'Checkout'},
            ],
          },
          {
            'id': 'inc-2',
            'title': 'EU packet loss',
            'lifecycle': 'resolved',
            'started_at': '2026-07-10T10:00:00Z',
            'resolved_at': '2026-07-10T11:00:00Z',
            'monitors': [
              {'id': 'm2', 'name': 'API'},
            ],
          },
          {
            'id': 'inc-3',
            'title': 'Latency spike',
            'lifecycle': 'monitoring',
            'started_at': '2026-07-11T09:00:00Z',
            'monitors': [
              {'id': 'm3', 'name': 'Web'},
            ],
          },
        ],
      }),
    });
  }

  test('IncidentController.instance registers and returns a singleton', () {
    final IncidentController first = IncidentController.instance;
    final IncidentController second = IncidentController.instance;

    expect(identical(first, second), isTrue);
  });

  test('load decodes the incident list from GET /incidents', () async {
    seedIncidents();
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );

    await controller.load();

    expect(
      controller.incidents.map((i) => i.id).toList(),
      equals(['inc-1', 'inc-2', 'inc-3']),
    );
    expect(controller.isSuccess, isTrue);
  });

  test('incidentById resolves a decoded incident from the loaded list', () async {
    seedIncidents();
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );
    await controller.load();

    final IncidentSummary? resolved = controller.incidentById('inc-2');

    expect(resolved, isNotNull);
    expect(resolved!.id, equals('inc-2'));
    expect(resolved.lifecycle, equals(IncidentLifecycle.resolved));
  });

  test('incidentById returns null for an unknown id', () async {
    seedIncidents();
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );
    await controller.load();

    expect(controller.incidentById('does-not-exist'), isNull);
  });

  test('activeIncidents derives the not-resolved subset of the loaded list', () async {
    seedIncidents();
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );
    await controller.load();

    expect(
      controller.activeIncidents.map((i) => i.id).toList(),
      equals(['inc-1', 'inc-3']),
    );
  });

  test('aiSuggestions stays empty until AI analysis is wired', () async {
    seedIncidents();
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );
    await controller.load();

    expect(controller.aiSuggestions, isEmpty);
  });

  test(
    'load degrades to an empty list and an error state when the network is '
    'unavailable',
    () async {
      // No network bound: `Http.get` resolves an unregistered service; the
      // defensive `load` must surface the controller's error state and never
      // throw out of onInit/reload.
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      await controller.load();

      expect(controller.incidents, isEmpty);
      expect(controller.isError, isTrue);
    },
  );

  // ---------------------------------------------------------------------------
  // Business actions (mock side-effects).
  //
  // Every incident fixture is a compile-time constant, so no action persists a
  // mutation: each is a toast (or, for `create`, a navigation) fired on top of
  // the const fixture. `assign` stays out of scope: it was NOT moved to the
  // controller (the assignee toggle remains view-local `setState`).
  // ---------------------------------------------------------------------------

  group('business actions', () {
    late IncidentController controller;
    late IncidentSummary incident;

    setUp(() {
      controller = Magic.findOrPut(IncidentController.new);
      incident = incidents.first;

      // MagicFeedback logs a warning when no navigator context is mounted
      // (the case here, a plain unit test); bind a LogManager so that
      // fallback path resolves instead of throwing a missing-service error.
      Magic.singleton('log', () => LogManager());
      Config.set('logging', {
        'default': 'console',
        'channels': {
          'console': {'driver': 'console', 'level': 'debug'},
        },
      });
    });

    test('resolve surfaces the resolved toast without throwing', () {
      expect(() => controller.resolve(incident), returnsNormally);
    });

    test('reopen surfaces the reopened toast without throwing', () {
      expect(() => controller.reopen(incident), returnsNormally);
    });

    test('acknowledge surfaces the acknowledged toast without throwing', () {
      expect(() => controller.acknowledge('Jordan Lee'), returnsNormally);
    });

    test('postUpdate surfaces the posted-update toast without throwing', () {
      expect(() => controller.postUpdate(incident), returnsNormally);
    });

    test(
      'editPostmortem surfaces the postmortem-edit toast without throwing',
      () {
        expect(() => controller.editPostmortem(), returnsNormally);
      },
    );
  });

  group('create()', () {
    setUp(() {
      MagicRouter.reset();
    });

    tearDown(() {
      MagicRouter.reset();
    });

    testWidgets('navigates to the incidents list without throwing', (
      tester,
    ) async {
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      MagicRoute.page('/', () => const SizedBox());
      MagicRoute.page('/incidents', () => const SizedBox());

      await tester.pumpWidget(
        MaterialApp.router(routerConfig: MagicRouter.instance.routerConfig),
      );
      await tester.pumpAndSettle();

      controller.create();
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(MagicRouter.instance.currentPath, '/incidents');
    });
  });
}
