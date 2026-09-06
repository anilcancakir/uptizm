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

  test('a thrown transport failure is an error, not a crash', () async {
    // No stub at all: the fake has no answer for this route.
    Http.fake();
    final DigestController controller = Magic.findOrPut(DigestController.new);

    await controller.load();

    expect(controller.phase, isNot(DigestPhase.ready));
    expect(controller.digest, isNull);
  });

  test('resetForSession drops the outgoing team digest before refetching',
      () async {
    Http.fake({'incidents/digest': Http.response(digestPayload())});
    final DigestController controller = Magic.findOrPut(DigestController.new);
    await controller.load();
    expect(controller.digest, isNotNull);

    // The incoming identity's refetch fails. A digest describes ONE team's
    // week, so the outgoing team's numbers must not survive the switch even
    // when there is nothing to replace them with.
    Http.fake((r) => Http.response(<String, dynamic>{'message': 'down'}, 500));

    await controller.resetForSession();

    expect(controller.digest, isNull);
    expect(controller.phase, DigestPhase.error);
  });
}
