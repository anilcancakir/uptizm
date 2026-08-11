import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/incident_controller.dart';
import 'package:uptizm/app/models/incident.dart';
import 'package:uptizm/app/enums/ai_degrade_reason.dart'
    show AiDegradeReason, aiDegradeReasonFromWire;
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

  group('isFirstLoadFor', () {
    // What these pin: `incidentById` returning null means two different things,
    // and the detail screen chooses between a skeleton and "this incident does
    // not exist" on the difference. Before this flag existed it always chose
    // not-found, so a deep link from an alert opened on an error for an incident
    // that was merely still being fetched.

    test('a lookup whose detail fetch is still in flight reads as pending', () {
      // No stub for `incidents/inc-9`, so the fetch this lookup kicks off never
      // completes within the synchronous window below.
      Http.fake();
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      expect(controller.incidentById('inc-9'), isNull);
      expect(controller.isFirstLoadFor('inc-9'), isTrue);
    });

    test('an id already in the roster is answered, never pending', () async {
      seedIncidents();
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );
      await controller.load();

      expect(controller.incidentById('inc-1'), isNotNull);
      expect(controller.isFirstLoadFor('inc-1'), isFalse);
    });

    test('a failed detail fetch settles, so the screen stops waiting', () async {
      // The sharp edge of the whole change: a failure that left the lookup
      // unsettled would skeleton forever with nothing in flight to end it, which
      // is worse than the not-found it replaced.
      final FakeNetworkDriver driver = Http.fake();
      driver.stub('incidents/inc-404', Http.response(<String, dynamic>{}, 404));
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      expect(controller.incidentById('inc-404'), isNull);
      await Future<void>.delayed(Duration.zero);

      expect(controller.incidentById('inc-404'), isNull);
      expect(
        controller.isFirstLoadFor('inc-404'),
        isFalse,
        reason: 'a read that failed has still answered',
      );
    });

    test('a null id waits for nothing', () {
      Http.fake();
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      expect(controller.isFirstLoadFor(null), isFalse);
    });
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

          await controller.postUpdate(
            incident,
            message: 'Rolling back the release.',
          );

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

      test('sends is_public: false for an internal-only note', () async {
        // The load-bearing case. The backend resolves an ABSENT `is_public` as
        // `true` (`IncidentController::postUpdate` ->
        // `validated('is_public', true)`), so omitting the key publishes the
        // note to the public status page. An operator who turned the composer's
        // publish switch off asked for the opposite, which makes a dropped
        // `is_public` a confidentiality leak rather than a cosmetic default.
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        fake.reset();
        seedIncidents(fake);

        await controller.postUpdate(
          incident,
          message: 'Internal: rotating the leaked key.',
          isPublic: false,
        );

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/${incident.id}/updates' &&
              (r.data as Map)['is_public'] == false,
        );
      });

      test('sends the composer status as its wire token', () async {
        // The update row is STAMPED with this status
        // (`IncidentWriteService::appendUpdate`), so a dropped status silently
        // relabels the entry with the incident's current lifecycle instead of
        // the one the operator picked.
        final fake = seedIncidents();
        controller = Magic.findOrPut(IncidentController.new);
        await controller.load();
        fake.reset();
        seedIncidents(fake);

        await controller.postUpdate(
          incident,
          message: 'Failover complete, watching error rates.',
          status: IncidentLifecycle.monitoring,
        );

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url == '/incidents/${incident.id}/updates' &&
              (r.data as Map)['status'] == 'monitoring',
        );
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

  group('aiDegradeReasonFromWire', () {
    test('null is nothing degraded, not a reason', () {
      expect(aiDegradeReasonFromWire(null), isNull);
    });

    test('each backend snake_case value decodes to its case', () {
      expect(
        aiDegradeReasonFromWire('budget_exhausted'),
        equals(AiDegradeReason.budgetExhausted),
      );
      expect(
        aiDegradeReasonFromWire('output_untrusted'),
        equals(AiDegradeReason.outputUntrusted),
      );
      expect(
        aiDegradeReasonFromWire('service_unreachable'),
        equals(AiDegradeReason.serviceUnreachable),
      );
    });

    test('an unknown value reads as the most conservative reason', () {
      // A value the client does not know still means the backend degraded, so it
      // must not vanish into "nothing happened".
      expect(
        aiDegradeReasonFromWire('something_new'),
        equals(AiDegradeReason.serviceUnreachable),
      );
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

    test('decodes the degrade_reason the backend answered with', () async {
      // The reason is what lets the client say WHY the summary is a baseline in
      // the operator's own language; dropped here, the screen can only render
      // the backend's English prose.
      Http.fake({
        'incidents/deg-1/analysis': Http.response({
          'data': {
            'summary': 'critical severity incident, currently investigating.',
            'confidence': 'low',
            'degrade_reason': 'budget_exhausted',
          },
        }),
      });
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );
      final Incident incident = Incident.fromMap({'id': 'deg-1'});

      await controller.loadAnalysis(incident.id);

      expect(
        controller.analysisFor(incident)!.degradeReason,
        equals(AiDegradeReason.budgetExhausted),
      );
    });

    test('reports the fetch as pending while it runs, and clears it after', () async {
      // What this protects: the degraded section's retry disables itself on
      // `analysisPending`, and the request behind it re-asks the model, which can
      // take the better part of a minute. A flag that never clears would leave the
      // retry dead for the rest of the screen's life; one that never sets would
      // let a second tap spend a second AI budget unit on the same answer.
      Http.fake({
        'incidents/pending-1/analysis': Http.response({
          'data': {'summary': 'ok', 'confidence': 'high'},
        }),
      });
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      expect(controller.analysisPending('pending-1'), isFalse);

      // Deliberately NOT awaited: the flag only exists during the gap between
      // the call and its answer, so awaiting first would test nothing.
      final Future<void> inFlight = controller.loadAnalysis('pending-1');
      expect(controller.analysisPending('pending-1'), isTrue);
      expect(
        controller.analysisPending('another-incident'),
        isFalse,
        reason: 'the flag is per incident, not global',
      );

      await inFlight;

      expect(controller.analysisPending('pending-1'), isFalse);
    });

    test('clears the pending flag when the request fails', () async {
      // The `finally` path. A 500 (or a stray-request throw, or a malformed
      // body) must still release the retry, otherwise the one state where an
      // operator most wants to retry is the one where the button stays dead.
      Http.fake({
        'incidents/pending-2/analysis': Http.response({
          'message': 'Server Error',
        }, 500),
      });
      // The failure path logs, so the `log` service has to resolve; without it
      // the throw inside the catch would mask what this test is about.
      Magic.singleton('log', () => LogManager());
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      await controller.retryAnalysis('pending-2');

      expect(controller.analysisPending('pending-2'), isFalse);
      expect(controller.analysisFor(Incident.fromMap({'id': 'pending-2'})), isNull);
    });

    test('one incident finishing does not release another one still running', () async {
      // THE DEFECT THIS PINS. The flag used to be a single slot cleared
      // unconditionally, so: open A (a minute-long request), go back, open B, and
      // A's answer released B's retry while B's request was still in flight. The
      // next tap spent a second AI budget unit on a request already running.
      Http.fake({
        'incidents/slow-a/analysis': Http.response({
          'data': {'summary': 'a', 'confidence': 'low'},
        }),
        'incidents/slow-b/analysis': Http.response({
          'data': {'summary': 'b', 'confidence': 'low'},
        }),
      });
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      final Future<void> a = controller.loadAnalysis('slow-a');
      final Future<void> b = controller.loadAnalysis('slow-b');
      expect(controller.analysisPending('slow-a'), isTrue);
      expect(controller.analysisPending('slow-b'), isTrue);

      await a;

      expect(
        controller.analysisPending('slow-b'),
        isTrue,
        reason: "A finishing must not speak for B's request",
      );

      await b;

      expect(controller.analysisPending('slow-b'), isFalse);
    });

    test('a second call for an id already in flight is dropped', () async {
      // The disabled button is a courtesy, not the mechanism: a remount inside
      // the request window would fire again, and every request spends a unit.
      final fake = Http.fake({
        'incidents/once/analysis': Http.response({
          'data': {'summary': 'once', 'confidence': 'low'},
        }),
      });
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      final Future<void> first = controller.loadAnalysis('once');
      await controller.retryAnalysis('once');
      await first;

      expect(
        fake.recorded
            .where((entry) => entry.$1.url == '/incidents/once/analysis')
            .length,
        1,
      );
    });

    test('a cached model-authored analysis is not re-fetched on a remount', () async {
      // The endpoint recomputes and spends an AI budget unit per call, and the
      // detail view fetches from `initState`, so a screen reopened three times
      // used to pay three times for the same answer.
      final fake = Http.fake({
        'incidents/cached/analysis': Http.response({
          'data': {'summary': 'The origin returned 503s.', 'confidence': 'high'},
        }),
      });
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      await controller.loadAnalysis('cached');
      await controller.loadAnalysis('cached');

      int calls() => fake.recorded
          .where((entry) => entry.$1.url == '/incidents/cached/analysis')
          .length;
      expect(calls(), 1);

      // The retry is the explicit exception: it always asks again.
      await controller.retryAnalysis('cached');
      expect(calls(), 2);
    });

    test('a cached DEGRADE is re-fetched, because that answer is worth re-asking', () async {
      final fake = Http.fake({
        'incidents/degraded-cache/analysis': Http.response({
          'data': {
            'summary': 'critical severity incident, currently investigating.',
            'confidence': 'low',
            'degrade_reason': 'service_unreachable',
          },
        }),
      });
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      await controller.loadAnalysis('degraded-cache');
      await controller.loadAnalysis('degraded-cache');

      expect(
        fake.recorded
            .where((entry) => entry.$1.url == '/incidents/degraded-cache/analysis')
            .length,
        2,
      );
    });

    test('a retry against a malformed payload reports rather than going quiet', () async {
      // The last silent exit. A 200 whose body is not the shape we asked for used
      // to return with no log and no toast, so an operator-initiated re-ask looked
      // identical to a dead button, which is the defect class this whole change
      // is about.
      Http.fake({
        'incidents/malformed/analysis': Http.response({'unexpected': 'shape'}),
      });
      Magic.singleton('log', () => LogManager());
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );

      await controller.retryAnalysis('malformed');

      expect(controller.analysisPending('malformed'), isFalse);
      expect(
        controller.analysisFor(Incident.fromMap({'id': 'malformed'})),
        isNull,
        reason: 'a shape we cannot read must not become a rendered analysis',
      );
    });

    test('a null or absent degrade_reason means nothing degraded', () async {
      // Both shapes, because the two are different states everywhere else in
      // this app: the endpoint always SENDS the key (null on the model path),
      // while any other analysis payload omits it entirely.
      Http.fake({
        'incidents/deg-2/analysis': Http.response({
          'data': {
            'summary': 'The origin returned 503s under load.',
            'confidence': 'high',
            'degrade_reason': null,
          },
        }),
        'incidents/deg-2b/analysis': Http.response({
          'data': {
            'summary': 'The origin returned 503s under load.',
            'confidence': 'high',
          },
        }),
      });
      final IncidentController controller = Magic.findOrPut(
        IncidentController.new,
      );
      final Incident explicitNull = Incident.fromMap({'id': 'deg-2'});
      final Incident absent = Incident.fromMap({'id': 'deg-2b'});

      await controller.loadAnalysis(explicitNull.id);
      await controller.loadAnalysis(absent.id);

      expect(controller.analysisFor(explicitNull)!.degradeReason, isNull);
      expect(controller.analysisFor(absent)!.degradeReason, isNull);
    });

    test(
      'the fetched analysis wins wholesale over whatever the incident carried',
      () async {
        // What this pins: the fetch is taken as a WHOLE object, so no field can
        // be dropped by a later addition. Both reasons are non-null and
        // different on purpose: with a null base, or with two equal values,
        // either precedence answers the same and the test could not tell them
        // apart. The base shape here is the dashboard AI-suggestion one, which
        // is the only producer of a non-null `Incident.ai`.
        Http.fake({
          'incidents/deg-3/analysis': Http.response({
            'data': {
              'summary': 'critical severity incident, currently resolved.',
              'confidence': 'low',
              'degrade_reason': 'service_unreachable',
            },
          }),
        });
        final IncidentController controller = Magic.findOrPut(
          IncidentController.new,
        );
        final Incident incident = Incident.fromMap({
          'id': 'deg-3',
          'ai': {
            'trigger': 'AI anomaly',
            'confidence': 'low',
            'tldr': 'A baseline from the incident record.',
            'degrade_reason': 'budget_exhausted',
          },
        });
        expect(
          incident.ai!.degradeReason,
          equals(AiDegradeReason.budgetExhausted),
          reason: 'the first-paint decode is the only route to a non-null base',
        );

        await controller.loadAnalysis(incident.id);

        final resolved = controller.analysisFor(incident)!;
        expect(resolved.degradeReason, equals(AiDegradeReason.serviceUnreachable));
        expect(
          resolved.tldr,
          equals('critical severity incident, currently resolved.'),
          reason:
              'the whole object comes from the fetch, so the first paint cannot '
              'hold any field back',
        );
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
