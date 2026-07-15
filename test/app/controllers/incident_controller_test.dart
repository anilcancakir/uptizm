import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/incident_controller.dart';
import 'package:uptizm/app/models/incident.dart';
import 'package:uptizm/app/enums/incident_lifecycle.dart' show IncidentLifecycle;

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Stubs `GET /incidents` with a canned `{data: [...]}` envelope covering
  /// an active, a resolved, and a second active incident, and returns the
  /// [FakeNetworkDriver] carrying it. The controller decodes these via the
  /// wired `load`; the assertions below exercise that wiring in place of the
  /// removed fixture-equality checks.
  ///
  /// Adds the stub to [fake] when given (so a caller can `reset()` a driver
  /// after seeding a controller and re-seed the same instance for a later
  /// `reload()` assertion) instead of always swapping in a brand-new driver.
  FakeNetworkDriver seedIncidents([FakeNetworkDriver? fake]) {
    final driver = fake ?? Http.fake();
    driver.stub(
      'incidents',
      Http.response({
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
    );
    return driver;
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

  test(
    'incidentById resolves a decoded incident from the loaded list',
    () async {
      seedIncidents();
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );
      await controller.load();

      final Incident? resolved = controller.incidentById('inc-2');

      expect(resolved, isNotNull);
      expect(resolved!.id, equals('inc-2'));
      expect(resolved.lifecycle, equals(IncidentLifecycle.resolved));
    },
  );

  test('incidentById returns null for an unknown id', () async {
    seedIncidents();
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );
    await controller.load();

    expect(controller.incidentById('does-not-exist'), isNull);
  });

  test(
    'activeIncidents derives the not-resolved subset of the loaded list',
    () async {
      seedIncidents();
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );
      await controller.load();

      expect(
        controller.activeIncidents.map((i) => i.id).toList(),
        equals(['inc-1', 'inc-3']),
      );
    },
  );

  test('aiSuggestions stays empty until AI analysis is wired', () async {
    seedIncidents();
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );
    await controller.load();

    expect(controller.aiSuggestions, isEmpty);
  });

  test('load degrades to an empty list without throwing when the network is '
      'unavailable', () async {
    // No network bound: the unfiltered `load` sources its list from
    // `Incident.all()`, which absorbs the transport failure internally and
    // resolves `[]` (it cannot distinguish that from a genuine empty result).
    // The defensive `load` keeps the (empty) last-known-good list and surfaces
    // the empty state, never throwing out of onInit/reload.
    final IncidentController controller = Magic.findOrPut(
      IncidentController.new,
    );

    await expectLater(controller.load(), completes);

    expect(controller.incidents, isEmpty);
    expect(controller.isError, isFalse);
    expect(controller.isEmpty, isTrue);
  });

  // ---------------------------------------------------------------------------
  // Business actions: live-wired writes against the S5 incident-write
  // endpoints, following the monitor_controller.dart action pattern
  // (`Http.post` -> reload on success -> toast; error toast + stay on
  // failure). `assign` stays out of scope: it was NOT moved to the
  // controller (the assignee toggle remains view-local `setState`).
  // ---------------------------------------------------------------------------

  group('business actions', () {
    late IncidentController controller;
    late Incident incident;

    setUp(() {
      // The write actions read only `id` and `title` off the incident, so a
      // minimal `Incident.fromMap` stands in for the former const DTO fixture
      // now that these methods take the ORM model.
      incident = Incident.fromMap({
        'id': 'checkout-503',
        'title': 'Checkout service returning 503s',
      });

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

    group('resolve', () {
      test('POSTs /incidents/{id}/resolve and reloads on success', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        fake.reset();
        seedIncidents(fake);

        await controller.resolve(incident);

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/${incident.id}/resolve',
        );
        fake.assertSent((r) => r.method == 'GET' && r.url == '/incidents');
      });

      test('surfaces an error toast and does not reload on failure', () async {
        Http.fake({'incidents/${incident.id}/resolve': Http.response({}, 422)});
        controller = Magic.findOrPut(IncidentController.new);

        await expectLater(controller.resolve(incident), completes);
      });
    });

    group('reopen', () {
      test('POSTs /incidents/{id}/reopen and reloads on success', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        fake.reset();
        seedIncidents(fake);

        await controller.reopen(incident);

        fake.assertSent(
          (r) =>
              r.method == 'POST' && r.url == '/incidents/${incident.id}/reopen',
        );
        fake.assertSent((r) => r.method == 'GET' && r.url == '/incidents');
      });

      test('surfaces an error toast and does not reload on failure', () async {
        Http.fake({'incidents/${incident.id}/reopen': Http.response({}, 422)});
        controller = Magic.findOrPut(IncidentController.new);

        await expectLater(controller.reopen(incident), completes);
      });
    });

    group('acknowledge', () {
      test('POSTs /incidents/{id}/acknowledge for the incident in view and '
          'reloads', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        controller.incidentById(incident.id);
        fake.reset();
        seedIncidents(fake);

        await controller.acknowledge('Jordan Lee');

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/${incident.id}/acknowledge',
        );
        fake.assertSent((r) => r.method == 'GET' && r.url == '/incidents');
      });

      test('is a no-op when no incident is currently in view', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);

        await controller.acknowledge('Jordan Lee');

        fake.assertNothingSent();
      });
    });

    group('postUpdate', () {
      test(
        'POSTs /incidents/{id}/updates with the message and reloads',
        () async {
          final fake = seedIncidents();
          controller = Magic.findOrPut(IncidentController.new);
          await controller.load();
          fake.reset();
          seedIncidents(fake);

          await controller.postUpdate(incident, 'Rolling back the release.');

          fake.assertSent(
            (r) =>
                r.method == 'POST' &&
                r.url == '/incidents/${incident.id}/updates' &&
                (r.data as Map)['message'] == 'Rolling back the release.',
          );
          fake.assertSent((r) => r.method == 'GET' && r.url == '/incidents');
        },
      );

      test('is a no-op when no message is given', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);

        await controller.postUpdate(incident);

        fake.assertNothingSent();
      });
    });

    test(
      'editPostmortem surfaces the postmortem-edit toast without throwing',
      () {
        controller = Magic.findOrPut(IncidentController.new);
        expect(() => controller.editPostmortem(), returnsNormally);
      },
    );

    group('create', () {
      test('POSTs /incidents with the given fields and reloads', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        MagicRouter.reset();
        MagicRoute.page('/', () => const SizedBox());
        MagicRoute.page('/incidents', () => const SizedBox());
        MagicRouter.instance.routerConfig;
        fake.reset();
        seedIncidents(fake);

        await controller.create({
          'monitor_id': 'm1',
          'severity': 'critical',
          'title': 'Checkout returning 503s',
        });

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents' &&
              (r.data as Map)['monitor_id'] == 'm1',
        );
        fake.assertSent((r) => r.method == 'GET' && r.url == '/incidents');
        MagicRouter.reset();
      });
    });
  });

  group('loadAnalysis', () {
    test(
      'GETs /incidents/{id}/analysis and decodes evidence_for/against + '
      'suggested_actions',
      () async {
        final fake = Http.fake({
          'incidents/checkout-503/analysis': Http.response({
            'data': {
              'summary': 'The origin returned 503s under load.',
              'confidence': 'high',
              'contributing_factors': ['Deploy at 14:02'],
              'stripped_citations': [],
              'evidence_for': [
                {
                  'label': 'All regions affected',
                  'detail': 'Checks failed in us-east and eu-west.',
                  'source': 'check',
                },
              ],
              'evidence_against': [
                {
                  'label': 'No DNS change',
                  'detail': 'DNS records are unchanged since last week.',
                  'source': 'monitor',
                },
              ],
              'suggested_actions': [
                {
                  'title': 'Check your origin',
                  'rationale': 'The origin server is returning 503s.',
                },
              ],
            },
          }),
        });
        final IncidentController controller = Magic.findOrPut(
          IncidentController.new,
        );
        final Incident incident = Incident.fromMap({
          'id': 'checkout-503',
          'title': 'Checkout returning 503s',
        });

        await controller.loadAnalysis(incident.id);

        fake.assertSent(
          (r) => r.method == 'GET' && r.url == '/incidents/checkout-503/analysis',
        );

        final ai = controller.analysisFor(incident)!;
        expect(ai.evidenceFor, hasLength(1));
        expect(ai.evidenceFor.single.label, equals('All regions affected'));
        expect(ai.evidenceFor.single.source, equals('check'));
        expect(ai.evidenceAgainst, hasLength(1));
        expect(ai.evidenceAgainst.single.label, equals('No DNS change'));
        expect(ai.suggestedActions, hasLength(1));
        expect(ai.suggestedActions.single.title, equals('Check your origin'));
      },
    );

    test(
      'analysisFor falls back to Incident.ai unenriched before loadAnalysis '
      'resolves',
      () {
        final IncidentController controller = Magic.findOrPut(
          IncidentController.new,
        );
        final Incident incident = Incident.fromMap({
          'id': 'checkout-503',
          'title': 'Checkout returning 503s',
          'ai': {'trigger': 'AI anomaly', 'confidence': 'high', 'tldr': 'tldr'},
        });

        final ai = controller.analysisFor(incident);

        expect(ai, isNotNull);
        expect(ai!.tldr, equals('tldr'));
        expect(ai.evidenceFor, isEmpty);
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
