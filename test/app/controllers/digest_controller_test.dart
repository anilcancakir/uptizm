import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/digest_controller.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // The failure path logs, so `log` has to resolve or the logger throws out
    // of the read it was reporting on.
    Magic.singleton('log', () => LogManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// The digest payload the backend composes, trimmed to what the phase machine
  /// needs. Shape only: what the fields render is the view's business.
  Map<String, dynamic> digestPayload() => <String, dynamic>{
    'data': <String, dynamic>{
      'week_start': '2026-08-31T00:00:00.000Z',
      'week_end': '2026-09-06T23:59:59.000Z',
      'summary': 'A quiet week.',
    },
  };

  test('a resolved payload lands on ready', () async {
    Http.fake({'incidents/digest': Http.response(digestPayload())});
    final DigestController controller = Magic.findOrPut(DigestController.new);

    await controller.load();

    expect(controller.phase, DigestPhase.ready);
    expect(controller.digest, isNotNull);
  });

  test('a 404 is empty, because the server answered', () async {
    Http.fake({
      'incidents/digest': Http.response(<String, dynamic>{}, 404),
    });
    final DigestController controller = Magic.findOrPut(DigestController.new);

    await controller.load();

    expect(controller.phase, DigestPhase.empty);
    expect(controller.digest, isNull);
  });

  test('a 500 is an error, never an empty digest', () async {
    // The distinction the whole phase enum exists for: a read that did not land
    // is not a team with no digest, and only one of the two should offer Retry.
    Http.fake({
      'incidents/digest': Http.response(<String, dynamic>{'message': 'down'}, 500),
    });
    final DigestController controller = Magic.findOrPut(DigestController.new);

    await controller.load();

    expect(controller.phase, DigestPhase.error);
    expect(controller.digest, isNull);
  });

  test('a malformed 200 is an error, not an empty digest', () async {
    // A bare `Http.fake()` answers 200 with an EMPTY body, so the old version
    // of this test never reached `load`'s catch and `isNot(ready)` would have
    // passed on `empty` just as happily. Naming the phase is what makes it a
    // guard: a 200 whose `data` is not a Map is a read that did not give us a
    // digest, and that is not the same as the server having none.
    Http.fake({
      'incidents/digest': Http.response(<String, dynamic>{'data': 'nope'}),
    });
    final DigestController controller = Magic.findOrPut(DigestController.new);

    await controller.load();

    expect(controller.phase, DigestPhase.error);
    expect(controller.digest, isNull);
  });

  test('resetForSession drops the outgoing team digest BEFORE the refetch',
      () async {
    Http.fake({'incidents/digest': Http.response(digestPayload())});
    final DigestController controller = Magic.findOrPut(DigestController.new);
    await controller.load();
    expect(controller.digest, isNotNull);

    // Observed DURING the in-flight window, which is the only place the clear
    // is visible: `load` overwrites `_digest` on success and nulls it on every
    // failure, so asserting after the call completes passes with or without
    // `resetForSession`'s own clear. A digest describes ONE team's week, and
    // the window between the switch and the new answer is exactly when the
    // outgoing team's numbers must not be on screen.
    final Future<void> reset = controller.resetForSession();

    expect(
      controller.digest,
      isNull,
      reason: 'the outgoing team digest must be gone before the refetch lands',
    );
    expect(controller.phase, DigestPhase.loading);

    await reset;
  });
}
