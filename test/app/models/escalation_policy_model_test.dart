import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/escalation_policy.dart';

void main() {
  group('EscalationPolicy model', () {
    test('resource / table / incrementing / fillable configuration', () {
      final policy = EscalationPolicy.fromMap({'id': 'p1', 'name': 'Standard'});
      expect(policy.table, 'escalation_policies');
      expect(policy.resource, 'escalation-policies');
      expect(policy.incrementing, isFalse);
      // Only `name` is mass-assignable; the step chain is a sub-resource.
      expect(policy.fillable, ['name']);
    });

    test('fromMap hydrates id + name and marks exists', () {
      final policy = EscalationPolicy.fromMap({
        'id': 'p1',
        'name': 'Critical path',
      });
      expect(policy.exists, isTrue);
      expect(policy.id, 'p1');
      expect(policy.name, 'Critical path');
    });

    test('fromMap without an id leaves exists false', () {
      final policy = EscalationPolicy.fromMap({'name': 'No id'});
      expect(policy.exists, isFalse);
    });

    test('steps decodes the wire step chain into EscalationStepWire with ids', () {
      final policy = EscalationPolicy.fromMap({
        'id': 'p1',
        'name': 'Standard',
        'steps': [
          {'id': 's1', 'position': 0, 'delay_minutes': 0, 'target_type': 'on_call'},
          {'id': 's2', 'position': 1, 'delay_minutes': 5, 'target_type': 'on_call'},
          {'id': 's3', 'position': 2, 'delay_minutes': 15, 'target_type': 'user', 'target_id': 'u9'},
        ],
      });
      final steps = policy.steps;
      expect(steps.length, 3);
      expect(steps[0].id, 's1');
      expect(steps[0].delayMinutes, 0);
      expect(steps[0].targetType, 'on_call');
      expect(steps[1].id, 's2');
      expect(steps[1].delayMinutes, 5);
      expect(steps[1].targetType, 'on_call');
      expect(steps[2].id, 's3');
      expect(steps[2].targetType, 'user');
      expect(steps[2].targetId, 'u9');
    });

    test('steps is empty when the wire omits the steps array', () {
      final policy = EscalationPolicy.fromMap({'id': 'p1', 'name': 'Index row'});
      expect(policy.steps, isEmpty);
    });

    test('name is settable', () {
      final policy = EscalationPolicy.fromMap({'id': 'p1', 'name': 'Old'});
      policy.name = 'New';
      expect(policy.name, 'New');
    });

    test('all() routes through GET /escalation-policies via Http.fake', () async {
      final fake = Http.fake({
        'escalation-policies': Http.response({
          'data': [
            {'id': 'p1', 'name': 'Standard'},
            {'id': 'p2', 'name': 'Critical path'},
          ],
        }),
      });
      final policies = await EscalationPolicy.all();
      expect(policies.length, 2);
      expect(policies[0].id, 'p1');
      expect(policies[0].name, 'Standard');
      expect(policies[1].id, 'p2');
      fake.assertSent((r) => r.url.contains('escalation-policies'));
      Http.unfake();
    });

    test('find() routes through GET /escalation-policies/{id} and decodes steps', () async {
      final fake = Http.fake({
        'escalation-policies/p1': Http.response({
          'data': {
            'id': 'p1',
            'name': 'Standard',
            'steps': [
              {'id': 's1', 'position': 0, 'delay_minutes': 0, 'target_type': 'on_call'},
            ],
          },
        }),
      });
      final policy = await EscalationPolicy.find('p1');
      expect(policy, isNotNull);
      expect(policy!.id, 'p1');
      expect(policy.name, 'Standard');
      expect(policy.steps.length, 1);
      expect(policy.steps.first.id, 's1');
      fake.assertSent((r) => r.url.contains('escalation-policies/p1'));
      Http.unfake();
    });

    test('find() returns null on a non-2xx response', () async {
      Http.fake({
        'escalation-policies/missing': Http.response({'message': 'not found'}, 404),
      });
      final policy = await EscalationPolicy.find('missing');
      expect(policy, isNull);
      Http.unfake();
    });

    test('fromJson round-trips the wire shape', () {
      final policy = EscalationPolicy.fromJson(
        '{"id":"p1","name":"Standard","steps":[{"id":"s1","position":0,"delay_minutes":0,"target_type":"on_call"}]}',
      );
      expect(policy.id, 'p1');
      expect(policy.name, 'Standard');
      expect(policy.steps.length, 1);
      expect(policy.steps.first.id, 's1');
    });
  });
}
