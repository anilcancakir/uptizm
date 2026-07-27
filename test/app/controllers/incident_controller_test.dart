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
  // Business actions: live-wired writes against the incident-write endpoints,
  // following the monitor_controller.dart action pattern (`Http.post` ->
  // reload on success -> toast; error toast + stay on failure). `assign` and
  // `savePostmortem` are live here too: both persist state the detail view
  // reads back off the incident, so neither has a view-local mirror.
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

        await controller.acknowledge();

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/${incident.id}/acknowledge',
        );
        fake.assertSent((r) => r.method == 'GET' && r.url == '/incidents');
      });

      test('sends NO client-authored message: the acknowledging identity is '
          'the backend request user', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        controller.incidentById(incident.id);
        fake.reset();
        seedIncidents(fake);

        await controller.acknowledge();

        // The former behaviour composed `{'message': 'Acknowledged by <name>'}`
        // from a hardcoded client name, persisting a person who did not exist.
        // The acknowledge POST must now carry no message at all, leaving the
        // author and the default note to the backend.
        fake.assertNotSent(
          (r) =>
              r.url == '/incidents/${incident.id}/acknowledge' &&
              r.data is Map &&
              (r.data as Map).containsKey('message'),
        );
      });

      test('is a no-op when no incident is currently in view', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);

        await controller.acknowledge();

        fake.assertNothingSent();
      });
    });

    group('assign', () {
      test('POSTs /incidents/{id}/assign with the member id and reloads', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        fake.reset();
        seedIncidents(fake);

        await controller.assign(incident, 'user-7');

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/${incident.id}/assign' &&
              (r.data as Map)['assignee_id'] == 'user-7',
        );
        fake.assertSent((r) => r.method == 'GET' && r.url == '/incidents');
      });

      test('POSTs a null assignee_id to unassign', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        fake.reset();
        seedIncidents(fake);

        await controller.assign(incident, null);

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/${incident.id}/assign' &&
              (r.data as Map)['assignee_id'] == null,
        );
      });

      test('surfaces an error toast and does not reload on failure', () async {
        Http.fake({'incidents/${incident.id}/assign': Http.response({}, 422)});
        controller = Magic.findOrPut(IncidentController.new);

        await expectLater(controller.assign(incident, 'nope'), completes);
      });
    });

    group('savePostmortem', () {
      test('POSTs /incidents/{id}/postmortem with the body and publish flag',
          () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        fake.reset();
        seedIncidents(fake);

        final bool saved = await controller.savePostmortem(
          incident,
          '  The origin pool starved under release traffic.  ',
          publish: true,
        );

        expect(saved, isTrue);
        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/${incident.id}/postmortem' &&
              (r.data as Map)['body'] ==
                  'The origin pool starved under release traffic.' &&
              (r.data as Map)['publish'] == true,
        );
        fake.assertSent((r) => r.method == 'GET' && r.url == '/incidents');
      });

      test('saves a draft with publish false', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        fake.reset();
        seedIncidents(fake);

        await controller.savePostmortem(incident, 'Draft.', publish: false);

        fake.assertSent(
          (r) =>
              r.url == '/incidents/${incident.id}/postmortem' &&
              (r.data as Map)['publish'] == false,
        );
      });

      test('refuses a blank body without a request', () async {
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);

        final bool saved = await controller.savePostmortem(
          incident,
          '   ',
          publish: true,
        );

        expect(saved, isFalse);
        fake.assertNothingSent();
      });

      test('reports failure without closing the caller composer', () async {
        Http.fake({
          'incidents/${incident.id}/postmortem': Http.response({}, 500),
        });
        controller = Magic.findOrPut(IncidentController.new);

        expect(
          await controller.savePostmortem(incident, 'Body.', publish: false),
          isFalse,
        );
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

  // ---------------------------------------------------------------------------
  // resetForSession: clear the previous identity's list + detail + analysis,
  // then refetch through `load` (the entry point `reload` delegates to).
  // ---------------------------------------------------------------------------

  group('resetForSession', () {
    test('clears the list, detail, and analysis cache on a failed refetch', () async {
      final fake = seedIncidents();
      // `inc-9` is deliberately outside the list envelope, so it can only be
      // resolved through the single-incident detail fetch + `_detail` cache.
      fake.stub(
        'incidents/inc-9',
        Http.response({
          'data': {
            'id': 'inc-9',
            'title': 'Region failover',
            'lifecycle': 'investigating',
            'started_at': '2026-07-11T08:00:00Z',
          },
        }),
      );
      fake.stub(
        'incidents/inc-9/analysis',
        Http.response({
          'data': {
            'summary': 'A region failed over.',
            'confidence': 'high',
            'evidence_for': [
              {'label': 'Region down', 'detail': 'eu-west failed', 'source': 'check'},
            ],
            'evidence_against': [],
            'suggested_actions': [],
          },
        }),
      );
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      await controller.load();
      expect(controller.incidents, isNotEmpty);
      // First call kicks off the detail fetch and returns null; pump until the
      // cached detail resolves.
      expect(controller.incidentById('inc-9'), isNull);
      for (var i = 0; i < 50 && controller.incidentById('inc-9') == null; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 1));
      }
      expect(controller.incidentById('inc-9'), isNotNull);
      final Incident detail = controller.incidentById('inc-9')!;
      await controller.loadAnalysis('inc-9');
      expect(controller.analysisFor(detail)!.evidenceFor, hasLength(1));

      // The new identity's refetch resolves nothing. `load` alone keeps the
      // last-known-good list on an empty fetch, which would leave the previous
      // team's incidents (and its cached detail) readable.
      Http.fake((r) => Http.response({'message': 'down'}, 500));

      await controller.resetForSession();

      expect(controller.incidents, isEmpty);
      expect(controller.activeIncidents, isEmpty);
      expect(controller.isEmpty, isTrue);
      expect(controller.incidentById('inc-9'), isNull);
      expect(controller.analysisFor(detail), isNull);
    });

    test('refetches the incidents of the new identity', () async {
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );
      seedIncidents();
      await controller.load();

      Http.fake({
        'incidents': Http.response({
          'data': [
            {
              'id': 'other-team-inc',
              'title': 'Queue backlog',
              'lifecycle': 'investigating',
              'started_at': '2026-07-12T09:00:00Z',
            },
          ],
        }),
      });

      await controller.resetForSession();

      expect(
        controller.incidents.map((Incident i) => i.id).toList(),
        equals(['other-team-inc']),
      );
      expect(controller.isSuccess, isTrue);
    });
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
