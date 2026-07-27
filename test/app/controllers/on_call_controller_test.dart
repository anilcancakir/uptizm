import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/on_call_controller.dart';
import 'package:uptizm/app/support/team_types.dart'
    show OnCallOverrideWindow, OnCallRotationSlot, TeamResponder;

/// A tiny stateful stand-in for the `api/v1/on-call/*` surface.
///
/// The pattern-based `Http.fake` map returns one canned response per URL, which
/// cannot express "the list changed because of the write that just ran". This
/// fake owns mutable schedule rows instead, so every write mutates it and the
/// controller's own post-write re-read observes exactly what the real API would
/// have returned. That is the contract the on-call screen must hold: after a
/// write, what is in the controller equals what the API would answer.
class _FakeOnCallApi {
  _FakeOnCallApi({
    List<Map<String, dynamic>>? schedules,
    Map<String, Map<String, dynamic>?>? responders,
  }) : schedules = schedules ?? [],
       responders = responders ?? {};

  /// The team's schedules as `OnCallScheduleResource` maps, rotations and
  /// overrides eager-loaded (the shape the index now returns).
  final List<Map<String, dynamic>> schedules;

  /// The `GET /on-call/current` answer per schedule id: the resolved user map,
  /// or `null` for "nobody is on call".
  final Map<String, Map<String, dynamic>?> responders;

  /// When set, every `GET /on-call/schedules` answers with this status.
  int? indexStatus;

  /// When set, every `GET /on-call/current` answers with this status.
  int? currentStatus;

  /// When set, every write answers with this status.
  int? writeStatus;

  /// Auto-incrementing suffix for ids this fake mints.
  int _sequence = 0;

  String _nextId(String prefix) => '$prefix-${++_sequence}';

  /// Routes one recorded request to its response.
  MagicResponse handle(MagicRequest request) {
    final String path = request.url.startsWith('/')
        ? request.url.substring(1)
        : request.url;
    final List<String> segments = path.split('/');

    if (request.method == 'GET' && path == 'on-call/current') {
      if (currentStatus != null) {
        return Http.response({'message': 'boom'}, currentStatus!);
      }
      final String? scheduleId = request.queryParameters?['schedule_id'] as String?;
      return Http.response({
        'data': {
          'schedule_id': scheduleId,
          'user': responders[scheduleId],
        },
      }, 200);
    }

    if (request.method == 'GET' && path == 'on-call/schedules') {
      if (indexStatus != null) {
        return Http.response({'message': 'boom'}, indexStatus!);
      }
      return Http.response({'data': schedules}, 200);
    }

    if (writeStatus != null) {
      return Http.response({'message': 'Validation failed'}, writeStatus!);
    }

    if (request.method == 'POST' && path == 'on-call/schedules') {
      final Map<String, dynamic> body = request.data as Map<String, dynamic>;
      final Map<String, dynamic> created = {
        'id': _nextId('sched'),
        'name': body['name'],
        'timezone': body['timezone'],
      };
      // Mirrors the backend `store()`, whose relations were never loaded: the
      // created row carries no `rotations`/`overrides` key at all.
      schedules.add({...created, 'rotations': <dynamic>[], 'overrides': <dynamic>[]});
      return Http.response({'data': created}, 201);
    }

    // on-call/schedules/{id}/rotations[/{rotationId}|/reorder]
    // on-call/schedules/{id}/overrides[/{overrideId}]
    if (segments.length >= 4 && segments[0] == 'on-call') {
      final Map<String, dynamic>? schedule = _schedule(segments[2]);
      if (schedule == null) return Http.response({'message': 'Not found'}, 404);

      final String collection = segments[3];
      final String? childId = segments.length > 4 ? segments[4] : null;

      if (request.method == 'POST' && childId == null) {
        final Map<String, dynamic> body = request.data as Map<String, dynamic>;
        (schedule[collection] as List).add({
          'id': _nextId(collection == 'rotations' ? 'rot' : 'ov'),
          'user_name': 'Server Name',
          ...body,
        });
        return Http.response({'data': schedule}, 201);
      }

      if (request.method == 'PUT' && childId == 'reorder') {
        final List<dynamic> order = (request.data as Map<String, dynamic>)['order'] as List;
        for (final dynamic row in order) {
          final Map<String, dynamic> entry = row as Map<String, dynamic>;
          for (final dynamic slot in schedule['rotations'] as List) {
            final Map<String, dynamic> current = slot as Map<String, dynamic>;
            if (current['id'] == entry['id']) current['position'] = entry['position'];
          }
        }
        (schedule['rotations'] as List).sort(
          (a, b) => ((a as Map)['position'] as int).compareTo(
            (b as Map)['position'] as int,
          ),
        );
        return Http.response(null, 204);
      }

      if (request.method == 'DELETE' && childId != null) {
        (schedule[collection] as List).removeWhere(
          (dynamic row) => (row as Map)['id'] == childId,
        );
        return Http.response(null, 204);
      }
    }

    return Http.response({'message': 'Not found'}, 404);
  }

