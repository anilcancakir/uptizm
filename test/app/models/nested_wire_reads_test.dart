import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/models/escalation_policy.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/app/support/escalation_support.dart';
import 'package:uptizm/app/support/status_page_types.dart';

/// What these pin: the same degradation as `model_reads_degrade_test.dart`, one
/// layer deeper.
///
/// That suite covers a model's OWN attributes, which go through magic's
/// `get<T>` and coerce. These two getters decode NESTED maps by hand (a pivot
/// row, a step sub-object), where `m['k'] as String?` is a hard cast with
/// nothing above it. Both run inside getters a `build` reads, so a wrong-typed
/// field took the status page and the escalation editor down rather than
/// blanking one line.
///
/// The id case is not hypothetical. `MigrationHelper::primaryKey` picks uuid or
/// bigint off `magic-starter.use_uuids`, and `EscalationPolicyResource` emits
/// `$step->id` raw, so an int id is a configuration flag away.
void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  group('status page components', () {
    test('a non-string label blanks one name, not the whole list', () {
      final StatusPage page = StatusPage.fromMap(<String, dynamic>{
        'id': 'acme',
        'monitors': <Map<String, dynamic>>[
          {'name': 'API', 'last_status': 'up', 'display_order': 1},
          {'name': 42, 'last_status': 'up', 'display_order': 2},
        ],
      });

      final List<PublicComponent> components = page.components;

      expect(components, hasLength(2));
      expect(components[0].name, 'API');
      // The odd row still renders, with an empty label rather than taking the
      // other component down with it.
      expect(components[1].name, '');
    });

    test('a non-string status reads pending, never up', () {
      final StatusPage page = StatusPage.fromMap(<String, dynamic>{
        'id': 'acme',
        'monitors': <Map<String, dynamic>>[
          {'name': 'API', 'last_status': 7},
        ],
      });

      // The product rule: the absence of a readable measurement is not evidence
      // of health, so an undecodable status is pending and not `up`.
      expect(page.components.single.status, StatusKey.pending);
    });

    test('a string display_order still sorts, rather than collapsing to 0', () {
      final StatusPage page = StatusPage.fromMap(<String, dynamic>{
        'id': 'acme',
        'monitors': <Map<String, dynamic>>[
          {'name': 'second', 'last_status': 'up', 'display_order': '2'},
          {'name': 'first', 'last_status': 'up', 'display_order': '1'},
        ],
      });

      expect(
        page.components.map((PublicComponent c) => c.name),
        <String>['first', 'second'],
      );
    });
  });

  group('escalation policy steps', () {
    test('a numeric step id keeps its identity instead of throwing', () {
      final EscalationPolicy policy = EscalationPolicy.fromMap(
        <String, dynamic>{
          'id': 'p1',
          'name': 'Primary',
          'steps': <Map<String, dynamic>>[
            {
              'id': 17,
              'position': 0,
              'delay_minutes': 5,
              'target_type': 'user',
              'target_id': 'u1',
            },
          ],
        },
      );

      final EscalationStepWire step = policy.steps.single;

      // Stringified, NOT null. The editor's save-diff reads a null id as "create
      // this step", so degrading an existing id to null would keep the screen up
      // and then duplicate the row on the next save.
      expect(step.id, '17');
      expect(step.delayMinutes, 5);
    });

    test('an unreadable step id is null, and the rest of the chain decodes', () {
      final EscalationPolicy policy = EscalationPolicy.fromMap(
        <String, dynamic>{
          'id': 'p1',
          'steps': <Map<String, dynamic>>[
            {
              'id': <String>['not-an-id'],
              'position': 0,
              'target_type': 'schedule',
            },
          ],
        },
      );

      final EscalationStepWire step = policy.steps.single;

      expect(step.id, isNull);
      expect(step.targetType, 'schedule');
    });

    test('a non-string target_type falls back rather than throwing', () {
      final EscalationPolicy policy = EscalationPolicy.fromMap(
        <String, dynamic>{
          'id': 'p1',
          'steps': <Map<String, dynamic>>[
            {'id': 's1', 'target_type': 3, 'target_id': 9},
          ],
        },
      );

      final EscalationStepWire step = policy.steps.single;

      expect(step.targetType, 'on_call');
      // `target_id` is a plain string field rather than a record id the diff
      // branches on, so an unreadable one degrades to null.
      expect(step.targetId, isNull);
    });
  });
}
