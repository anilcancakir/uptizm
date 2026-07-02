import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/mocks/monitors.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.warning() works inside MagicFeedback.showSnackbar
    // (pause/resume/delete call Magic.success, which falls through to a
    // warning log when no navigator context is mounted, as here).
    Magic.singleton('log', () => LogManager());
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

  test('monitors returns the full fixture list', () {
    final MonitorController controller = MonitorController.instance;

    expect(controller.monitors, equals(monitors));
  });

  test('monitorById resolves a known fixture id', () {
    final MonitorController controller = MonitorController.instance;
    final MonitorSummary expected = monitors.first;

    expect(controller.monitorById(expected.id), equals(expected));
  });

  test('monitorById returns null for an unknown or null id', () {
    final MonitorController controller = MonitorController.instance;

    expect(controller.monitorById('does-not-exist'), isNull);
    expect(controller.monitorById(null), isNull);
  });

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
