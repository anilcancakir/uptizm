import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:uptizm/app/mocks/status_pages.dart';
import 'package:uptizm/resources/views/status/status_form_support.dart'
    show aiDraftFor;

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.warning() works inside MagicFeedback.showSnackbar
    // (save/create/removeSubscriber call Magic.success, which falls through to
    // a warning log when no navigator context is mounted, as here).
    Magic.singleton('log', () => LogManager());
    // Force-build the lazy GoRouter so MagicRoute.to (used by save/create)
    // does not throw StateError('Router not initialized...'). In production
    // the router is built once at app boot before any controller action
    // fires; accessing the getter here reproduces that precondition without
    // mounting a widget tree.
    MagicRouter.instance.routerConfig;
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('StatusPageController.instance registers and returns a singleton', () {
    final StatusPageController first = StatusPageController.instance;
    final StatusPageController second = StatusPageController.instance;

    expect(identical(first, second), isTrue);
  });

  test('statusPages returns the full fixture list', () {
    final StatusPageController controller = StatusPageController.instance;

    expect(controller.statusPages, equals(statusPages));
  });

  test('configById resolves a known fixture id', () {
    final StatusPageController controller = StatusPageController.instance;

    expect(controller.configById('acme'), equals(findStatusPage('acme')));
  });

  test('configById returns null for an unknown or null id', () {
    final StatusPageController controller = StatusPageController.instance;

    expect(controller.configById('does-not-exist'), isNull);
    expect(controller.configById(null), isNull);
  });

  test('subscribersFor seeds the working copy from the fixture roster', () {
    final StatusPageController controller = StatusPageController.instance;

    expect(controller.subscribersFor('acme'), equals(subscribersFor('acme')));
  });

  test('subscribersFor returns an empty list for a null id', () {
    final StatusPageController controller = StatusPageController.instance;

    expect(controller.subscribersFor(null), isEmpty);
  });

  // ---------------------------------------------------------------------------
  // generateWithAi: returns a fresh draft.
  // ---------------------------------------------------------------------------

  test('generateWithAi returns a non-null draft filled from the monitor ids', () {
    final StatusPageController controller = StatusPageController.instance;
    final List<String> monitorIds = ['api', 'checkout'];

    final StatusPageConfig draft = controller.generateWithAi(monitorIds);

    // StatusPageConfig has no value equality, so compare field-by-field
    // against the pure aiDraftFor fill it delegates to.
    final StatusPageConfig expected = aiDraftFor(monitorIds);
    expect(draft, isNotNull);
    expect(draft.name, equals(expected.name));
    expect(draft.slug, equals(expected.slug));
    expect(draft.metricKeys, equals(expected.metricKeys));
  });

  // ---------------------------------------------------------------------------
  // removeSubscriber: real, observable state mutation on the working roster.
  // ---------------------------------------------------------------------------

  group('removeSubscriber', () {
    test('drops the subscriber from the page roster and shrinks it by one', () {
      final StatusPageController controller = StatusPageController.instance;
      final List<Subscriber> before = List.of(
        controller.subscribersFor('acme'),
      );
      final Subscriber target = before.first;

      controller.removeSubscriber('acme', target);

      final List<Subscriber> after = controller.subscribersFor('acme');
      expect(after.length, equals(before.length - 1));
      expect(after.contains(target), isFalse);
    });

    test('surfaces the remove toast without throwing', () {
      final StatusPageController controller = StatusPageController.instance;
      final Subscriber target = controller.subscribersFor('acme').first;

      expect(
        () => controller.removeSubscriber('acme', target),
        returnsNormally,
      );
    });

    test('notifies listeners via refreshUI', () {
      final StatusPageController controller = StatusPageController.instance;
      final Subscriber target = controller.subscribersFor('acme').first;
      int notifications = 0;
      controller.addListener(() => notifications++);

      controller.removeSubscriber('acme', target);

      expect(notifications, equals(1));
    });
  });

  // ---------------------------------------------------------------------------
  // save / create: toast + navigation side-effects only (mock: nothing else
  // persists), matching the monitor/incident controller precedent.
  // ---------------------------------------------------------------------------

  group('save / create surface a toast and navigate back to the list', () {
    test('save does not throw and does not notify listeners', () {
      final StatusPageController controller = StatusPageController.instance;
      final StatusPageConfig draft = findStatusPage('acme')!;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.save(draft), returnsNormally);
      expect(notifications, equals(0));
    });

    test('create does not throw and does not notify listeners', () {
      final StatusPageController controller = StatusPageController.instance;
      final StatusPageConfig draft = findStatusPage('acme')!;
      int notifications = 0;
      controller.addListener(() => notifications++);

      expect(() => controller.create(draft), returnsNormally);
      expect(notifications, equals(0));
    });
  });
}
