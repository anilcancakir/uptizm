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
    // Bind a fake network driver so the wired save/create/attachMonitor/
    // detachMonitor/reorderMonitors actions resolve the `network` service.
    // Individual tests override it with `Http.fake({...})` to seed a canned
    // envelope, or call `Http.unfake()` to exercise the transport-failure
    // degradation path.
    Http.fake();
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
  // save / create: live `PUT`/`POST` calls against `api/v1/status-pages`,
  // reload (refreshUI) + navigation on success, error toast + stay on
  // failure. Mirrors `monitor_controller.dart:145-221`'s wired precedent.
  // ---------------------------------------------------------------------------

  group('save', () {
    test('PUTs /status-pages/{id} with the mapped field payload', () async {
      final fake = Http.fake({
        'status-pages/*': Http.response({'data': {}}, 200),
      });
      final StatusPageController controller = StatusPageController.instance;
      final StatusPageConfig draft = findStatusPage('acme')!;

      await controller.save(draft);

      fake.assertSent(
        (r) =>
            r.method == 'PUT' &&
            r.url.contains('status-pages/${draft.id}') &&
            r.data is Map &&
            (r.data as Map)['name'] == draft.name &&
            (r.data as Map)['slug'] == draft.slug,
      );
    });

    test('refreshes the bound view on a successful save', () async {
      Http.fake({
        'status-pages/*': Http.response({'data': {}}, 200),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.save(findStatusPage('acme')!);

      expect(notifications, equals(1));
    });

    test('surfaces an error toast and does not refresh on a failed save', () async {
      Http.fake({
        'status-pages/*': Http.response({'message': 'Validation failed'}, 422),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await expectLater(
        () => controller.save(findStatusPage('acme')!),
        returnsNormally,
      );
      expect(notifications, equals(0));
    });

    test(
      'degrades gracefully (no throw) when the network is unavailable',
      () async {
        Http.unfake();
        final StatusPageController controller = StatusPageController.instance;

        await expectLater(
          () => controller.save(findStatusPage('acme')!),
          returnsNormally,
        );
      },
    );
  });

  group('create', () {
    test('POSTs /status-pages with the mapped field payload', () async {
      final fake = Http.fake({
        'status-pages': Http.response({'data': {}}, 201),
      });
      final StatusPageController controller = StatusPageController.instance;
      final StatusPageConfig draft = findStatusPage('internal')!;

      await controller.create(draft);

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('status-pages') &&
            r.data is Map &&
            (r.data as Map)['name'] == draft.name &&
            (r.data as Map)['domain_mode'] == 'custom',
      );
    });

    test('refreshes the bound view on a successful create', () async {
      Http.fake({
        'status-pages': Http.response({'data': {}}, 201),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.create(findStatusPage('acme')!);

      expect(notifications, equals(1));
    });

    test('surfaces an error toast on a failed create', () async {
      Http.fake({
        'status-pages': Http.response({'message': 'Validation failed'}, 422),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await expectLater(
        () => controller.create(findStatusPage('acme')!),
        returnsNormally,
      );
      expect(notifications, equals(0));
    });
  });

  // ---------------------------------------------------------------------------
  // attachMonitor / detachMonitor / reorderMonitors: live monitor-membership
  // actions against the S3 pivot endpoints.
  // ---------------------------------------------------------------------------

  group('attachMonitor', () {
    test('POSTs /status-pages/{pageId}/monitors with the monitor_id', () async {
      final fake = Http.fake({
        'status-pages/acme/monitors': Http.response({'data': {}}, 200),
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.attachMonitor('acme', 'checkout', displayOrder: 2);

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('status-pages/acme/monitors') &&
            r.data is Map &&
            (r.data as Map)['monitor_id'] == 'checkout' &&
            (r.data as Map)['display_order'] == 2,
      );
    });

    test('refreshes the bound view on success', () async {
      Http.fake({
        'status-pages/acme/monitors': Http.response({'data': {}}, 200),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.attachMonitor('acme', 'checkout');

      expect(notifications, equals(1));
    });

    test('surfaces an error toast without throwing on failure', () async {
      Http.fake({
        'status-pages/acme/monitors': Http.response({'message': 'nope'}, 422),
      });
      final StatusPageController controller = StatusPageController.instance;

      await expectLater(
        () => controller.attachMonitor('acme', 'checkout'),
        returnsNormally,
      );
    });
  });

  group('detachMonitor', () {
    test('DELETEs /status-pages/{pageId}/monitors/{monitorId}', () async {
      final fake = Http.fake({
        'status-pages/acme/monitors/checkout': Http.response(null, 204),
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.detachMonitor('acme', 'checkout');

      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url.contains('status-pages/acme/monitors/checkout'),
      );
    });

    test('refreshes the bound view on success', () async {
      Http.fake({
        'status-pages/acme/monitors/checkout': Http.response(null, 204),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.detachMonitor('acme', 'checkout');

      expect(notifications, equals(1));
    });
  });

  group('reorderMonitors', () {
    test('PUTs /status-pages/{pageId}/monitors/reorder with the order', () async {
      final fake = Http.fake({
        'status-pages/acme/monitors/reorder': Http.response(null, 204),
      });
      final StatusPageController controller = StatusPageController.instance;
      final order = [
        {'id': 'checkout', 'display_order': 0},
        {'id': 'api', 'display_order': 1},
      ];

      await controller.reorderMonitors('acme', order);

      fake.assertSent(
        (r) =>
            r.method == 'PUT' &&
            r.url.contains('status-pages/acme/monitors/reorder') &&
            r.data is Map &&
            (r.data as Map)['order'] == order,
      );
    });

    test('refreshes the bound view on success', () async {
      Http.fake({
        'status-pages/acme/monitors/reorder': Http.response(null, 204),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.reorderMonitors('acme', [
        {'id': 'checkout', 'display_order': 0},
      ]);

      expect(notifications, equals(1));
    });
  });
}
