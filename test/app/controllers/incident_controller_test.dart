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

  test('IncidentController.instance registers and returns a singleton', () {
    final IncidentController first = IncidentController.instance;
    final IncidentController second = IncidentController.instance;

    expect(identical(first, second), isTrue);
  });

  test('incidents returns every fixture incident', () {
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );

    expect(controller.incidents, equals(incidents));
  });

  test('incidentById resolves a known id via the shared fixture lookup', () {
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );

    expect(
      controller.incidentById('checkout-503'),
      equals(findIncident('checkout-503')),
    );
  });

  test('incidentById returns null for an unknown id', () {
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );

    expect(controller.incidentById('does-not-exist'), isNull);
  });

  test(
    'activeIncidents delegates to the shared not-resolved fixture filter',
    () {
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      expect(controller.activeIncidents, equals(activeIncidents));
    },
  );

  test('aiSuggestions delegates to the shared ai-payload fixture filter', () {
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );

    expect(controller.aiSuggestions, equals(aiSuggestions));
  });

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
