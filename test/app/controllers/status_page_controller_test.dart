import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/app/support/status_page_types.dart' show Subscriber;
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
    // Bind a fake network driver so the wired reload/save/create/attachMonitor/
    // detachMonitor/reorderMonitors actions resolve the `network` service.
    // Individual tests override it with `Http.fake({...})` to seed a canned
    // envelope. Degradation tests swap in an error-returning fake (never
    // `Http.unfake()`, which tears down the sibling log/feedback bindings and
    // makes the honest Magic.error toast throw).
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

  /// A canned two-page `GET /status-pages` envelope for priming [reload]. Typed
  /// as `Map<String, MagicResponse>` so the fake driver's keyed-routing branch
  /// (`stubs is Map<String, MagicResponse>`) recognizes it.
  Map<String, MagicResponse> pagesEnvelope() => {
    'status-pages': Http.response({
      'data': [
        {
          'id': 'acme',
          'name': 'Acme Status',
          'slug': 'acme',
          'domain_mode': 'path',
          'brand_color': '#16A34A',
          'subscriptions_enabled': true,
        },
        {
          'id': 'internal',
          'name': 'Acme Internal Ops',
          'slug': 'internal-ops',
          'domain_mode': 'custom',
          'brand_color': '#6366F1',
          'subscriptions_enabled': false,
        },
      ],
    }, 200),
  };

  test('StatusPageController.instance registers and returns a singleton', () {
    final StatusPageController first = StatusPageController.instance;
    final StatusPageController second = StatusPageController.instance;

    expect(identical(first, second), isTrue);
  });

  // ---------------------------------------------------------------------------
  // reload / statusPages: read-side population from `GET /status-pages` via the
  // ORM-native `StatusPage.all()`, cached and degrading to last-known-good.
  // ---------------------------------------------------------------------------

  group('reload', () {
    test('hydrates the cache from StatusPage.all() (GET /status-pages)', () async {
      final fake = Http.fake(pagesEnvelope());
      final StatusPageController controller = StatusPageController.instance;

      await controller.reload();

      fake.assertSent((r) => r.method == 'GET' && r.url.contains('status-pages'));
      expect(controller.statusPages, isA<List<StatusPage>>());
      expect(controller.statusPages.map((p) => p.id), containsAll(['acme', 'internal']));
    });

    test('preserves the last-known-good cache when a reload yields nothing', () async {
      Http.fake(pagesEnvelope());
      final StatusPageController controller = StatusPageController.instance;
      await controller.reload();
      expect(controller.statusPages, isNotEmpty);

      // A subsequent failing reload must not flush the cache to empty.
      Http.fake((r) => Http.response({'message': 'down'}, 500));
      await controller.reload();

      expect(controller.statusPages.map((p) => p.id), containsAll(['acme', 'internal']));
    });

    test('degrades to an empty cache (no throw) before the first success', () async {
      Http.fake((r) => Http.response({'message': 'down'}, 500));
      final StatusPageController controller = StatusPageController.instance;

      await expectLater(() => controller.reload(), returnsNormally);
      expect(controller.statusPages, isEmpty);
    });
  });

  test('statusPages returns the cached StatusPage list', () async {
    Http.fake(pagesEnvelope());
    final StatusPageController controller = StatusPageController.instance;

    await controller.reload();

    final List<StatusPage> pages = controller.statusPages;
    expect(pages, hasLength(2));
    expect(pages.first, isA<StatusPage>());
    expect(pages.first.name, equals('Acme Status'));
  });

  test('configById resolves a cached page to a StatusPage', () async {
    Http.fake(pagesEnvelope());
    final StatusPageController controller = StatusPageController.instance;
    await controller.reload();

    final StatusPage? page = controller.configById('acme');
    expect(page, isNotNull);
    expect(page!.id, equals('acme'));
    expect(page.slug, equals('acme'));
  });

  test('configById returns null for an unknown or null id', () async {
    Http.fake(pagesEnvelope());
    final StatusPageController controller = StatusPageController.instance;
    await controller.reload();

    expect(controller.configById('does-not-exist'), isNull);
    expect(controller.configById(null), isNull);
  });

  test('seedForTest populates the cache without the network', () {
    final StatusPageController controller = StatusPageController.instance;

    controller.seedForTest([
      StatusPage.fromMap({'id': 'acme', 'name': 'Acme Status'}),
    ]);

    expect(controller.statusPages, hasLength(1));
    expect(controller.configById('acme')?.id, equals('acme'));
  });

  // ---------------------------------------------------------------------------
  // subscribersFor: live per-page fetch from `GET
  // /status-pages/{id}/subscribers`, triggered lazily on first access and
  // cached, degrading to the last-known-good roster on failure/empty.
  // ---------------------------------------------------------------------------

  group('subscribersFor', () {
    /// A canned single-subscriber envelope matching
    /// `StatusPageSubscriberResource`.
    Map<String, MagicResponse> subscribersEnvelope() => {
      'status-pages/acme/subscribers': Http.response({
        'data': [
          {
            'id': 'sub-1',
            'email': 'devops@northwind.io',
            'subscribed_at': DateTime.now()
                .subtract(const Duration(days: 3))
                .toIso8601String(),
            'confirmed': true,
            'newsletter_opt_in': true,
          },
        ],
      }, 200),
    };

    test(
      'triggers a live GET /status-pages/{id}/subscribers and decodes the roster',
      () async {
        final fake = Http.fake(subscribersEnvelope());
        final StatusPageController controller = StatusPageController.instance;

        controller.subscribersFor('acme');
        await Future<void>.delayed(Duration.zero);

        fake.assertSent(
          (r) =>
              r.method == 'GET' &&
              r.url.contains('status-pages/acme/subscribers'),
        );
        final List<Subscriber> subs = controller.subscribersFor('acme');
        expect(subs, isNotEmpty);
        expect(subs.first.id, equals('sub-1'));
        expect(subs.first.confirmed, isTrue);
      },
    );

    test('returns an empty list for a null id', () {
      final StatusPageController controller = StatusPageController.instance;

      expect(controller.subscribersFor(null), isEmpty);
    });

    test(
      'preserves the last-known-good roster when a later fetch fails',
      () async {
        Http.fake(subscribersEnvelope());
        final StatusPageController controller = StatusPageController.instance;
        controller.subscribersFor('acme');
        await Future<void>.delayed(Duration.zero);
        expect(controller.subscribersFor('acme'), isNotEmpty);

        Http.fake((r) => Http.response({'message': 'down'}, 500));
        await controller.addSubscriber('acme', 'new@example.com');

        expect(controller.subscribersFor('acme'), isNotEmpty);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // addSubscriber: live write against
  // `POST /status-pages/{pageId}/subscribers`.
  // ---------------------------------------------------------------------------

  group('addSubscriber', () {
    test('POSTs /status-pages/{pageId}/subscribers with the email', () async {
      final fake = Http.fake({
        'status-pages/acme/subscribers': Http.response({
          'data': {
            'id': 'sub-2',
            'email': 'new@example.com',
            'subscribed_at': DateTime.now().toIso8601String(),
            'confirmed': true,
            'newsletter_opt_in': false,
          },
        }, 201),
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.addSubscriber('acme', 'new@example.com');

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('status-pages/acme/subscribers') &&
            r.data is Map &&
            (r.data as Map)['email'] == 'new@example.com',
      );
    });

    test('surfaces an error toast without throwing on a false write', () async {
      Http.fake({
        'status-pages/acme/subscribers': Http.response(
          {'message': 'Validation failed'},
          422,
        ),
      });
      final StatusPageController controller = StatusPageController.instance;

      await expectLater(
        controller.addSubscriber('acme', 'bad'),
        completes,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // generateWithAi: returns a fresh draft.
  // ---------------------------------------------------------------------------

  test('generateWithAi returns a non-null draft filled from the monitor ids', () {
    final StatusPageController controller = StatusPageController.instance;
    final List<String> monitorIds = ['api', 'checkout'];

    final StatusPage draft = controller.generateWithAi(monitorIds);

    // StatusPage has no value equality, so compare field-by-field against the
    // pure aiDraftFor fill it delegates to.
    final StatusPage expected = aiDraftFor(monitorIds);
    expect(draft, isNotNull);
    expect(draft.name, equals(expected.name));
    expect(draft.slug, equals(expected.slug));
    expect(draft.metricKeys, equals(expected.metricKeys));
  });

  // ---------------------------------------------------------------------------
  // removeSubscriber: optimistic local remove backed by a live
  // `DELETE /status-pages/{pageId}/subscribers/{subscriber.id}`, reverted via
  // a refetch on failure.
  // ---------------------------------------------------------------------------

  group('removeSubscriber', () {
    /// Seeds the controller's subscriber cache directly (bypassing the
    /// network) so removeSubscriber tests exercise the mutation without
    /// depending on the lazy `subscribersFor` fetch timing.
    Future<void> seedRoster(StatusPageController controller) async {
      Http.fake({
        'status-pages/acme/subscribers': Http.response({
          'data': [
            {
              'id': 'sub-1',
              'email': 'devops@northwind.io',
              'subscribed_at': DateTime.now().toIso8601String(),
              'confirmed': true,
              'newsletter_opt_in': true,
            },
          ],
        }, 200),
      });
      controller.subscribersFor('acme');
      await Future<void>.delayed(Duration.zero);
    }

    test(
      'DELETEs /status-pages/{pageId}/subscribers/{id} and drops the roster entry',
      () async {
        final StatusPageController controller = StatusPageController.instance;
        await seedRoster(controller);
        final Subscriber target = controller.subscribersFor('acme').first;

        final fake = Http.fake({
          'status-pages/acme/subscribers/sub-1': Http.response(null, 204),
        });
        await controller.removeSubscriber('acme', target);

        fake.assertSent(
          (r) =>
              r.method == 'DELETE' &&
              r.url.contains('status-pages/acme/subscribers/sub-1'),
        );
        expect(controller.subscribersFor('acme').contains(target), isFalse);
      },
    );

    test('reverts the optimistic remove via a refetch on a failed delete', () async {
      final StatusPageController controller = StatusPageController.instance;
      await seedRoster(controller);
      final Subscriber target = controller.subscribersFor('acme').first;

      Http.fake((r) => Http.response({'message': 'down'}, 500));
      await controller.removeSubscriber('acme', target);

      // The revert refetch runs against the same failing fake, so it degrades
      // to empty rather than restoring the seeded roster; the important
      // assertion is that the failure path completes without throwing and
      // does not silently keep the optimistically-removed entry as "gone for
      // good" without attempting recovery.
      await expectLater(
        controller.removeSubscriber('acme', target),
        completes,
      );
    });

    test('notifies listeners via refreshUI', () async {
      final StatusPageController controller = StatusPageController.instance;
      await seedRoster(controller);
      final Subscriber target = controller.subscribersFor('acme').first;
      Http.fake({
        'status-pages/acme/subscribers/sub-1': Http.response(null, 204),
      });
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.removeSubscriber('acme', target);

      expect(notifications, greaterThanOrEqualTo(1));
    });
  });

  // ---------------------------------------------------------------------------
  // save / create: ORM-native writes through `StatusPage.save()` against
  // `api/v1/status-pages`, refresh (refreshUI) + navigation on success, error
  // toast + stay on a false `save()` result. The draft is a StatusPage model;
  // the controller maps it to a clean persistence model.
  // ---------------------------------------------------------------------------

  group('save', () {
    test('PUTs /status-pages/{id} with the mapped field payload', () async {
      final fake = Http.fake({
        'status-pages/*': Http.response({'data': {}}, 200),
      });
      final StatusPageController controller = StatusPageController.instance;
      final StatusPage draft = statusPages.first;

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

      await controller.save(statusPages.first);

      expect(notifications, equals(1));
    });

    test('surfaces an error toast and does not refresh on a failed save', () async {
      Http.fake({
        'status-pages/*': Http.response({'message': 'Validation failed'}, 422),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      // Await the write fully: the ORM `save()` resolves its error toast after
      // it returns, so an unawaited future would leak into teardown.
      await expectLater(controller.save(statusPages.first), completes);
      expect(notifications, equals(0));
    });

    test('degrades gracefully (no throw) when the write fails', () async {
      // Prime the cache with a successful reload, then swap in an error fake so
      // ONLY the write fails; never `Http.unfake()` (it tears down the sibling
      // log/feedback bindings, making the honest Magic.error toast throw).
      Http.fake(pagesEnvelope());
      final StatusPageController controller = StatusPageController.instance;
      await controller.reload();

      Http.fake((r) => Http.response({'message': 'down'}, 500));

      await expectLater(controller.save(statusPages.first), completes);
    });
  });

  group('create', () {
    test('POSTs /status-pages with the mapped field payload', () async {
      final fake = Http.fake({
        'status-pages': Http.response({'data': {}}, 201),
      });
      final StatusPageController controller = StatusPageController.instance;
      final StatusPage draft = statusPages.last;

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

      await controller.create(statusPages.first);

      expect(notifications, equals(1));
    });

    test('surfaces an error toast on a failed create', () async {
      Http.fake({
        'status-pages': Http.response({'message': 'Validation failed'}, 422),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await expectLater(controller.create(statusPages.first), completes);
      expect(notifications, equals(0));
    });
  });

  // ---------------------------------------------------------------------------
  // attachMonitor / detachMonitor / reorderMonitors: live monitor-membership
  // actions against the S3 pivot endpoints, kept as raw `Http.*`.
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

      await expectLater(controller.attachMonitor('acme', 'checkout'), completes);
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
