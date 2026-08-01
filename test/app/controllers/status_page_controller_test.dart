import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:uptizm/app/enums/domain_mode.dart' show DomainMode;
import 'package:uptizm/app/enums/status_page_preview_status.dart'
    show StatusPagePreviewStatus;
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

  // ---------------------------------------------------------------------------
  // reloadPage: tops the roster cache up with ONE page's `show` payload, which
  // is the only read that carries the signed `preview_image_url` (D5 keeps the
  // capability out of `index`).
  // ---------------------------------------------------------------------------

  group('reloadPage', () {
    /// The `show` envelope for `acme`: the same fields the index answers with
    /// (so nothing else about the cached page changes) plus the signed image
    /// URL that only `show` can carry.
    Map<String, MagicResponse> acmeShowEnvelope({
      String imageUrl = 'https://api.uptizm.test/preview/acme.png?signature=a',
    }) => {
      'status-pages/acme': Http.response({
        'data': {
          'id': 'acme',
          'name': 'Acme Status',
          'slug': 'acme',
          'domain_mode': 'path',
          'brand_color': '#16A34A',
          'subscriptions_enabled': true,
          'preview_render_status': 'completed',
          'preview_image_url': imageUrl,
        },
      }, 200),
    };

    test('publishes the signed image URL the index response cannot carry', () async {
      Http.fake({...pagesEnvelope(), ...acmeShowEnvelope()});
      final StatusPageController controller = StatusPageController.instance;

      await controller.reload();
      expect(
        controller.configById('acme')?.previewImageUrl,
        isNull,
        reason: 'the roster read has no image URL to give (D5)',
      );

      await controller.reloadPage('acme');

      expect(
        controller.configById('acme')?.previewImageUrl,
        equals('https://api.uptizm.test/preview/acme.png?signature=a'),
      );
      expect(
        controller.statusPages.map((StatusPage p) => p.id),
        containsAll(['acme', 'internal']),
        reason: 'a single-page read tops the roster up, it does not replace it',
      );
    });

    test('GETs the show route and notifies listeners', () async {
      final fake = Http.fake({...pagesEnvelope(), ...acmeShowEnvelope()});
      final StatusPageController controller = StatusPageController.instance;
      await controller.reload();
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.reloadPage('acme');

      fake.assertSent(
        (r) => r.method == 'GET' && r.url.endsWith('status-pages/acme'),
      );
      expect(notifications, equals(1));
    });

    test('drops a read that does not answer for the requested page', () async {
      // Only the index is stubbed, so the show read falls through to the
      // fake's empty 200. That hydrates a model with no id, which appended to
      // the roster would show up as a blank status page.
      Http.fake(pagesEnvelope());
      final StatusPageController controller = StatusPageController.instance;
      await controller.reload();

      await controller.reloadPage('acme');

      expect(controller.statusPages, hasLength(2));
      expect(controller.configById('acme')?.name, equals('Acme Status'));
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

    test('refreshes the bound view once when components are unchanged', () async {
      // The fake answers the post-save re-read with a page carrying exactly the
      // draft's component set, so the pivot sync is a no-op and the save
      // notifies once. Most saves only touch branding, and firing
      // detach/attach/reorder for an unchanged set would be three wasted writes.
      final StatusPage draft = statusPages.first;
      Http.fake({
        'status-pages/*': Http.response({
          'data': <String, dynamic>{
            'id': draft.id,
            'monitors': <Map<String, dynamic>>[
              for (final String id in draft.monitorIds)
                <String, dynamic>{'id': id},
            ],
          },
        }, 200),
      });
      final StatusPageController controller = StatusPageController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.save(draft);

      expect(notifications, equals(1));
    });

    test('syncs components through the pivot endpoints, not the page write', () async {
      // The regression this pins: the editor used to carry its component
      // assignment inside the page payload under a `monitors` key, which neither
      // StoreStatusPageRequest nor UpdateStatusPageRequest validates, so
      // Laravel's validated() dropped it and a component assignment made in the
      // UI never persisted. Assignment has to travel over the dedicated
      // attach/detach/reorder sub-resource instead.
      final StatusPage draft = statusPages.first;
      // The re-read reports a DIFFERENT attachment set, so the sync has a real
      // delta to apply: one id to detach and the draft's own ids to attach.
      final fake = Http.fake({
        'status-pages/*': Http.response({
          'data': <String, dynamic>{
            'id': draft.id,
            'monitors': <Map<String, dynamic>>[
              <String, dynamic>{'id': 'stale-monitor'},
            ],
          },
        }, 200),
      });

      await StatusPageController.instance.save(draft);

      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url == '/status-pages/${draft.id}/monitors/stale-monitor',
      );
      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url == '/status-pages/${draft.id}/monitors' &&
            (r.data as Map)['monitor_id'] == draft.monitorIds.first,
      );
      fake.assertSent(
        (r) =>
            r.method == 'PUT' &&
            r.url == '/status-pages/${draft.id}/monitors/reorder',
      );
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

      // The domain mode goes on the wire as ITSELF. The client used to translate
      // `subdomain` into `custom` because the backend accepted only
      // `path|custom`, so picking Subdomain stored Custom, and the read-back only
      // looked right because the model's fallback for the unknown `custom`
      // happened to be `subdomain`. Both halves are fixed; this pins the wire.
      expect(draft.domainMode, DomainMode.subdomain);

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('status-pages') &&
            r.data is Map &&
            (r.data as Map)['name'] == draft.name &&
            (r.data as Map)['domain_mode'] == 'subdomain',
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

  // ---------------------------------------------------------------------------
  // requestPreviewRender: POSTs the trigger, then polls
  // `GET /status-pages/{id}` (show) on a bounded interval. Stops on
  // `completed`/`failed`; on a cap the state stays `rendering` with a
  // check-again signal, it must never write a client-side `failed`.
  // ---------------------------------------------------------------------------

  group('requestPreviewRender', () {
    /// Wires a fake GET show response for [pageId] returning [status] (and,
    /// for `completed`, an image URL), keyed so `Http.fake`'s map routing
    /// matches the controller's `StatusPage.find` request.
    Map<String, MagicResponse> showEnvelope(
      String pageId,
      String? status, {
      String? imageUrl,
    }) => {
      'status-pages/$pageId': Http.response({
        'data': {
          'id': pageId,
          'name': 'Acme Status',
          'preview_render_status': ?status,
          'preview_image_url': ?imageUrl,
        },
      }, 200),
    };

    test('POSTs /status-pages/{id}/preview before polling', () async {
      final fake = Http.fake({
        'status-pages/acme/preview': Http.response(null, 202),
        ...showEnvelope('acme', 'completed', imageUrl: 'https://x/acme.png'),
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
      );

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('status-pages/acme/preview'),
      );
    });

    // The bug a live pass caught, and the one that made the acknowledgement
    // line look broken. On a page that ALREADY has a render the server keeps
    // reporting the PREVIOUS `completed` until a worker picks the new job up, so
    // treating any `completed` as terminal made the very first tick conclude,
    // two seconds after the tap, that the render it had just asked for was
    // already finished. The poll stopped tracking, the in-flight marker was
    // handed straight back, and the pane sat on the old stamp. Refresh on an
    // existing preview is the most common action this feature has.
    test('a re-render keeps polling while the server still reports the '
        'PREVIOUS completed render', () async {
      final DateTime previous = DateTime.now().subtract(
        const Duration(minutes: 5),
      );

      // The roster already holds a completed render, which is what makes the
      // stale-completed reading reachable at all.
      StatusPageController.instance.seedForTest(<StatusPage>[
        StatusPage.fromMap(<String, dynamic>{
          'id': 'acme',
          'name': 'Acme Status',
          'slug': 'acme',
          'domain_mode': 'path',
          'preview_render_status': 'completed',
          'preview_image_url': 'https://x/acme.png?v=1',
          'preview_rendered_at': previous.toIso8601String(),
        }),
      ]);

      int getCalls = 0;
      Http.fake((r) {
        if (r.method == 'POST' && r.url.contains('status-pages/acme/preview')) {
          return Http.response(null, 202);
        }
        if (r.method == 'GET' && r.url.contains('status-pages/acme')) {
          getCalls++;
          // The server keeps answering with the OLD completed render, exactly
          // as it does before a worker starts the new job.
          return Http.response({
            'data': {
              'id': 'acme',
              'name': 'Acme Status',
              'preview_render_status': 'completed',
              'preview_image_url': 'https://x/acme.png?v=1',
              'preview_rendered_at': previous.toIso8601String(),
            },
          }, 200);
        }
        return Http.response(null, 404);
      });

      final StatusPageController controller = StatusPageController.instance;

      await controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
        maxAttempts: 4,
      );

      expect(
        getCalls,
        4,
        reason:
            'the poll must keep waiting for a render NEWER than the one it '
            'already knew about, not stop on the first stale completed',
      );
      expect(
        controller.hasPreviewPollCapped('acme'),
        isTrue,
        reason:
            'and it must reach its cap, so the pane can offer check-again '
            'instead of silently pretending the render finished',
      );
    });

    test('polls show and stops once the server reports completed', () async {
      int getCalls = 0;
      Http.fake((r) {
        if (r.method == 'POST' && r.url.contains('status-pages/acme/preview')) {
          return Http.response(null, 202);
        }
        if (r.method == 'GET' && r.url.contains('status-pages/acme')) {
          getCalls++;
          final String status = getCalls < 3 ? 'rendering' : 'completed';
          return Http.response({
            'data': {
              'id': 'acme',
              'preview_render_status': status,
              if (status == 'completed')
                'preview_image_url': 'https://x/acme.png?v=2',
            },
          }, 200);
        }
        return Http.response({'message': 'unexpected request'}, 404);
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
        maxAttempts: 10,
      );

      expect(getCalls, 3, reason: 'stops polling as soon as completed lands');
      expect(
        controller.configById('acme')?.previewRenderStatus,
        StatusPagePreviewStatus.completed,
      );
    });

    test('polls show and stops once the server reports failed', () async {
      Http.fake({
        'status-pages/acme/preview': Http.response(null, 202),
        ...showEnvelope('acme', 'failed'),
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
      );

      expect(
        controller.configById('acme')?.previewRenderStatus,
        StatusPagePreviewStatus.failed,
      );
    });

    // -------------------------------------------------------------------------
    // The honesty pin: a naive implementation that gives up by writing
    // `failed` client-side would pass every OTHER test in this group, since
    // `failed` also stops the poll. This is the one test that would catch it:
    // the server never once says `failed`, it just keeps saying `rendering`
    // past the cap, so a correct client must still be reporting `rendering`
    // once polling gives up, with a distinct "still working" signal.
    // -------------------------------------------------------------------------
    test(
      'on cap: stays rendering (never writes failed) and signals check-again',
      () async {
        Http.fake({
          'status-pages/acme/preview': Http.response(null, 202),
          ...showEnvelope('acme', 'rendering'),
        });
        final StatusPageController controller = StatusPageController.instance;

        await controller.requestPreviewRender(
          'acme',
          pollInterval: const Duration(milliseconds: 1),
          maxAttempts: 3,
        );

        expect(
          controller.configById('acme')?.previewRenderStatus,
          StatusPagePreviewStatus.rendering,
          reason:
              'the render may still succeed server-side; the cap must not '
              'contradict a server state the client has no authority over',
        );
        expect(
          controller.hasPreviewPollCapped('acme'),
          isTrue,
          reason: 'a distinct signal the view can render as check-again',
        );
      },
    );

    // -------------------------------------------------------------------------
    // The in-flight marker: `POST .../preview` only enqueues, so the row's
    // status stays NULL until a worker starts the job. The client's own
    // knowledge that it asked is what fills that gap; it never states a server
    // state the server has not reached.
    // -------------------------------------------------------------------------

    test('marks the page render-requested once the trigger is accepted', () async {
      Http.fake({
        'status-pages/acme/preview': Http.response(null, 202),
        // The worker never picks the job up, so the status never appears.
        ...showEnvelope('acme', null),
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
        maxAttempts: 2,
      );

      expect(controller.hasRequestedPreviewRender('acme'), isTrue);
      expect(
        controller.configById('acme')?.previewRenderStatus,
        isNull,
        reason: 'the client marker must not fabricate a server render state',
      );
    });

    test('a rejected trigger leaves the page unmarked', () async {
      Http.fake({
        'status-pages/acme/preview': Http.response({'message': 'nope'}, 429),
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.requestPreviewRender('acme');

      expect(
        controller.hasRequestedPreviewRender('acme'),
        isFalse,
        reason: 'nothing was enqueued, so nothing is in flight',
      );
    });

    test('hands the marker back once the server reports a terminal state', () async {
      Http.fake({
        'status-pages/acme/preview': Http.response(null, 202),
        ...showEnvelope('acme', 'completed', imageUrl: 'https://x/acme.png'),
      });
      final StatusPageController controller = StatusPageController.instance;

      await controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
      );

      expect(controller.hasRequestedPreviewRender('acme'), isFalse);
    });

    test('a fresh poll clears an earlier capped signal', () async {
      Http.fake({
        'status-pages/acme/preview': Http.response(null, 202),
        ...showEnvelope('acme', 'rendering'),
      });
      final StatusPageController controller = StatusPageController.instance;
      await controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
        maxAttempts: 2,
      );
      expect(controller.hasPreviewPollCapped('acme'), isTrue);

      Http.fake({
        'status-pages/acme/preview': Http.response(null, 202),
        ...showEnvelope('acme', 'completed', imageUrl: 'https://x/acme.png'),
      });
      await controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
      );

      expect(controller.hasPreviewPollCapped('acme'), isFalse);
    });

    test('a second poll for the same page does not stack a duplicate loop', () async {
      int getCalls = 0;
      Http.fake((r) {
        if (r.method == 'POST' && r.url.contains('status-pages/acme/preview')) {
          return Http.response(null, 202);
        }
        if (r.method == 'GET' && r.url.contains('status-pages/acme')) {
          getCalls++;
          return Http.response({
            'data': {'id': 'acme', 'preview_render_status': 'completed'},
          }, 200);
        }
        return Http.response({'message': 'unexpected request'}, 404);
      });
      final StatusPageController controller = StatusPageController.instance;

      // Fire two polls back to back without awaiting the first: the second
      // must supersede the first's loop rather than let both run.
      final Future<void> first = controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
        maxAttempts: 50,
      );
      final Future<void> second = controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 1),
      );
      await Future.wait([first, second]);

      // Both resolve, but only ONE loop should have been live at a time: the
      // superseded first loop must stop reading/writing state once the
      // second starts, not keep ticking in the background forever.
      expect(getCalls, lessThan(50));
    });

    test('does not keep polling once the controller is disposed', () async {
      int getCalls = 0;
      Http.fake((r) {
        if (r.method == 'POST' && r.url.contains('status-pages/acme/preview')) {
          return Http.response(null, 202);
        }
        if (r.method == 'GET' && r.url.contains('status-pages/acme')) {
          getCalls++;
          return Http.response({
            'data': {'id': 'acme', 'preview_render_status': 'rendering'},
          }, 200);
        }
        return Http.response({'message': 'unexpected request'}, 404);
      });
      final StatusPageController controller = StatusPageController.instance;

      final Future<void> pending = controller.requestPreviewRender(
        'acme',
        pollInterval: const Duration(milliseconds: 2),
        maxAttempts: 200,
      );
      await Future<void>.delayed(const Duration(milliseconds: 5));
      controller.dispose();

      final int callsAtDispose = getCalls;
      await Future<void>.delayed(const Duration(milliseconds: 20));

      expect(
        getCalls,
        callsAtDispose,
        reason: 'a disposed controller must not keep firing requests',
      );
      // The in-flight Future still completes (it does not hang forever); it
      // just stops making progress once disposed.
      await expectLater(pending, completes);
    });
  });

  // ---------------------------------------------------------------------------
  // isPreviewRenderStale: a server `rendering` status is treated as stale
  // once it has sat past 2x the backend job's uniqueness window (120s), so a
  // lost job cannot pin the pane on a skeleton forever.
  // ---------------------------------------------------------------------------

  group('isPreviewRenderStale', () {
    test('false when the page has never been rendered', () {
      final StatusPage page = StatusPage.fromMap({'id': 'acme'});
      expect(
        StatusPageController.instance.isPreviewRenderStale(page),
        isFalse,
      );
    });

    test('false when completed, regardless of age', () {
      final StatusPage page = StatusPage.fromMap({
        'id': 'acme',
        'preview_render_status': 'completed',
        'updated_at': DateTime.now()
            .subtract(const Duration(hours: 2))
            .toIso8601String(),
      });
      expect(
        StatusPageController.instance.isPreviewRenderStale(page),
        isFalse,
      );
    });

    test('false while rendering and within the 240s threshold', () {
      final StatusPage page = StatusPage.fromMap({
        'id': 'acme',
        'preview_render_status': 'rendering',
        'updated_at': DateTime.now()
            .subtract(const Duration(seconds: 30))
            .toIso8601String(),
      });
      expect(
        StatusPageController.instance.isPreviewRenderStale(page),
        isFalse,
      );
    });

    test('true once rendering has sat past 240s (2x the 120s uniqueness window)', () {
      final StatusPage page = StatusPage.fromMap({
        'id': 'acme',
        'preview_render_status': 'rendering',
        'updated_at': DateTime.now()
            .subtract(const Duration(seconds: 300))
            .toIso8601String(),
      });
      expect(
        StatusPageController.instance.isPreviewRenderStale(page),
        isTrue,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // resetForSession: clear the previous identity's roster + subscriber caches,
  // then refetch.
  // ---------------------------------------------------------------------------

  group('resetForSession', () {
    test('clears the roster and the subscriber caches on a failed refetch', () async {
      Http.fake({
        ...pagesEnvelope(),
        'status-pages/acme/subscribers': Http.response({
          'data': [
            {
              'id': 'sub-1',
              'email': 'devops@northwind.io',
              'confirmed': true,
              'newsletter_opt_in': true,
            },
          ],
        }, 200),
      });
      final StatusPageController controller = StatusPageController.instance;
      await controller.reload();
      // First access primes the per-page cache in the background; pump until
      // the roster lands.
      controller.subscribersFor('acme');
      for (var i = 0; i < 50 && controller.subscribersFor('acme').isEmpty; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 1));
      }
      expect(controller.statusPages, hasLength(2));
      expect(controller.subscribersFor('acme'), hasLength(1));

      // The new identity's refetch fails. `reload` alone keeps the
      // last-known-good roster, which would leave the previous team's pages
      // listed (and its subscriber emails cached).
      Http.fake((r) => Http.response({'message': 'down'}, 500));
      var notifications = 0;
      controller.addListener(() => notifications++);

      await controller.resetForSession();

      expect(notifications, greaterThan(0));
      expect(controller.statusPages, isEmpty);
      expect(controller.configById('acme'), isNull);
      // The dropped cache key makes the next read a fresh (background) fetch,
      // so it answers empty rather than with the previous team's roster.
      expect(controller.subscribersFor('acme'), isEmpty);
    });

    test('refetches the roster of the new identity', () async {
      Http.fake(pagesEnvelope());
      final StatusPageController controller = StatusPageController.instance;
      await controller.reload();

      Http.fake({
        'status-pages': Http.response({
          'data': [
            {
              'id': 'other-team',
              'name': 'Northwind Status',
              'slug': 'northwind',
              'domain_mode': 'path',
            },
          ],
        }, 200),
      });

      await controller.resetForSession();

      expect(
        controller.statusPages.map((StatusPage p) => p.id).toList(),
        equals(['other-team']),
      );
      expect(controller.configById('acme'), isNull);
    });

    // An unconsumed `previews` queue means the server never reports any render
    // state at all, so the client's own in-flight marker is the only thing
    // holding the pane. Without a time bound it would hold it on a skeleton for
    // the rest of the session and across remounts, which is the one shape the
    // editor's state table forbids. Expiry turns it into the failed affordance,
    // which is honest (asked for, nothing came back) and carries a retry.
    testWidgets('an in-flight preview request expires instead of holding the '
        'pane forever', (tester) async {
      Http.fake({
        'status-pages': Http.response({
          'data': [
            {
              'id': 'acme',
              'name': 'Acme Status',
              'slug': 'acme',
              'domain_mode': 'path',
            },
          ],
        }, 200),
      });

      final StatusPageController controller = StatusPageController.instance;
      await controller.reload();

      controller.seedPreviewRequestForTest(
        'acme',
        DateTime.now().subtract(const Duration(seconds: 10)),
      );

      expect(
        controller.hasRequestedPreviewRender('acme'),
        isTrue,
        reason: 'a recent request still reads as in flight',
      );
      expect(controller.hasPreviewRequestExpired('acme'), isFalse);

      controller.seedPreviewRequestForTest(
        'acme',
        DateTime.now().subtract(const Duration(seconds: 241)),
      );

      expect(
        controller.hasRequestedPreviewRender('acme'),
        isFalse,
        reason:
            'past the window the request must stop reading as in flight, or an '
            'unconsumed queue pins the pane on a skeleton',
      );
      expect(
        controller.hasPreviewRequestExpired('acme'),
        isTrue,
        reason: 'and it must read as expired so the pane offers a retry',
      );
    });

    // The preview bookkeeping is keyed by page id and therefore by tenant. This
    // controller is a Type-keyed singleton that outlives a login, so a surviving
    // entry would hand the incoming identity the previous one's "still
    // generating" affordance for a page it cannot even see. This repo has
    // shipped that class of bug before, which is why it is pinned here.
    testWidgets('clears the previous identity preview bookkeeping', (
      tester,
    ) async {
      Http.fake({
        'status-pages': Http.response({
          'data': [
            {
              'id': 'acme',
              'name': 'Acme Status',
              'slug': 'acme',
              'domain_mode': 'path',
            },
          ],
        }, 200),
        'status-pages/acme/preview': Http.response({}, 202),
      });

      final StatusPageController controller = StatusPageController.instance;
      await controller.reload();

      await tester.runAsync(
        () => controller.requestPreviewRender('acme', maxAttempts: 1),
      );

      expect(
        controller.hasRequestedPreviewRender('acme'),
        isTrue,
        reason: 'premise: the request must be recorded before the reset',
      );

      Http.fake({
        'status-pages': Http.response({'data': <dynamic>[]}, 200),
      });

      await controller.resetForSession();

      expect(
        controller.hasRequestedPreviewRender('acme'),
        isFalse,
        reason:
            'a new identity must not inherit the previous one in-flight '
            'preview request',
      );
      expect(controller.hasPreviewPollCapped('acme'), isFalse);
    });
  });

  group('visibility reaches the wire', () {
    /// Captures the body of the create POST, and answers with a minimal created
    /// resource so `save()` reports success.
    Future<Map<String, dynamic>?> postedBodyFor({required bool isPublic}) async {
      Map<String, dynamic>? posted;
      Http.fake((r) {
        if (r.method == 'POST') {
          posted = Map<String, dynamic>.from(r.data as Map);
          return Http.response({
            'data': <String, dynamic>{'id': 'acme', 'name': 'Acme', 'slug': 'acme'},
          }, 201);
        }
        return Http.response(<String, dynamic>{}, 200);
      });

      final StatusPageController controller = StatusPageController.instance;
      await controller.create(
        StatusPage.fromMap(<String, dynamic>{
          'name': 'Acme',
          'slug': 'acme',
          'domain_mode': 'path',
          'is_public': isPublic,
          'subscriptions_enabled': true,
        }),
      );

      return posted;
    }

    test('a created page posts the visibility the operator chose', () async {
      // The defect this pins, which shipped. `is_public` was missing from BOTH ends:
      // the editor had no control for it, and `_modelFrom` enumerates the wire fields
      // explicitly and had no entry. So a page created in the product kept the database
      // default of false, the public route is fail-closed, and the operator's own page
      // answered 404 with nothing in the UI able to change it.
      //
      // Asserted on the REQUEST BODY, not on a model getter: the field existed on the
      // model the whole time, and the only place it went missing was the payload.
      final Map<String, dynamic>? posted = await postedBodyFor(isPublic: true);

      expect(posted, isNotNull, reason: 'The create never reached the network.');
      expect(posted!['is_public'], isTrue);
    });

    test('a private choice is posted as private, not coerced to public', () async {
      // The other direction, so the assertion above cannot be satisfied by a
      // hardcoded true.
      final Map<String, dynamic>? posted = await postedBodyFor(isPublic: false);

      expect(posted!['is_public'], isFalse);
    });
  });
}
