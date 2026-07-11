import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.warning() works inside MagicFeedback.showSnackbar
    // (pause/resume/delete call Magic.success, which falls through to a
    // warning log when no navigator context is mounted, as here).
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver so the wired controller resolves the
    // `network` service. Individual tests override it with `Http.fake({...})`
    // to seed a canned envelope, or call `Http.unfake()` to exercise the
    // network-unavailable degradation path.
    Http.fake();
    // Force-build the lazy GoRouter so MagicRoute.to (used by delete/create/
    // save) does not throw StateError('Router not initialized...'). In
    // production the router is built once at app boot before any controller
    // action fires; accessing the getter here reproduces that precondition
    // without mounting a widget tree.
    MagicRouter.instance.routerConfig;
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('MonitorController.instance registers and returns a singleton', () {
    final MonitorController first = MonitorController.instance;
    final MonitorController second = MonitorController.instance;

    expect(identical(first, second), isTrue);
  });

  // The controller now sources its inventory from `GET /monitors`, so the
  // former `controller.monitors == monitors` fixture-equality assertions are
  // replaced by seeded-envelope decode + degradation assertions against the
  // wired behavior.
  test('reload decodes the monitor inventory from GET /monitors', () async {
    Http.fake({
      'monitors': Http.response({
        'data': [
          {
            'id': 'api',
            'name': 'API',
            'url': 'https://api.uptizm.com',
            'last_status': 'up',
            'last_response_ms': 120,
            'uptime': '99.98%',
            'check_interval_sec': 30,
            'regions': ['us-east', 'eu-west'],
          },
          {
            'id': 'marketing',
            'name': 'Marketing site',
            'status': 'paused',
          },
        ],
      }),
    });
    final MonitorController controller = MonitorController.instance;

    await controller.reload();

    expect(
      controller.monitors.map((MonitorSummary m) => m.id).toList(),
      equals(['api', 'marketing']),
    );
    expect(controller.monitors.first.status, equals(StatusKey.up));
    expect(controller.monitors.first.responseMs, equals(120));
    expect(controller.monitors.last.status, equals(StatusKey.paused));
  });

  test('monitorById resolves a decoded monitor after a reload', () async {
    Http.fake({
      'monitors': Http.response({
        'data': [
          {
            'id': 'api',
            'name': 'API',
            'url': 'https://api.uptizm.com',
            'last_status': 'up',
          },
        ],
      }),
    });
    final MonitorController controller = MonitorController.instance;
    await controller.reload();

    final MonitorSummary? resolved = controller.monitorById('api');

    expect(resolved, isNotNull);
    expect(resolved!.id, equals('api'));
    expect(resolved.name, equals('API'));
  });

  test('monitorById returns null for an unknown or null id', () {
    final MonitorController controller = MonitorController.instance;

    expect(controller.monitorById('does-not-exist'), isNull);
    expect(controller.monitorById(null), isNull);
  });

  test(
    'reload degrades to an empty inventory when the network is unavailable',
    () async {
      // Drop the faked network so `Http.get` resolves an unregistered service:
      // the defensive `reload` must swallow it and never throw out of onInit.
      Http.unfake();
      final MonitorController controller = MonitorController.instance;

      await controller.reload();

      expect(controller.monitors, isEmpty);
    },
  );

  // ---------------------------------------------------------------------------
  // Business actions: toast + navigation side-effects only.
  //
  // The `monitors` fixture is a compile-time `const List`; the pre-controller
  // views never mutated it, so these actions carry no state to mutate and call
  // no refreshUI() (behavior parity, see plan Wave 2 controller-behavior note).
  // Each assertion below confirms both halves of that contract: the action
  // completes without throwing, AND it does not notify listeners.
  // ---------------------------------------------------------------------------

  group('business actions do not mutate state or notify listeners', () {
    test('pause does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.pause(monitors.first.id), returnsNormally);
      expect(notifications, equals(0));
    });

    test('pause on an unknown id is a no-op', () {
      final MonitorController controller = MonitorController.instance;

      expect(() => controller.pause('does-not-exist'), returnsNormally);
    });

    test('resume does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.resume(monitors.first.id), returnsNormally);
      expect(notifications, equals(0));
    });

    test('resume on an unknown id is a no-op', () {
      final MonitorController controller = MonitorController.instance;

      expect(() => controller.resume('does-not-exist'), returnsNormally);
    });

    test('delete does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.delete(monitors.first.id), returnsNormally);
      expect(notifications, equals(0));
    });

    test('delete on an unknown id is a no-op', () {
      final MonitorController controller = MonitorController.instance;

      expect(() => controller.delete('does-not-exist'), returnsNormally);
    });

    test('create does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.create(), returnsNormally);
      expect(notifications, equals(0));
    });

    test('save does not throw and does not notify listeners', () {
      final MonitorController controller = MonitorController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.save(monitors.first.id), returnsNormally);
      expect(notifications, equals(0));
    });
  });
}
