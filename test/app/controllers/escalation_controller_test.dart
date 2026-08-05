import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/escalation_controller.dart';
import 'package:uptizm/app/models/escalation_policy.dart';
import 'package:uptizm/app/support/escalation_support.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.warning() works inside MagicFeedback.showSnackbar
    // (create/save/delete call Magic.success/Magic.error, which fall through
    // to a warning log when no navigator context is mounted, as here).
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver so the wired reload/create/save/delete/
    // removeStep/reorderSteps actions resolve the `network` service.
    // Individual tests override it with `Http.fake({...})` to seed a canned
    // envelope, or call `Http.unfake()` to exercise the transport-failure
    // degradation path.
    Http.fake();
    // Force-build the lazy GoRouter so MagicRoute.to (used by create/save)
    // does not throw StateError('Router not initialized...').
    MagicRouter.instance.routerConfig;
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('EscalationController.instance registers and returns a singleton', () {
    final EscalationController first = EscalationController.instance;
    final EscalationController second = EscalationController.instance;

    expect(identical(first, second), isTrue);
  });

  // ---------------------------------------------------------------------------
  // reload: GET index + per-policy GET detail (Future.wait fan-out).
  // ---------------------------------------------------------------------------

  group('reload', () {
    test('hydrates the roster from index + per-policy detail fetches', () async {
      Http.fake({
        'escalation-policies': Http.response({
          'data': [
            {'id': 'standard', 'name': 'Standard'},
          ],
        }, 200),
        'escalation-policies/standard': Http.response({
          'data': {
            'id': 'standard',
            'name': 'Standard',
            'steps': [
              {
                'id': 'step-1',
                'position': 0,
                'delay_minutes': 0,
                'target_type': 'user',
                'target_id': 'u2',
              },
            ],
          },
        }, 200),
      });
      final EscalationController controller = EscalationController.instance;

      await controller.reload();

      expect(controller.policies, hasLength(1));
      expect(controller.policies.single.name, equals('Standard'));
      expect(controller.policies.single.steps, hasLength(1));
      expect(
        controller.policies.single.steps.single.targetType,
        equals('user'),
      );
      expect(
        controller.policies.single.steps.single.targetId,
        equals('u2'),
      );
    });

    test('preserves the last-known-good roster on a failed index fetch', () async {
      Http.fake({
        'escalation-policies': Http.response({'message': 'nope'}, 500),
      });
      final EscalationController controller = EscalationController.instance;

      await controller.reload();

      expect(controller.policies, isEmpty);
    });

    test('degrades gracefully (no throw) when the network is unavailable', () async {
      Http.unfake();
      final EscalationController controller = EscalationController.instance;

      await expectLater(controller.reload(), completes);
      expect(controller.policies, isEmpty);
    });
  });

  // ---------------------------------------------------------------------------
  // detailById: synchronous cache + background refresh.
  // ---------------------------------------------------------------------------

  group('detailById', () {
    test('returns null for an unknown or null id', () {
      final EscalationController controller = EscalationController.instance;

      expect(controller.detailById('does-not-exist'), isNull);
      expect(controller.detailById(null), isNull);
    });

    test('returns the seeded detail synchronously', () {
      final EscalationController controller = EscalationController.instance;
      controller.seedForTest([
        EscalationPolicy.fromMap({'id': 'standard', 'name': 'Standard'}),
      ]);

      expect(controller.detailById('standard')?.name, equals('Standard'));
    });

    // What these pin: the editor picks between a skeleton and "this policy does
    // not exist" on the difference between an unanswered per-id read and an
    // answered one. `isFirstLoad` covers the LIST and cannot speak for one
    // policy, so before this flag the editor called a deep-linked policy broken
    // for as long as its own `refreshDetail` took.
    test('an id with no answered read yet reads as pending', () {
      final EscalationController controller = EscalationController.instance;

      expect(controller.detailById('standard'), isNull);
      expect(controller.isFirstLoadFor('standard'), isTrue);
    });

    test('a refreshDetail that found nothing settles the id', () async {
      // The sharp edge: settling only on success would skeleton forever on a
      // genuinely missing policy, which is the one case the not-found state is
      // actually for.
      final EscalationController controller = EscalationController.instance;

      await controller.refreshDetail('gone');

      expect(controller.detailById('gone'), isNull);
      expect(
        controller.isFirstLoadFor('gone'),
        isFalse,
        reason: 'a read that came back empty has still answered',
      );
    });

    test('a null id is the create form and waits for nothing', () {
      final EscalationController controller = EscalationController.instance;

      expect(controller.isFirstLoadFor(null), isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  // create: POST policy + sequential POST steps, reload + navigate on
  // success, error toast + stay on failure.
  // ---------------------------------------------------------------------------

  group('create', () {
    test('POSTs the policy then one people-only step per rung, in order', () async {
      final fake = Http.fake({
        'escalation-policies': Http.response({
          'data': {'id': 'new-policy', 'name': 'New policy'},
        }, 201),
        'escalation-policies/new-policy/steps': Http.response({'data': {}}, 201),
        'escalation-policies/new-policy': Http.response({
          'data': {'id': 'new-policy', 'name': 'New policy', 'steps': []},
        }, 200),
      });
      final EscalationController controller = EscalationController.instance;

      await controller.create('New policy', const [
        EscalationRungDraft(
          afterMinutes: 0,
          targetType: EscalationTargetType.onCall,
        ),
        EscalationRungDraft(
          afterMinutes: 5,
          targetType: EscalationTargetType.user,
          targetUserId: 'u2',
        ),
      ]);

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('escalation-policies') &&
            !r.url.contains('/steps') &&
            r.data is Map &&
            (r.data as Map)['name'] == 'New policy',
      );
      final int stepPostCount = fake.recorded
          .where(
            (entry) =>
                entry.$1.method == 'POST' &&
                entry.$1.url.contains('escalation-policies/new-policy/steps'),
          )
          .length;
      expect(stepPostCount, equals(2));
      // On-call rung: target_type on_call, no target_id.
      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('escalation-policies/new-policy/steps') &&
            r.data is Map &&
            (r.data as Map)['position'] == 0 &&
            (r.data as Map)['delay_minutes'] == 0 &&
            (r.data as Map)['target_type'] == 'on_call' &&
            !(r.data as Map).containsKey('target_id'),
      );
      // User rung: target_type user + the member id.
      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('escalation-policies/new-policy/steps') &&
            r.data is Map &&
            (r.data as Map)['position'] == 1 &&
            (r.data as Map)['delay_minutes'] == 5 &&
            (r.data as Map)['target_type'] == 'user' &&
            (r.data as Map)['target_id'] == 'u2',
      );
      // Never the removed channel target type.
      fake.assertNotSent(
        (r) => r.data is Map && (r.data as Map)['target_type'] == 'channel',
      );
    });

    test('surfaces an error toast and does not create steps on a failed policy create', () async {
      final fake = Http.fake({
        'escalation-policies': Http.response({'message': 'Validation failed'}, 422),
      });
      final EscalationController controller = EscalationController.instance;

      await expectLater(
        controller.create('New policy', const [
          EscalationRungDraft(
            afterMinutes: 0,
            targetType: EscalationTargetType.onCall,
          ),
        ]),
        completes,
      );
      fake.assertNotSent((r) => r.url.contains('/steps'));
    });
  });

  // ---------------------------------------------------------------------------
  // save: PUT policy + reconcile steps (remove/add/reorder).
  // ---------------------------------------------------------------------------

  group('save', () {
    test('PUTs the policy name', () async {
      final fake = Http.fake({
        'escalation-policies/standard': Http.response({
          'data': {'id': 'standard', 'name': 'Standard', 'steps': []},
        }, 200),
      });
      final EscalationController controller = EscalationController.instance;

      await controller.save('standard', 'Standard', const [], const {});

      fake.assertSent(
        (r) =>
            r.method == 'PUT' &&
            r.url.contains('escalation-policies/standard') &&
            !r.url.contains('/steps') &&
            r.data is Map &&
            (r.data as Map)['name'] == 'Standard',
      );
    });

    test('removes an original step id no longer present in the draft', () async {
      final fake = Http.fake({
        'escalation-policies/standard': Http.response({
          'data': {'id': 'standard', 'name': 'Standard', 'steps': []},
        }, 200),
        'escalation-policies/standard/steps/step-1': Http.response(null, 204),
      });
      final EscalationController controller = EscalationController.instance;

      await controller.save('standard', 'Standard', const [], {'step-1'});

      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url.contains('escalation-policies/standard/steps/step-1'),
      );
    });

    test('adds a user step for a rung with a null id (new or edited)', () async {
      final fake = Http.fake({
        'escalation-policies/standard': Http.response({
          'data': {'id': 'standard', 'name': 'Standard', 'steps': []},
        }, 200),
        'escalation-policies/standard/steps': Http.response({'data': {}}, 201),
      });
      final EscalationController controller = EscalationController.instance;

      await controller.save('standard', 'Standard', const [
        EscalationRungDraft(
          afterMinutes: 10,
          targetType: EscalationTargetType.user,
          targetUserId: 'u3',
        ),
      ], const {});

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('escalation-policies/standard/steps') &&
            r.data is Map &&
            (r.data as Map)['position'] == 0 &&
            (r.data as Map)['delay_minutes'] == 10 &&
            (r.data as Map)['target_type'] == 'user' &&
            (r.data as Map)['target_id'] == 'u3',
      );
    });

    test('bulk-reorders untouched rungs via the reorder endpoint', () async {
      final fake = Http.fake({
        'escalation-policies/standard': Http.response({
          'data': {'id': 'standard', 'name': 'Standard', 'steps': []},
        }, 200),
        'escalation-policies/standard/steps/reorder': Http.response(null, 204),
      });
      final EscalationController controller = EscalationController.instance;

      await controller.save('standard', 'Standard', const [
        EscalationRungDraft(
          id: 'step-2',
          afterMinutes: 5,
          targetType: EscalationTargetType.user,
          targetUserId: 'u3',
        ),
        EscalationRungDraft(
          id: 'step-1',
          afterMinutes: 0,
          targetType: EscalationTargetType.onCall,
        ),
      ], {'step-1', 'step-2'});

      fake.assertSent((r) {
        if (r.method != 'PUT' ||
            !r.url.contains('escalation-policies/standard/steps/reorder') ||
            r.data is! Map) {
          return false;
        }
        final Object? order = (r.data as Map)['order'];
        if (order is! List || order.length != 2) return false;
        return order[0]['id'] == 'step-2' &&
            order[0]['position'] == 0 &&
            order[1]['id'] == 'step-1' &&
            order[1]['position'] == 1;
      });
    });

    test('surfaces an error toast and stops reconciling on a failed policy update', () async {
      final fake = Http.fake({
        'escalation-policies/standard': Http.response({'message': 'nope'}, 422),
      });
      final EscalationController controller = EscalationController.instance;

      await expectLater(
        controller.save('standard', 'Standard', const [], {'step-1'}),
        completes,
      );
      fake.assertNotSent((r) => r.method == 'DELETE');
    });
  });

  // ---------------------------------------------------------------------------
  // delete: DELETE policy, evict from cache, reload on success.
  // ---------------------------------------------------------------------------

  group('delete', () {
    test('DELETEs /escalation-policies/{id} and evicts it from the cache', () async {
      final fake = Http.fake({
        'escalation-policies/standard': Http.response(null, 204),
      });
      final EscalationController controller = EscalationController.instance;
      controller.seedForTest([
        EscalationPolicy.fromMap({'id': 'standard', 'name': 'Standard'}),
      ]);

      await controller.delete('standard');

      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url.contains('escalation-policies/standard'),
      );
      expect(controller.policies, isEmpty);
    });

    test('surfaces an error toast without throwing on a failed delete', () async {
      Http.fake({
        'escalation-policies/standard': Http.response({'message': 'nope'}, 422),
      });
      final EscalationController controller = EscalationController.instance;
      controller.seedForTest([
        EscalationPolicy.fromMap({'id': 'standard', 'name': 'Standard'}),
      ]);

      await expectLater(controller.delete('standard'), completes);
      expect(controller.policies, hasLength(1));
    });
  });

  // ---------------------------------------------------------------------------
  // removeStep / reorderSteps: directly callable + testable primitives.
  // ---------------------------------------------------------------------------

  group('removeStep', () {
    test('DELETEs /escalation-policies/{policyId}/steps/{stepId}', () async {
      final fake = Http.fake({
        'escalation-policies/standard/steps/step-1': Http.response(null, 204),
      });
      final EscalationController controller = EscalationController.instance;

      final bool ok = await controller.removeStep('standard', 'step-1');

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url.contains('escalation-policies/standard/steps/step-1'),
      );
    });

    test('returns false and toasts on a failed removal', () async {
      Http.fake({
        'escalation-policies/standard/steps/step-1': Http.response(
          {'message': 'nope'},
          422,
        ),
      });
      final EscalationController controller = EscalationController.instance;

      final bool ok = await controller.removeStep('standard', 'step-1');

      expect(ok, isFalse);
    });
  });

  group('reorderSteps', () {
    test('PUTs /escalation-policies/{policyId}/steps/reorder with the order', () async {
      final fake = Http.fake({
        'escalation-policies/standard/steps/reorder': Http.response(null, 204),
      });
      final EscalationController controller = EscalationController.instance;
      final order = [
        {'id': 'step-2', 'position': 0},
        {'id': 'step-1', 'position': 1},
      ];

      final bool ok = await controller.reorderSteps('standard', order);

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'PUT' &&
            r.url.contains('escalation-policies/standard/steps/reorder') &&
            r.data is Map &&
            (r.data as Map)['order'] == order,
      );
    });

    test('returns false and toasts on a failed reorder', () async {
      Http.fake({
        'escalation-policies/standard/steps/reorder': Http.response(
          {'message': 'nope'},
          422,
        ),
      });
      final EscalationController controller = EscalationController.instance;

      final bool ok = await controller.reorderSteps('standard', const [
        {'id': 'step-1', 'position': 0},
      ]);

      expect(ok, isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  // resetForSession: clear the previous identity's policy cache, then refetch.
  // ---------------------------------------------------------------------------

  group('resetForSession', () {
    test('clears the cached policies even when the refetch fails', () async {
      final EscalationController controller = EscalationController.instance;
      controller.seedForTest([
        EscalationPolicy.fromMap({'id': 'standard', 'name': 'Standard'}),
      ]);
      expect(controller.policies, hasLength(1));

      // The new identity's refetch resolves nothing. `reload` alone reads an
      // empty roster as "nothing new to publish", which would leave the
      // previous team's policies listed and openable in the editor.
      Http.fake((r) => Http.response({'message': 'down'}, 500));
      var notifications = 0;
      controller.addListener(() => notifications++);

      await controller.resetForSession();

      expect(notifications, greaterThan(0));
      expect(controller.policies, isEmpty);
      expect(controller.detailById('standard'), isNull);
    });

    test('refetches the policies of the new identity', () async {
      final EscalationController controller = EscalationController.instance;
      controller.seedForTest([
        EscalationPolicy.fromMap({'id': 'standard', 'name': 'Standard'}),
      ]);

      Http.fake({
        'escalation-policies/other-team': Http.response({
          'data': {'id': 'other-team', 'name': 'Northwind ladder', 'steps': []},
        }, 200),
        'escalation-policies': Http.response({
          'data': [
            {'id': 'other-team', 'name': 'Northwind ladder'},
          ],
        }, 200),
      });

      await controller.resetForSession();

      expect(
        controller.policies.map((EscalationPolicy p) => p.id).toList(),
        equals(['other-team']),
      );
      expect(controller.detailById('standard'), isNull);
    });
  });
}
