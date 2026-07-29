import 'dart:async' show unawaited;

import 'package:flutter/foundation.dart' show listEquals;
import 'package:flutter/widgets.dart' show Color, visibleForTesting;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/status_page.dart';
import '../enums/domain_mode.dart' show DomainMode;
import '../support/status_page_types.dart' show Subscriber;
import '../../resources/views/status/status_form_support.dart' show aiDraftFor;

/// Controller backing the four routed status-page views (list, editor,
/// preview, subscribers).
///
/// The read side is ORM-native as of Wave 2: [reload] fetches the roster
/// through `StatusPage.all()` (`GET /status-pages`) and caches it in [_pages],
/// degrading to the last-known-good cache on any failure (empty before the
/// first success); [statusPages] and [configById] answer synchronously from
/// that cache so a view's `build()` never awaits. The write actions [save] and
/// [create] map the editor's [StatusPage] draft to a clean persistence model
/// and persist through its ORM `save()` (bool-checked, toast on failure),
/// while [attachMonitor]/[detachMonitor]/[reorderMonitors] stay raw `Http.*`
/// against the monitor-membership pivot sub-resource. The subscriber roster
/// went live in Step 4: [subscribersFor] lazily fetches
/// `GET /status-pages/{id}/subscribers` per page id and caches the result, so
/// the `StatusPageSubscribersView` build-time reads keep their existing
/// synchronous signature while the underlying data is now real. [addSubscriber]
/// and [removeSubscriber] persist through `POST`/`DELETE` on the same
/// sub-resource.
class StatusPageController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor, registering the controller on first access.
  static StatusPageController get instance =>
      Magic.findOrPut(StatusPageController.new);

  /// Live per-page subscriber roster cache, keyed by status-page id.
  ///
  /// Populated by [_refreshSubscribers] (`GET
  /// /status-pages/{id}/subscribers`), lazily triggered by [subscribersFor] on
  /// first access per page id; [addSubscriber] and [removeSubscriber] mutate
  /// or refetch this cache so a change survives across rebuilds within the
  /// controller's lifetime.
  final Map<String, List<Subscriber>> _subscribers = {};

  /// In-memory cache of the status-page roster, populated by [reload]. Empty
  /// until the first successful fetch resolves.
  List<StatusPage> _pages = [];

  /// Every configured status page, sourced from `GET /status-pages` via
  /// [reload]. Reads straight from the cache (no I/O), so a view's `build()`
  /// never awaits.
  List<StatusPage> get statusPages => _pages;

  /// Resolves a status page by [id] from the cached roster; `null` when none
  /// matches (unknown id, or the cache has not loaded yet).
  StatusPage? configById(String? id) {
    if (id == null) return null;
    for (final StatusPage page in _pages) {
      if (page.id == id) return page;
    }
    return null;
  }

  /// Seeds the in-memory roster directly for a widget/controller test,
  /// bypassing the network.
  ///
  /// The wired [reload] path sources the roster from `GET /status-pages`, which
  /// a bare test host cannot serve; this lets a test populate [statusPages]
  /// (and therefore [configById]) with known fixtures before pumping a bound
  /// view. Notifies listeners so an already-mounted view rebuilds against the
  /// seeded roster.
  @visibleForTesting
  void seedForTest(List<StatusPage> seed) {
    _pages = List<StatusPage>.from(seed);
    refreshUI();
  }

  /// Bootstraps the roster the first time this controller backs a view.
  @override
  void onInit() {
    super.onInit();
    reload();
  }

  /// Non-destructive roster refresh: fetches the roster through
  /// `StatusPage.all()` (`GET /status-pages`) and republishes it on a
  /// non-empty result. Preserves the previously loaded roster on any failure
  /// (network error, non-2xx, or an empty payload) so the list view never
  /// flickers into an empty state between reloads.
  ///
  /// `StatusPage.all()` swallows transport failures and returns an empty list,
  /// so an empty result is treated as "nothing new to publish" and leaves the
  /// last-known-good cache in place (it is empty before the first success).
  Future<void> reload() async {
    final List<StatusPage> pages = await StatusPage.all();
    if (pages.isEmpty) return;

    _pages = pages;
    refreshUI();
  }

  /// Drops the previous session's status-page roster and its per-page
  /// subscriber caches, publishes the cleared state, then refetches for the
  /// identity that is now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): [reload] keeps
  /// the last-known-good roster on an empty fetch, so across an identity change
  /// a failed refetch would otherwise leave the previous team's pages listed.
  /// The [_subscribers] cache is dropped whole rather than refetched: it is
  /// lazily filled per page id by [subscribersFor], which refetches on the next
  /// build of a subscribers view.
  @override
  Future<void> resetForSession() async {
    _pages = [];
    _subscribers.clear();
    refreshUI();

    await reload();
  }

  /// The working subscriber roster for the page with [id]; empty for a `null`
  /// id or before the first fetch for that id resolves.
  ///
  /// Triggers a background [_refreshSubscribers] fetch on first access per
  /// page id (never re-fetches while a cached entry, even an empty one,
  /// already exists), then answers synchronously from the cache. This keeps
  /// the call a plain synchronous getter for `StatusPageSubscribersView`,
  /// which reads it directly inside `build()`.
  List<Subscriber> subscribersFor(String? id) {
    if (id == null) return const [];
    if (!_subscribers.containsKey(id)) {
      _subscribers[id] = const [];
      unawaited(_refreshSubscribers(id));
    }
    return _subscribers[id] ?? const [];
  }

  /// Fetches the subscriber roster for [pageId] via `GET
  /// /status-pages/{pageId}/subscribers` and decodes the envelope into
  /// [Subscriber]s, publishing the result and notifying listeners.
  ///
  /// Degrades to the last-known-good cache on any failure (network error,
  /// non-2xx, or an empty payload) so a view never flickers into an empty
  /// state after a genuine earlier load.
  Future<void> _refreshSubscribers(String pageId) async {
    try {
      final response = await Http.get('/status-pages/$pageId/subscribers');
      if (!response.successful) {
        Log.error(
          '[StatusPageController._refreshSubscribers] $pageId: '
          '${response.errorMessage}',
        );
        return;
      }

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List || raw.isEmpty) return;

      _subscribers[pageId] = raw
          .whereType<Map<String, dynamic>>()
          .map(Subscriber.fromMap)
          .toList();
      refreshUI();
    } catch (error) {
      Log.error(
        '[StatusPageController._refreshSubscribers] $pageId failed: $error',
      );
    }
  }

  /// Direct-adds [email] to the page [pageId]'s subscriber roster via `POST
  /// /status-pages/{pageId}/subscribers`, then refreshes the cached roster
  /// from the backend on success.
  ///
  /// Bool-checked: a non-2xx response or a transport failure logs and
  /// surfaces an error toast without throwing, leaving the cached roster
  /// untouched.
  Future<void> addSubscriber(String pageId, String email) async {
    try {
      final response = await Http.post(
        '/status-pages/$pageId/subscribers',
        data: <String, dynamic>{'email': email},
      );
      if (!response.successful) {
        Log.error(
          '[StatusPageController.addSubscriber] $pageId: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return;
      }

      await _refreshSubscribers(pageId);
    } catch (error) {
      Log.error('[StatusPageController.addSubscriber] $pageId failed: $error');
      _toastError(null);
    }
  }

  /// Composes an AI-drafted [StatusPage] over the given [monitorIds].
  ///
  /// A pure fill: the view reads the returned draft's fields into its local
  /// compose state, so the draft and its slug latch stay ephemeral in the view.
  StatusPage generateWithAi(List<String> monitorIds) =>
      aiDraftFor(monitorIds);

  // ---------------------------------------------------------------------------
  // Business actions: live writes against `api/v1/status-pages`.
  // ---------------------------------------------------------------------------

  /// Saves an existing status page [draft] via the ORM `StatusPage.save()`
  /// (`PUT /status-pages/{id}`).
  ///
  /// Maps the editor's value-object draft to a persistence model marked as
  /// already existing (so `save()` issues an update, not a create), then checks
  /// the bool result: on success, refreshes the bound view, surfaces a success
  /// toast, and returns to the list; on a false result, hands any 422 field
  /// errors back for inline display (staying put, no toast) or surfaces the
  /// generic error toast for a non-field failure, so the operator can retry
  /// from the still-open editor.
  ///
  /// Returns the backend per-field validation errors (single message per field,
  /// keyed by the wire field name: `name`, `slug`, `domain_mode`, ...) so the
  /// editor can render a server 422 inline; an empty map means success or a
  /// non-field failure (already toasted).
  Future<Map<String, String>> save(StatusPage draft) async {
    final StatusPage page = _modelFrom(draft, existing: true);

    final bool ok = await page.save();
    if (!ok) {
      final Map<String, String>? fieldErrors = _fieldErrorsOrToast(page);
      if (fieldErrors != null) return fieldErrors;
      return const {};
    }

    await _syncComponents(page.id, draft.monitorIds);
    refreshUI();
    Magic.success(trans('uptizm.status.editor_form_save'), draft.name ?? '');
    MagicRoute.to('/status');
    return const {};
  }

  /// Creates a new status page from [draft] via the ORM `StatusPage.save()`
  /// (`POST /status-pages`).
  ///
  /// Maps the value-object draft to a fresh (non-existing) model so `save()`
  /// issues a create, then checks the bool result: on success, refreshes the
  /// bound view, surfaces a success toast, and returns to the list; on a false
  /// result, hands any 422 field errors back for inline display (staying put,
  /// no toast) or surfaces the generic error toast for a non-field failure.
  ///
  /// Returns the backend per-field validation errors (single message per field,
  /// keyed by the wire field name) so the editor can render a server 422
  /// inline; an empty map means success or a non-field failure (already
  /// toasted).
  Future<Map<String, String>> create(StatusPage draft) async {
    final StatusPage page = _modelFrom(draft, existing: false);

    final bool ok = await page.save();
    if (!ok) {
      final Map<String, String>? fieldErrors = _fieldErrorsOrToast(page);
      if (fieldErrors != null) return fieldErrors;
      return const {};
    }

    await _syncComponents(page.id, draft.monitorIds);
    refreshUI();
    Magic.success(
      trans('uptizm.status.editor_form_create_page'),
      draft.name ?? '',
    );
    MagicRoute.to('/status');
    return const {};
  }

  /// Brings page [pageId]'s attached components in line with [desiredIds], in
  /// the given display order.
  ///
  /// Components are a pivot SUB-RESOURCE on the backend: `StoreStatusPageRequest`
  /// and `UpdateStatusPageRequest` do not validate a `monitors` key, so anything
  /// the page write carries under that name is discarded by `validated()`. The
  /// editor used to rely on exactly that key, which is why a component
  /// assignment made in the UI never persisted and every page reported zero
  /// components. Assignment therefore has to go through the dedicated
  /// attach/detach/reorder endpoints, which is what this does.
  ///
  /// Detaches first, then attaches, then restates the order, so a reorder-only
  /// change still lands. Each leg already logs and toasts its own failure
  /// without throwing, so a partial sync degrades to "some components moved"
  /// rather than aborting the whole save the operator already saw succeed.
  Future<void> _syncComponents(String pageId, List<String> desiredIds) async {
    if (pageId.isEmpty) return;

    // The server's current attachment set, not the cached list: a create has no
    // cache entry yet, and a stale cache would compute the wrong delta.
    final StatusPage? saved = await StatusPage.find(pageId);
    final List<String> current = saved?.monitorIds ?? const <String>[];

    // Nothing to do when the assignment is already exactly this, in this order.
    // Most saves only touch branding, and firing detach/attach/reorder for an
    // unchanged component set would be three pointless writes per save.
    if (listEquals(current, desiredIds)) return;

    final Set<String> desired = desiredIds.toSet();

    for (final String removed in current.toSet().difference(desired)) {
      await detachMonitor(pageId, removed);
    }

    for (final String added in desired.difference(current.toSet())) {
      await attachMonitor(
        pageId,
        added,
        displayOrder: desiredIds.indexOf(added),
      );
    }

    // Restate the full order. Attach only carries the order of newly added
    // rows, so moving an already-attached component needs this pass.
    if (desiredIds.isNotEmpty) {
      await reorderMonitors(pageId, <Map<String, dynamic>>[
        for (int i = 0; i < desiredIds.length; i++)
          <String, dynamic>{'id': desiredIds[i], 'display_order': i},
      ]);
    }
  }

  /// Resolves a failed [page] save into either its per-field validation errors
  /// or a generic toast.
  ///
  /// Returns the field errors (single message per field, keyed by the wire
  /// field name) when the failed save carried the Laravel 422 shape via
  /// [StatusPage.validationErrors], so the caller hands them back to the editor
  /// for inline display and stays put. Returns `null` for a non-field failure
  /// (a transport error / 500) after surfacing the generic error toast and
  /// logging the cause, so the caller falls back to its empty-map contract.
  Map<String, String>? _fieldErrorsOrToast(StatusPage page) {
    final Map<String, List<String>> errors = page.validationErrors;
    if (errors.isNotEmpty) {
      return {
        for (final MapEntry<String, List<String>> entry in errors.entries)
          entry.key: entry.value.first,
      };
    }

    Log.error('[StatusPageController] save returned false with no field errors');
    _toastError(null);
    return null;
  }

  /// Attaches [monitorId] to the page [pageId]'s public component list via
  /// `POST /status-pages/{pageId}/monitors`, or updates its pivot fields when
  /// already attached (the backend uses `syncWithoutDetaching`).
  ///
  /// [displayOrder] and [customLabel] are optional pivot fields; omitted, the
  /// backend defaults them to `0`/`null`. Refreshes the bound view on success;
  /// logs and toasts on failure without throwing.
  Future<void> attachMonitor(
    String pageId,
    String monitorId, {
    int? displayOrder,
    String? customLabel,
  }) async {
    try {
      final response = await Http.post(
        '/status-pages/$pageId/monitors',
        data: <String, dynamic>{
          'monitor_id': monitorId,
          'display_order': ?displayOrder,
          'custom_label': ?customLabel,
        },
      );
      if (!response.successful) {
        Log.error(
          '[StatusPageController.attachMonitor] $pageId/$monitorId: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return;
      }

      refreshUI();
    } catch (error) {
      Log.error(
        '[StatusPageController.attachMonitor] $pageId/$monitorId failed: '
        '$error',
      );
      _toastError(null);
    }
  }

  /// Detaches [monitorId] from the page [pageId]'s public component list via
  /// `DELETE /status-pages/{pageId}/monitors/{monitorId}`.
  ///
  /// Refreshes the bound view on success; logs and toasts on failure without
  /// throwing.
  Future<void> detachMonitor(String pageId, String monitorId) async {
    try {
      final response = await Http.delete(
        '/status-pages/$pageId/monitors/$monitorId',
      );
      if (!response.successful) {
        Log.error(
          '[StatusPageController.detachMonitor] $pageId/$monitorId: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return;
      }

      refreshUI();
    } catch (error) {
      Log.error(
        '[StatusPageController.detachMonitor] $pageId/$monitorId failed: '
        '$error',
      );
      _toastError(null);
    }
  }

  /// Bulk-reorders the page [pageId]'s attached monitors via
  /// `PUT /status-pages/{pageId}/monitors/reorder`.
  ///
  /// [order] is the full set of `{id, display_order}` rows in their new order
  /// (mirrors the backend's `reorderMonitors` contract). Refreshes the bound
  /// view on success; logs and toasts on failure without throwing.
  Future<void> reorderMonitors(
    String pageId,
    List<Map<String, dynamic>> order,
  ) async {
    try {
      final response = await Http.put(
        '/status-pages/$pageId/monitors/reorder',
        data: <String, dynamic>{'order': order},
      );
      if (!response.successful) {
        Log.error(
          '[StatusPageController.reorderMonitors] $pageId: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return;
      }

      refreshUI();
    } catch (error) {
      Log.error('[StatusPageController.reorderMonitors] $pageId failed: $error');
      _toastError(null);
    }
  }

  /// Removes [subscriber] from the page [pageId] roster via `DELETE
  /// /status-pages/{pageId}/subscribers/{subscriber.id}`.
  ///
  /// Optimistic: the entry is dropped from the cached roster and the view
  /// rebuilds immediately, before the request resolves. On a non-2xx response
  /// or a transport failure, logs, surfaces an error toast, and reverts by
  /// refetching the roster from the backend (rather than re-inserting the
  /// removed entry locally, since the backend is the source of truth for what
  /// still exists).
  Future<void> removeSubscriber(String pageId, Subscriber subscriber) async {
    final List<Subscriber> roster = subscribersFor(pageId);
    final int index = roster.indexOf(subscriber);
    if (index == -1) return;

    roster.removeAt(index);
    refreshUI();

    try {
      final response = await Http.delete(
        '/status-pages/$pageId/subscribers/${subscriber.id}',
      );
      if (!response.successful) {
        Log.error(
          '[StatusPageController.removeSubscriber] $pageId/${subscriber.id}: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        await _refreshSubscribers(pageId);
        return;
      }

      Magic.success(
        trans('uptizm.status.subscribers_remove_toast_title'),
        trans('uptizm.status.subscribers_remove_toast_description', {
          'email': subscriber.email,
        }),
      );
    } catch (error) {
      Log.error(
        '[StatusPageController.removeSubscriber] $pageId/${subscriber.id} '
        'failed: $error',
      );
      _toastError(null);
      await _refreshSubscribers(pageId);
    }
  }

  // ---------------------------------------------------------------------------
  // Wire helpers
  // ---------------------------------------------------------------------------

  /// Builds a clean [StatusPage] persistence model from the editor's [draft]
  /// model.
  ///
  /// Fills the backend's `Store`/`UpdateStatusPageRequest` field shape (using
  /// the forward write-casts [_wireDomainMode]/[_wireBrandColor]) and, when
  /// [existing] is true, stamps the id and marks the model as already existing
  /// so `save()` routes to `PUT` rather than `POST`.
  ///
  /// `monitorIds`/`metricKeys` are deliberately excluded: monitor membership
  /// is a separate pivot managed through [attachMonitor]/[detachMonitor], and
  /// metric selection has no live endpoint yet (the backend's `metrics()`
  /// pivot exists for schema completeness only, per `StatusPage.php`).
  StatusPage _modelFrom(StatusPage draft, {required bool existing}) {
    final StatusPage page = StatusPage()
      ..fill(<String, dynamic>{
        'name': draft.name,
        'slug': draft.slug,
        'domain_mode': _wireDomainMode(draft.domainMode),
        'brand_color': _wireBrandColor(draft.brandColor),
        'logo_text': draft.logoText,
        'description': draft.description,
        'subscriptions_enabled': draft.subscriptionsEnabled,
      });
    if (existing) {
      page.id = draft.id;
      page.exists = true;
    }
    return page;
  }

  /// Maps the fixture's [DomainMode] to the backend's `domain_mode` enum
  /// (`'path'`/`'custom'`).
  ///
  /// The two enums diverge: the fixture models a subdomain vs. a shared path,
  /// while the backend models a shared path vs. a dedicated custom domain.
  /// [DomainMode.subdomain] maps to `'custom'` as the closest available
  /// concept (a page served on its own domain, not the shared path).
  String _wireDomainMode(DomainMode mode) => switch (mode) {
    DomainMode.path => 'path',
    DomainMode.subdomain => 'custom',
  };

  /// Encodes a fixture [Color] as the backend's `#RRGGBB` hex string.
  String _wireBrandColor(Color color) {
    final String hex = (color.toARGB32() & 0xFFFFFF)
        .toRadixString(16)
        .padLeft(6, '0')
        .toUpperCase();
    return '#$hex';
  }

  /// Surfaces a generic write-failure toast.
  ///
  /// Reuses the existing `list_error_load_*` copy: the status namespace has
  /// no dedicated save/create/attach/detach/reorder failure strings yet, and
  /// this step's file scope does not extend to the lang assets that would add
  /// them (see `### Deviations`).
  void _toastError(String? detail) {
    Magic.error(
      trans('uptizm.status.list_error_load_title'),
      detail ?? trans('uptizm.status.list_error_load_description'),
    );
  }
}
