import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/models/on_call_schedule.dart';

/// OnCallSchedule model tests.
///
/// The schedule's REST resource is the nested segment `on-call/schedules`,
/// so the static [OnCallSchedule.find]/[OnCallSchedule.all] helpers MUST
/// route through the SAME wire paths the controller already drives raw
/// (`GET /on-call/schedules` and `GET /on-call/schedules/:id`). The
/// `find`/`all` groups below reuse the exact fake-stub keys the controller
/// test seeds (`on-call/schedules`, `on-call/schedules/<id>`), proving the
/// ORM and the raw controller share one wire path.
void main() {
  // Http.fake() injects the FakeNetworkDriver into the IoC container; unfake
  // in tearDown so the singleton does not leak into sibling test files.
  tearDown(Http.unfake);

  group('OnCallSchedule.resource', () {
    test('is the nested on-call/schedules segment', () {
      final OnCallSchedule schedule = OnCallSchedule();

      expect(schedule.resource, 'on-call/schedules');
      expect(schedule.table, 'on_call_schedules');
      expect(schedule.incrementing, isFalse);
    });
  });

  group('OnCallSchedule.fromMap', () {
    test('decodes a backend OnCallScheduleResource payload', () {
      final OnCallSchedule schedule = OnCallSchedule.fromMap({
        'id': 'sched-1',
        'team_id': 't1',
        'name': 'Primary rotation',
        'timezone': 'America/New_York',
        'created_at': '2026-07-12T00:00:00Z',
        'updated_at': '2026-07-12T01:00:00Z',
      });

      expect(schedule.id, 'sched-1');
      expect(schedule.teamId, 't1');
      expect(schedule.name, 'Primary rotation');
      expect(schedule.timezone, 'America/New_York');
      expect(schedule.createdAt, isNotNull);
      expect(schedule.updatedAt, isNotNull);
    });

    test('preserves the eager-loaded rotations and overrides rings', () {
      // The detail payload carries the rotations/overrides sub-resource rings
      // alongside the schedule scalars. fromMap stores every key as a raw
      // attribute so the controller migration (Step 8) can expose them
      // without re-fetching.
      final OnCallSchedule schedule = OnCallSchedule.fromMap({
        'id': 'sched-1',
        'team_id': 't1',
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
        'overrides': [
          {
            'id': 'ov-1',
            'user_id': 'u4',
            'user_name': 'Ada Lovelace',
            'starts_at': '2026-07-12T00:00:00Z',
            'ends_at': '2026-07-13T00:00:00Z',
          },
        ],
      });

      // The rings ride through as raw attributes (List<Map>), ready for the
      // controller's `_applyScheduleDetail` extraction in Step 8.
      final Object? rotations = schedule.attributes['rotations'];
      expect(rotations, isA<List>());
      expect((rotations as List).first['user_id'], 'u2');

      final Object? overrides = schedule.attributes['overrides'];
      expect(overrides, isA<List>());
      expect((overrides as List).first['user_id'], 'u4');
    });

    test('marks the model as existing when an id is present', () {
      expect(OnCallSchedule.fromMap({'id': 'sched-1'}).exists, isTrue);
      expect(OnCallSchedule.fromMap({'name': 'Primary'}).exists, isFalse);
    });
  });

  group('OnCallSchedule typed accessors', () {
    test('name and timezone are readable and writable', () {
      final OnCallSchedule schedule = OnCallSchedule();

      schedule
        ..name = 'Escalation ring'
        ..timezone = 'Europe/Berlin';

      expect(schedule.name, 'Escalation ring');
      expect(schedule.timezone, 'Europe/Berlin');
    });

    test('teamId defaults to null when the payload omits it', () {
      expect(OnCallSchedule().teamId, isNull);
      expect(OnCallSchedule.fromMap({'id': 'x'}).teamId, isNull);
    });
  });

  group('OnCallSchedule fillable', () {
    test('accepts name and timezone via mass assignment', () {
      final OnCallSchedule schedule = OnCallSchedule()
        ..fill({
          'name': 'Primary rotation',
          'timezone': 'UTC',
        });

      expect(schedule.name, 'Primary rotation');
      expect(schedule.timezone, 'UTC');
    });

    test('guards team_id (server-resolved, not client-writable)', () {
      // Mirrors StoreOnCallScheduleRequest, which validates only name/timezone:
      // the owning team_id is bound server-side from the authenticated user's
      // current team, so it must never enter the client write surface.
      final OnCallSchedule schedule = OnCallSchedule()
        ..fill({
          'name': 'Primary',
          'team_id': 't-attacker',
        });

      expect(schedule.name, 'Primary');
      expect(schedule.teamId, isNull);
      expect(schedule.fillable, ['name', 'timezone']);
    });
  });

  group('OnCallSchedule.all', () {
    test('routes through GET /on-call/schedules (same wire path as controller)', () async {
      // The stub key mirrors the controller test's list stub verbatim, proving
      // the ORM resolves the same fake path the raw Http.get drives today.
      final FakeNetworkDriver fake = Http.fake({
        'on-call/schedules': Http.response({
          'data': [
            {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
            {'id': 'sched-2', 'name': 'Escalation', 'timezone': 'UTC'},
          ],
        }, 200),
      });

      final List<OnCallSchedule> schedules = await OnCallSchedule.all();

      expect(schedules.length, 2);
      expect(schedules.first.id, 'sched-1');
      expect(schedules.first.name, 'Primary');
      expect(schedules.last.id, 'sched-2');
      fake.assertSent(
        (MagicRequest r) => r.method == 'GET' && r.url == '/on-call/schedules',
      );
    });

    test('returns an empty list when no schedule exists yet', () async {
      Http.fake({
        'on-call/schedules': Http.response({'data': []}, 200),
      });

      final List<OnCallSchedule> schedules = await OnCallSchedule.all();

      expect(schedules, isEmpty);
    });
  });

  group('OnCallSchedule.find', () {
    test('routes through GET /on-call/schedules/:id (same wire path as controller)', () async {
      // The stub key mirrors the controller test's detail stub verbatim,
      // proving the ORM resolves the same fake path the raw Http.get drives.
      final FakeNetworkDriver fake = Http.fake({
        'on-call/schedules/sched-1': Http.response({
          'data': {
            'id': 'sched-1',
            'team_id': 't1',
            'name': 'Primary',
            'timezone': 'UTC',
            'rotations': [],
            'overrides': [],
          },
        }, 200),
      });

      final OnCallSchedule? schedule = await OnCallSchedule.find('sched-1');

      expect(schedule, isNotNull);
      expect(schedule?.id, 'sched-1');
      expect(schedule?.name, 'Primary');
      fake.assertSent(
        (MagicRequest r) =>
            r.method == 'GET' && r.url == '/on-call/schedules/sched-1',
      );
    });

    test('returns null when the schedule is not found', () async {
      Http.fake({
        'on-call/schedules/missing': Http.response({'message': 'Not found'}, 404),
      });

      final OnCallSchedule? schedule = await OnCallSchedule.find('missing');

      expect(schedule, isNull);
    });
  });

  group('OnCallSchedule round-trip', () {
    test('toMap re-serializes the scalar schedule fields', () {
      final OnCallSchedule schedule = OnCallSchedule.fromMap({
        'id': 'sched-1',
        'team_id': 't1',
        'name': 'Primary',
        'timezone': 'UTC',
      });

      final Map<String, dynamic> map = schedule.toMap();

      expect(map['id'], 'sched-1');
      expect(map['team_id'], 't1');
      expect(map['name'], 'Primary');
      expect(map['timezone'], 'UTC');
    });

    test('fromJson decodes a JSON string payload', () {
      final OnCallSchedule schedule = OnCallSchedule.fromJson(
        '{"id":"sched-9","team_id":"t9","name":"Weekend","timezone":"UTC"}',
      );

      expect(schedule.id, 'sched-9');
      expect(schedule.teamId, 't9');
      expect(schedule.name, 'Weekend');
    });
  });
}