  Map<String, dynamic>? _schedule(String id) {
    for (final Map<String, dynamic> schedule in schedules) {
      if (schedule['id'] == id) return schedule;
    }
    return null;
  }
}

/// One eager-loaded schedule row, in the index payload's shape.
Map<String, dynamic> _scheduleRow({
  String id = 'sched-1',
  List<Map<String, dynamic>> rotations = const [],
  List<Map<String, dynamic>> overrides = const [],
}) {
  return {
    'id': id,
    'team_id': 'team-1',
    'name': 'Primary',
    'timezone': 'UTC',
    'rotations': [...rotations],
    'overrides': [...overrides],
  };
}

/// One rotation row, in the wire shape.
Map<String, dynamic> _rotationRow({
  required String id,
  required String userId,
  String? userName,
  int position = 0,
  int shiftHours = 24,
}) {
  return {
    'id': id,
    'user_id': userId,
    'user_name': userName,
    'position': position,
    'shift_hours': shiftHours,
  };
}

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.warning() works inside MagicFeedback.showSnackbar
    // (the write actions call Magic.success/Magic.error, which fall through to
    // a warning log when no navigator context is mounted, as here).
    Magic.singleton('log', () => LogManager());
    Http.fake();
    // Force-build the lazy GoRouter, which also initializes the
    // WidgetsBinding that MagicFeedback's snackbar lookup depends on. Without
    // this, Magic.success/Magic.error throw "Binding has not yet been
    // initialized" instead of degrading to a logged warning, mirroring
    // `status_page_controller_test.dart`'s precedent.
    MagicRouter.instance.routerConfig;
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Installs [api] as the network driver and returns the recording fake.
  FakeNetworkDriver install(_FakeOnCallApi api) => Http.fake(api.handle);

  test('OnCallController.instance registers and returns a singleton', () {
    final OnCallController first = OnCallController.instance;
    final OnCallController second = OnCallController.instance;

    expect(identical(first, second), isTrue);
  });

  // ---------------------------------------------------------------------------
  // reload: GET /on-call/schedules (rotations + overrides eager-loaded) and
  // GET /on-call/current (the server-resolved responder).
  // ---------------------------------------------------------------------------

  group('reload', () {
    test('hydrates the ring, the overrides, and the resolved responder', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [
          _scheduleRow(
            rotations: [
              _rotationRow(
                id: 'rot-1',
                userId: 'u2',
                userName: 'Real Person',
                shiftHours: 48,
              ),
            ],
            overrides: [
              {
                'id': 'ov-1',
                'user_id': 'u4',
                'user_name': 'Cover Person',
                'starts_at': '2026-07-12T00:00:00Z',
                'ends_at': '2026-07-13T00:00:00Z',
              },
            ],
          ),
        ],
        responders: {
          'sched-1': {'id': 'u2', 'name': 'Real Person', 'email': 'real@acme.test'},
        },
      );
      final fake = install(api);

      final OnCallController controller = OnCallController.instance;
      await controller.reload();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('on-call/schedules'),
      );
      fake.assertSent(
        (r) =>
            r.method == 'GET' &&
            r.url.contains('on-call/current') &&
            r.queryParameters?['schedule_id'] == 'sched-1',
      );
      // No per-schedule detail round trip: the index carries the full ring.
      fake.assertNotSent(
        (r) => r.method == 'GET' && r.url.contains('on-call/schedules/sched-1'),
      );

      expect(controller.phase, OnCallPhase.ready);
      expect(controller.scheduleId, 'sched-1');
      expect(controller.rotation.single.id, 'rot-1');
      expect(controller.rotation.single.userName, 'Real Person');
      expect(controller.rotation.single.shiftHours, 48);
      expect(controller.overrides.single.id, 'ov-1');
      expect(controller.currentResponder?.name, 'Real Person');
      expect(controller.rotationIdFor('u2'), 'rot-1');
    });

    test('orders the ring by position', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [
          _scheduleRow(
            rotations: [
              _rotationRow(id: 'rot-b', userId: 'u3', position: 1),
              _rotationRow(id: 'rot-a', userId: 'u2', position: 0),
            ],
          ),
        ],
        responders: {'sched-1': null},
      );
      install(api);

      await OnCallController.instance.reload();

      expect(
        OnCallController.instance.rotation.map((s) => s.id).toList(),
        ['rot-a', 'rot-b'],
      );
    });

    test('publishes the empty phase when the team has no schedule', () async {
      install(_FakeOnCallApi());

      await OnCallController.instance.reload();

      expect(OnCallController.instance.phase, OnCallPhase.empty);
      expect(OnCallController.instance.scheduleId, isNull);
      expect(OnCallController.instance.rotation, isEmpty);
    });

    test('publishes the error phase (never "empty") on a failed index', () async {
      install(_FakeOnCallApi()..indexStatus = 500);

      await OnCallController.instance.reload();

      expect(OnCallController.instance.phase, OnCallPhase.error);
      expect(OnCallController.instance.rotation, isEmpty);
    });

    test('publishes the error phase on a transport failure', () async {
      Http.unfake();

      await expectLater(
        () => OnCallController.instance.reload(),
        returnsNormally,
      );
      expect(OnCallController.instance.phase, OnCallPhase.error);
    });

    test('keeps a null responder when the server resolves nobody', () async {
      install(
        _FakeOnCallApi(
          schedules: [_scheduleRow()],
          responders: {'sched-1': null},
        ),
      );

      await OnCallController.instance.reload();

      expect(OnCallController.instance.phase, OnCallPhase.ready);
      expect(OnCallController.instance.currentResponder, isNull);
      expect(OnCallController.instance.activeOverride, isNull);
    });

    test(
      'fails the whole read when the responder resolve fails, rather than '
      'reading as nobody on call',
      () async {
        install(
          _FakeOnCallApi(schedules: [_scheduleRow()])..currentStatus = 500,
        );

        await OnCallController.instance.reload();

        expect(OnCallController.instance.phase, OnCallPhase.error);
        expect(OnCallController.instance.currentResponder, isNull);
      },
    );

    test('resolves the override covering now for the current responder', () async {
      final DateTime now = DateTime.now().toUtc();
      install(
        _FakeOnCallApi(
          schedules: [
            _scheduleRow(
              overrides: [
                {
                  'id': 'ov-live',
                  'user_id': 'u4',
                  'user_name': 'Cover Person',
                  'starts_at': now
                      .subtract(const Duration(hours: 1))
                      .toIso8601String(),
                  'ends_at': now.add(const Duration(hours: 1)).toIso8601String(),
                },
              ],
            ),
          ],
          responders: {
            'sched-1': {'id': 'u4', 'name': 'Cover Person'},
          },
        ),
      );

      await OnCallController.instance.reload();

      expect(OnCallController.instance.activeOverride?.id, 'ov-live');
    });
  });

  // ---------------------------------------------------------------------------
  // createSchedule: the empty state's action.
  // ---------------------------------------------------------------------------

  group('createSchedule', () {
    test('POSTs a schedule and re-reads into the ready phase', () async {
      final _FakeOnCallApi api = _FakeOnCallApi();
      final fake = install(api);

      final bool ok = await OnCallController.instance.createSchedule();

      expect(ok, isTrue);
      fake.assertSent(
        (r) => r.method == 'POST' && r.url.contains('on-call/schedules'),
      );
      expect(OnCallController.instance.phase, OnCallPhase.ready);
      expect(OnCallController.instance.scheduleId, isNotNull);
      // A freshly created schedule genuinely has an empty ring and nobody on
      // call; that must publish as-is, not as a fabricated rotation.
      expect(OnCallController.instance.rotation, isEmpty);
      expect(OnCallController.instance.currentResponder, isNull);
    });

    test('returns false and stays empty when the create fails', () async {
      install(_FakeOnCallApi()..writeStatus = 422);

      final bool ok = await OnCallController.instance.createSchedule();

      expect(ok, isFalse);
      expect(OnCallController.instance.scheduleId, isNull);
    });
  });

  // ---------------------------------------------------------------------------
  // addToRotation
  // ---------------------------------------------------------------------------

  group('addToRotation', () {
    test('POSTs the slot then re-reads the API state', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [_scheduleRow()],
        responders: {'sched-1': null},
      );
      final fake = install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();

      final bool ok = await controller.addToRotation(
        const TeamResponder(id: 'u3', name: 'Ravi Real'),
      );

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url == '/on-call/schedules/sched-1/rotations' &&
            (r.data as Map)['user_id'] == 'u3' &&
            (r.data as Map)['position'] == 0 &&
            (r.data as Map)['shift_hours'] == 24,
      );
      // The ring on screen is the server's, including the `user_name` the
      // server resolved (not the name the picker happened to show).
      expect(controller.rotation.single.userId, 'u3');
      expect(controller.rotation.single.userName, 'Server Name');
    });

    test('appends at the end of the ring', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [
          _scheduleRow(
            rotations: [_rotationRow(id: 'rot-1', userId: 'u2', position: 0)],
          ),
        ],
        responders: {'sched-1': null},
      );
      final fake = install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();

      await controller.addToRotation(
        const TeamResponder(id: 'u3', name: 'Ravi Real'),
      );

      fake.assertSent(
        (r) => r.method == 'POST' && (r.data as Map)['position'] == 1,
      );
      expect(controller.rotation.length, 2);
    });

    test('returns false and leaves the ring untouched on a 422', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [_scheduleRow()],
        responders: {'sched-1': null},
      );
      install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();
      api.writeStatus = 422;

      final bool ok = await controller.addToRotation(
        const TeamResponder(id: 'u3', name: 'Ravi Real'),
      );

      expect(ok, isFalse);
      expect(controller.rotation, isEmpty);
    });
  });

  // ---------------------------------------------------------------------------
  // removeFromRotation
  // ---------------------------------------------------------------------------

  group('removeFromRotation', () {
    test('DELETEs the slot then re-reads the API state', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [
          _scheduleRow(
            rotations: [
              _rotationRow(id: 'rot-1', userId: 'u2', position: 0),
              _rotationRow(id: 'rot-2', userId: 'u3', position: 1),
            ],
          ),
        ],
        responders: {'sched-1': null},
      );
      final fake = install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();

      final OnCallRotationSlot slot = controller.rotation.last;
      final bool ok = await controller.removeFromRotation(slot);

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url == '/on-call/schedules/sched-1/rotations/rot-2',
      );
      expect(controller.rotation.map((s) => s.id).toList(), ['rot-1']);
    });

    test('returns false without a request when no schedule is loaded', () async {
      final fake = install(_FakeOnCallApi());

      final bool ok = await OnCallController.instance.removeFromRotation(
        const OnCallRotationSlot(
          id: 'rot-1',
          userId: 'u2',
          userName: 'Real Person',
          position: 0,
          shiftHours: 24,
        ),
      );

      expect(ok, isFalse);
      fake.assertNotSent((r) => r.method == 'DELETE');
    });
  });

  // ---------------------------------------------------------------------------
  // reorderRotation
  // ---------------------------------------------------------------------------

  group('reorderRotation', () {
    test('PUTs the new order then re-reads the API state', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [
          _scheduleRow(
            rotations: [
              _rotationRow(id: 'rot-1', userId: 'u2', position: 0),
              _rotationRow(id: 'rot-2', userId: 'u3', position: 1),
            ],
          ),
        ],
        responders: {'sched-1': null},
      );
      final fake = install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();

      final bool ok = await controller.reorderRotation(
        controller.rotation.reversed.toList(),
      );

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'PUT' &&
            r.url == '/on-call/schedules/sched-1/rotations/reorder' &&
            ((r.data as Map)['order'] as List).first['id'] == 'rot-2',
      );
      expect(controller.rotation.map((s) => s.id).toList(), ['rot-2', 'rot-1']);
    });

    test('returns false without a request for a single-slot ring', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [
          _scheduleRow(
            rotations: [_rotationRow(id: 'rot-1', userId: 'u2')],
          ),
        ],
        responders: {'sched-1': null},
      );
      final fake = install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();

      final bool ok = await controller.reorderRotation(controller.rotation);

      expect(ok, isFalse);
      fake.assertNotSent((r) => r.method == 'PUT');
    });
  });

  // ---------------------------------------------------------------------------
  // addOverride / removeOverride
  // ---------------------------------------------------------------------------

  group('overrides', () {
    test('addOverride POSTs the window and re-reads the responder', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [_scheduleRow()],
        responders: {'sched-1': null},
      );
      final fake = install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();
      // The server hands the pager to the override target on the next resolve.
      api.responders['sched-1'] = {'id': 'u4', 'name': 'Cover Person'};

      final bool ok = await controller.addOverride(
        const TeamResponder(id: 'u4', name: 'Cover Person'),
      );

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url == '/on-call/schedules/sched-1/overrides' &&
            (r.data as Map)['user_id'] == 'u4' &&
            (r.data as Map)['starts_at'] != null &&
            (r.data as Map)['ends_at'] != null,
      );
      expect(controller.overrides.single.userId, 'u4');
      expect(controller.currentResponder?.id, 'u4');
    });

    test('addOverride returns false and changes nothing on a 422', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [_scheduleRow()],
        responders: {'sched-1': null},
      );
      install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();
      api.writeStatus = 422;

      final bool ok = await controller.addOverride(
        const TeamResponder(id: 'u4', name: 'Cover Person'),
      );

      expect(ok, isFalse);
      expect(controller.overrides, isEmpty);
      expect(controller.currentResponder, isNull);
    });

    test('removeOverride DELETEs the window then re-reads', () async {
      final _FakeOnCallApi api = _FakeOnCallApi(
        schedules: [
          _scheduleRow(
            overrides: [
              {
                'id': 'ov-1',
                'user_id': 'u4',
                'user_name': 'Cover Person',
                'starts_at': '2026-07-12T00:00:00Z',
                'ends_at': '2026-07-13T00:00:00Z',
              },
            ],
          ),
        ],
        responders: {'sched-1': null},
      );
      final fake = install(api);
      final OnCallController controller = OnCallController.instance;
      await controller.reload();

      final OnCallOverrideWindow window = controller.overrides.single;
      final bool ok = await controller.removeOverride(window);

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url == '/on-call/schedules/sched-1/overrides/ov-1',
      );
      expect(controller.overrides, isEmpty);
    });

    test('degrades gracefully (no throw) when a write blows up', () async {
      install(
        _FakeOnCallApi(
          schedules: [_scheduleRow()],
          responders: {'sched-1': null},
        ),
      );
      await OnCallController.instance.reload();

      Http.fake((request) => Http.response({'message': 'unavailable'}, 500));

      await expectLater(
        () => OnCallController.instance.addOverride(
          const TeamResponder(id: 'u4', name: 'Cover Person'),
        ),
        returnsNormally,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // resetForSession: clear the previous identity's schedule state, then refetch.
  // ---------------------------------------------------------------------------

  group('resetForSession', () {
    test('clears every cached field on a failed refetch', () async {
      install(
        _FakeOnCallApi(
          schedules: [
            _scheduleRow(
              rotations: [_rotationRow(id: 'rot-1', userId: 'u2')],
            ),
          ],
          responders: {
            'sched-1': {'id': 'u2', 'name': 'Real Person'},
          },
        ),
      );
      final OnCallController controller = OnCallController.instance;
      await controller.reload();
      expect(controller.scheduleId, 'sched-1');

      // The new identity's refetch fails. A stale schedule id is worse than a
      // missing one: every write action targets it, so the previous team's
      // schedule would keep receiving this team's rotations and overrides.
      Http.fake((r) => Http.response({'message': 'down'}, 500));
      var notifications = 0;
      controller.addListener(() => notifications++);

      await controller.resetForSession();

      expect(notifications, greaterThan(0));
      expect(controller.scheduleId, isNull);
      expect(controller.rotation, isEmpty);
      expect(controller.overrides, isEmpty);
      expect(controller.currentResponder, isNull);
      expect(controller.rotationIdFor('u2'), isNull);
      expect(controller.phase, OnCallPhase.error);
    });

    test('refetches the schedule of the new identity', () async {
      install(
        _FakeOnCallApi(
          schedules: [
            _scheduleRow(
              rotations: [_rotationRow(id: 'rot-1', userId: 'u2')],
            ),
          ],
          responders: {'sched-1': null},
        ),
      );
      final OnCallController controller = OnCallController.instance;
      await controller.reload();

      install(
        _FakeOnCallApi(
          schedules: [
            _scheduleRow(
              id: 'sched-9',
              rotations: [_rotationRow(id: 'rot-9', userId: 'u7')],
            ),
          ],
          responders: {'sched-9': null},
        ),
      );

      await controller.resetForSession();

      expect(controller.scheduleId, 'sched-9');
      expect(controller.rotationIdFor('u7'), 'rot-9');
      expect(controller.rotationIdFor('u2'), isNull);
    });
  });
}
