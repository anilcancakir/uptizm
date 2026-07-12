import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/assistant_controller.dart';

void main() {
  // The failure path surfaces a `Magic.error` toast, which reads
  // `MagicRouter.instance.navigatorKey.currentContext` -- that getter touches
  // `WidgetsBinding.instance` even with no widget tree mounted, so a plain
  // `test()` needs the binding initialized once up front (falls back to a
  // logged warning since no context is mounted, matching
  // `monitor_metrics_controller_test.dart`'s documented pattern).
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.error() works inside the failure paths.
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver so the wired controller resolves the
    // `network` service. Individual tests override it with `Http.fake({...})`
    // to seed a canned envelope, or call `Http.unfake()` to exercise the
    // network-unavailable degradation path.
    Http.fake();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('AssistantController.instance registers and returns a singleton', () {
    final AssistantController first = AssistantController.instance;
    final AssistantController second = AssistantController.instance;

    expect(identical(first, second), isTrue);
  });

  group('ask', () {
    test(
      'posts the question to /assistant and returns the grounded answer',
      () async {
        Http.fake({
          'assistant': Http.response({
            'data': {
              'answer': 'Your API monitor is up with 99.98% uptime.',
              'confidence': 'high',
              'stripped_citations': [],
            },
          }),
        });
        final AssistantController controller = AssistantController.instance;

        final String? answer = await controller.ask('Which monitors are slow?');

        expect(answer, equals('Your API monitor is up with 99.98% uptime.'));
      },
    );

    test(
      'returns null and does not throw when the network is unavailable',
      () async {
        Http.unfake();
        final AssistantController controller = AssistantController.instance;

        final String? answer = await controller.ask('Which monitors are slow?');

        expect(answer, isNull);
      },
    );

    test('returns null on a non-2xx response', () async {
      Http.fake({
        'assistant': Http.response({'message': 'Server error'}, 500),
      });
      final AssistantController controller = AssistantController.instance;

      final String? answer = await controller.ask('Which monitors are slow?');

      expect(answer, isNull);
    });

    test('returns null on a malformed payload', () async {
      Http.fake({
        'assistant': Http.response({'not_data': true}),
      });
      final AssistantController controller = AssistantController.instance;

      final String? answer = await controller.ask('Which monitors are slow?');

      expect(answer, isNull);
    });
  });
}
