import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/on_call_controller.dart';
import 'package:uptizm/app/mocks/teams_data.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.warning() works inside MagicFeedback.showSnackbar
    // (add/remove/override call Magic.success/Magic.error, which fall through
    // to a warning log when no navigator context is mounted, as here).
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver so the wired controller resolves the
    // `network` service. Individual tests override it with `Http.fake({...})`
    // to seed a canned envelope, or call `Http.unfake()` to exercise the
    // network-unavailable degradation path.
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

  test('OnCallController.instance registers and returns a singleton', () {
    final OnCallController first = OnCallController.instance;
    final OnCallController second = OnCallController.instance;

    expect(identical(first, second), isTrue);
  });

  // ---------------------------------------------------------------------------
  // reload: GET /on-call/schedules, then GET the first schedule's detail to
  // load its rotation ring for the member -> rotation id lookup.
  // ---------------------------------------------------------------------------

  group('reload', () {
    test('fetches the schedule list then the first schedule detail', () async {
      final fake = Http.fake({
        'on-call/schedules': Http.response({
          'data': [
            {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
          ],
        }, 200),
        'on-call/schedules/sched-1': Http.response({
          'data': {
            'id': 'sched-1',
            'name': 'Primary',
            'timezone': 'UTC',
            'rotations': [
              {
                'id': 'rot-1',
                'user_id': 'u2',
                'user_name': 'Mara Pohl',
                'position': 0,
                'shift_hours': 48,
              },
            ],
            'overrides': [],
          },
        }, 200),
      });

      await OnCallController.instance.reload();

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('on-call/schedules'),
      );
      fake.assertSent(
        (r) => r.method == 'GET' && r.url.contains('on-call/schedules/sched-1'),
      );
      expect(OnCallController.instance.scheduleId, equals('sched-1'));
      expect(OnCallController.instance.rotationIdFor('u2'), equals('rot-1'));
    });

    test('degrades gracefully when no schedule exists yet', () async {
      Http.fake({
        'on-call/schedules': Http.response({'data': []}, 200),
      });

      await expectLater(
        () => OnCallController.instance.reload(),
        returnsNormally,
      );
      expect(OnCallController.instance.scheduleId, isNull);
    });

    test('degrades gracefully on a transport failure', () async {
      Http.unfake();

      await expectLater(
        () => OnCallController.instance.reload(),
        returnsNormally,
      );
      expect(OnCallController.instance.scheduleId, isNull);
    });
  });

  // ---------------------------------------------------------------------------
  // addToRotation: POSTs a rotation slot to the resolved (or lazily created)
  // schedule, then reloads.
  // ---------------------------------------------------------------------------

  group('addToRotation', () {
    test(
      'creates the schedule then POSTs the rotation when none exists yet',
      () async {
        // A callback fake differentiates GET (list, then the created schedule's
        // detail) and POST (create schedule, then create rotation) on the same
        // `on-call/schedules` URL, which the pattern-based fake cannot do (it
        // matches on URL only, not method).
        final fake = Http.fake((request) {
          final String path = request.url.startsWith('/')
              ? request.url.substring(1)
              : request.url;
          if (request.method == 'GET' && path == 'on-call/schedules') {
            return Http.response({'data': []}, 200);
          }
          if (request.method == 'POST' && path == 'on-call/schedules') {
            return Http.response({
              'data': {
                'id': 'sched-new',
                'name': 'Primary rotation',
                'timezone': 'UTC',
              },
            }, 201);
          }
          if (request.method == 'POST' &&
              path == 'on-call/schedules/sched-new/rotations') {
            return Http.response({
              'data': {
                'id': 'sched-new',
                'name': 'Primary rotation',
                'timezone': 'UTC',
                'rotations': [
                  {
                    'id': 'rot-9',
                    'user_id': 'u4',
                    'user_name': 'Ada Lovelace',
                    'position': 0,
                    'shift_hours': 24,
                  },
                ],
                'overrides': [],
              },
            }, 201);
          }
          return Http.response(<String, dynamic>{}, 200);
        });

        final TeamMember member = teamMembers.firstWhere((m) => m.id == 'u4');
        final bool ok = await OnCallController.instance.addToRotation(member);

        expect(ok, isTrue);
        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url.contains('on-call/schedules/sched-new/rotations') &&
              r.data is Map &&
              (r.data as Map)['user_id'] == 'u4',
        );
      },
    );

    test(
      'POSTs the rotation directly when a schedule is already cached',
      () async {
        final fake = Http.fake({
          'on-call/schedules': Http.response({
            'data': [
              {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
            ],
          }, 200),
          'on-call/schedules/sched-1': Http.response({
            'data': {
              'id': 'sched-1',
              'name': 'Primary',
              'timezone': 'UTC',
              'rotations': [],
              'overrides': [],
            },
          }, 200),
          'on-call/schedules/sched-1/rotations': Http.response({
            'data': {
              'id': 'sched-1',
              'name': 'Primary',
              'timezone': 'UTC',
              'rotations': [
                {
                  'id': 'rot-2',
                  'user_id': 'u3',
                  'user_name': 'Ravi Shah',
                  'position': 0,
                  'shift_hours': 24,
                },
              ],
              'overrides': [],
            },
          }, 201),
        });

        await OnCallController.instance.reload();
        final TeamMember member = teamMembers.firstWhere((m) => m.id == 'u3');
        final bool ok = await OnCallController.instance.addToRotation(member);

        expect(ok, isTrue);
        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url.contains('on-call/schedules/sched-1/rotations') &&
              r.data is Map &&
              (r.data as Map)['user_id'] == 'u3',
        );
        expect(OnCallController.instance.rotationIdFor('u3'), equals('rot-2'));
      },
    );

    test(
      'surfaces an error toast and returns false on a failed write',
      () async {
        Http.fake({
          'on-call/schedules': Http.response({
            'data': [
              {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
            ],
          }, 200),
          'on-call/schedules/sched-1': Http.response({
            'data': {
              'id': 'sched-1',
              'name': 'Primary',
              'timezone': 'UTC',
              'rotations': [],
              'overrides': [],
            },
          }, 200),
          'on-call/schedules/sched-1/rotations': Http.response({
            'message': 'Validation failed',
          }, 422),
        });

        await OnCallController.instance.reload();
        final TeamMember member = teamMembers.firstWhere((m) => m.id == 'u3');

        final bool ok = await OnCallController.instance.addToRotation(member);

        expect(ok, isFalse);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // removeFromRotation: DELETEs the resolved rotation row, then reloads.
  // ---------------------------------------------------------------------------

  group('removeFromRotation', () {
    test('DELETEs the rotation row resolved from the cached ring', () async {
      final fake = Http.fake({
        'on-call/schedules': Http.response({
          'data': [
            {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
          ],
        }, 200),
        'on-call/schedules/sched-1': Http.response({
          'data': {
            'id': 'sched-1',
            'name': 'Primary',
            'timezone': 'UTC',
            'rotations': [
              {
                'id': 'rot-2',
                'user_id': 'u2',
                'user_name': 'Mara Pohl',
                'position': 0,
                'shift_hours': 48,
              },
            ],
            'overrides': [],
          },
        }, 200),
        'on-call/schedules/sched-1/rotations/rot-2': Http.response(null, 204),
      });

      await OnCallController.instance.reload();
      final OnCallShift shift = onCallRotation.firstWhere(
        (s) => s.memberId == 'u2',
      );
      final bool ok = await OnCallController.instance.removeFromRotation(shift);

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url.contains('on-call/schedules/sched-1/rotations/rot-2'),
      );
    });

    test(
      'returns false without a request when no rotation id is cached',
      () async {
        final fake = Http.fake({
          'on-call/schedules': Http.response({'data': []}, 200),
        });

        final OnCallShift shift = onCallRotation.first;
        final bool ok = await OnCallController.instance.removeFromRotation(
          shift,
        );

        expect(ok, isFalse);
        fake.assertNotSent((r) => r.method == 'DELETE');
      },
    );
  });

  // ---------------------------------------------------------------------------
  // override: POSTs a temporary override to the resolved (or lazily created)
  // schedule, then reloads.
  // ---------------------------------------------------------------------------

  group('override', () {
    test('POSTs the override with a starts_at/ends_at window', () async {
      final fake = Http.fake({
        'on-call/schedules': Http.response({
          'data': [
            {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
          ],
        }, 200),
        'on-call/schedules/sched-1': Http.response({
          'data': {
            'id': 'sched-1',
            'name': 'Primary',
            'timezone': 'UTC',
            'rotations': [],
            'overrides': [],
          },
        }, 200),
        'on-call/schedules/sched-1/overrides': Http.response({
          'data': {
            'id': 'sched-1',
            'name': 'Primary',
            'timezone': 'UTC',
            'rotations': [],
            'overrides': [
              {
                'id': 'ov-1',
                'user_id': 'u4',
                'user_name': 'Ada Lovelace',
                'starts_at': '2026-07-12T00:00:00Z',
                'ends_at': '2026-07-13T00:00:00Z',
              },
            ],
          },
        }, 201),
      });

      await OnCallController.instance.reload();
      final TeamMember member = teamMembers.firstWhere((m) => m.id == 'u4');
      final bool ok = await OnCallController.instance.addOverride(member);

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('on-call/schedules/sched-1/overrides') &&
            r.data is Map &&
            (r.data as Map)['user_id'] == 'u4' &&
            (r.data as Map)['starts_at'] != null &&
            (r.data as Map)['ends_at'] != null,
      );
    });

    test(
      'surfaces an error toast and returns false on a failed write',
      () async {
        Http.fake({
          'on-call/schedules': Http.response({
            'data': [
              {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
            ],
          }, 200),
          'on-call/schedules/sched-1': Http.response({
            'data': {
              'id': 'sched-1',
              'name': 'Primary',
              'timezone': 'UTC',
              'rotations': [],
              'overrides': [],
            },
          }, 200),
          'on-call/schedules/sched-1/overrides': Http.response({
            'message': 'Validation failed',
          }, 422),
        });

        await OnCallController.instance.reload();
        final TeamMember member = teamMembers.firstWhere((m) => m.id == 'u4');

        final bool ok = await OnCallController.instance.addOverride(member);

        expect(ok, isFalse);
      },
    );

    test(
      'degrades gracefully (no throw) when the network is unavailable',
      () async {
        Http.unfake();
        final TeamMember member = teamMembers.firstWhere((m) => m.id == 'u4');

        await expectLater(
          () => OnCallController.instance.addOverride(member),
          returnsNormally,
        );
      },
    );
  });
}
