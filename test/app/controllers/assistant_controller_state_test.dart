import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/assistant_controller.dart';
import 'package:uptizm/app/support/assistant_types.dart';

/// What these pin: the assistant conversation lives on the controller, which is
/// what lets it survive the shell swap at the `lg` breakpoint, and a second
/// question cannot be sent while the first is in flight.
///
/// `AppLayout` mounts `Assistant` in two structurally different subtrees chosen
/// by `wScreenIs('lg')`, so while the exchange lived in the widget's `State`,
/// dragging a browser window across 1024px discarded it: an operator mid-triage
/// lost the whole conversation with nothing persisted.
void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    Magic.singleton('log', () => LogManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  Map<String, dynamic> answer(String text) => <String, dynamic>{
    'data': <String, dynamic>{'answer': text},
  };

  test('the greeting is seeded once, however many shells mount', () {
    final AssistantController controller = Magic.findOrPut(
      AssistantController.new,
    );

    controller.ensureGreeted();
    controller.ensureGreeted();

    expect(controller.messages, hasLength(1));
    expect(controller.messages.first.role, AssistantRole.assistant);
  });

  test('a question and its answer both land on the controller', () async {
    Http.fake({'assistant': Http.response(answer('Checkout is slow.'))});
    final AssistantController controller = Magic.findOrPut(
      AssistantController.new,
    );
    controller.ensureGreeted();

    await controller.send('why is checkout slow?');

    expect(controller.messages, hasLength(3));
    expect(controller.messages[1].role, AssistantRole.user);
    expect(controller.messages[2].text, 'Checkout is slow.');
    expect(controller.isAsking, isFalse);
  });

  test('a second question while one is in flight is refused', () async {
    // Each accepted question is another POST /assistant charged against the
    // team's daily AI allowance, and replies appended in completion order
    // rather than send order could put the first answer under the second.
    final FakeNetworkDriver fake = Http.fake({
      'assistant': Http.response(answer('One.')),
    });
    final AssistantController controller = Magic.findOrPut(
      AssistantController.new,
    );

    final Future<void> first = controller.send('first');
    // Not awaited: this is the second tap, landing while the first is open.
    await controller.send('second');
    await first;

    expect(
      fake.recorded.length,
      1,
      reason: 'the second tap must not spend another AI allowance unit',
    );
    expect(
      controller.messages.where((m) => m.role == AssistantRole.user).length,
      1,
    );
  });

  test('a blank question is not sent', () async {
    final FakeNetworkDriver fake = Http.fake({
      'assistant': Http.response(answer('.')),
    });
    final AssistantController controller = Magic.findOrPut(
      AssistantController.new,
    );

    await controller.send('   ');

    expect(fake.recorded, isEmpty);
  });

  test('a session reset drops the outgoing team conversation', () async {
    Http.fake({'assistant': Http.response(answer('Checkout is slow.'))});
    final AssistantController controller = Magic.findOrPut(
      AssistantController.new,
    );
    controller.ensureGreeted();
    await controller.send('why is checkout slow?');
    expect(controller.messages, hasLength(3));

    // The assistant is grounded on ONE team's monitors and incidents, so the
    // exchange belongs to the identity that had it.
    await controller.resetForSession();

    expect(controller.messages, isEmpty);
    expect(controller.isAsking, isFalse);
  });
}
