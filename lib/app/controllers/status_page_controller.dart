import 'dart:async' show unawaited;

import 'package:flutter/foundation.dart' show listEquals;
import 'package:flutter/widgets.dart' show Color, visibleForTesting;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/status_page.dart';
import '../enums/status_page_preview_status.dart'
    show StatusPagePreviewStatus;
import '../support/status_page_types.dart' show Subscriber;
import '../../resources/views/status/status_form_support.dart' show aiDraftFor;

/// Controller backing the four routed status-page views (list, editor,
/// preview, subscribers).
///
/// The read side is ORM-native as of Wave 2: [reload] fetches the roster
/// through `StatusPage.all()` (`GET /status-pages`) and caches it in [_pages],
/// degrading to the last-known-good cache on any failure (empty before the
/// first success); [statusPages] and [configById] answer synchronously from
/// that cache so a view's `build()` never awaits. [reloadPage] tops that up
/// with the show payload of ONE page, which is the only read that carries the
/// signed `preview_image_url` (see its docblock). The write actions [save] and
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

  /// The page ids whose subscriber roster has come back at least once,
  /// successfully or not.
  ///
  /// [_subscribers] cannot answer this on its own: [subscribersFor] plants an
  /// empty entry the moment it fires the fetch (that entry IS the fetch-once
  /// guard), so a present key means "asked", not "answered".
  final Set<String> _resolvedSubscribers = {};

  /// Monotonically increasing per-page poll generation, keyed by page id.
  ///
  /// [requestPreviewRender] stamps its own poll loop with the CURRENT
  /// generation for the page before it starts running; a later call for the
  /// SAME page bumps the generation again, so the earlier loop's next tick
  /// notices it has been superseded and stops itself without doing further
  /// work. This one counter is what both guarantees rest on: a second poll
  /// for the same page never runs two loops at once, and a disposed
  /// controller (every tick also checks [isDisposed]) cannot keep polling in
  /// the background.
  final Map<String, int> _previewPollGeneration = {};

  /// Page ids whose [requestPreviewRender] poll hit its cap while the server
  /// still reported `rendering`.
  ///
  /// The render may still succeed server-side after the client gives up
  /// watching, so this is a distinct "keep checking" signal for the editor's
  /// check-again affordance, never a client-written `failed`: a client-side
  /// failure the server contradicts is the anti-pattern this repo has
  /// already fixed three times (dashboard KPIs, monitor-detail SLO, the
  /// pending-monitor "zero monitors" state).
  final Set<String> _previewPollCapped = {};

  /// Page ids whose render this client has asked for through
  /// [requestPreviewRender], accepted by the server (a 202), but which the
  /// server does not yet report any render state for.
  ///
  /// The gap this closes is real and not cosmetic: `POST .../preview` only
  /// enqueues, so `preview_render_status` stays NULL until a worker picks the
  /// job up. Between the tap and that moment the server cannot distinguish
  /// "never asked" from "asked, waiting", and the editor's never-rendered
  /// state therefore kept offering the same Generate button with no feedback
  /// at all. This marker is the client's own knowledge of its own request, so
  /// it is honest state rather than a fabricated server one: it says a render
  /// was requested, never that one is running or has succeeded.
  ///
  /// The value is WHEN the request was accepted, not just that it was, because
  /// the marker has to expire. If the `previews` queue is not being consumed the
  /// server never reports any state at all, so without a time bound this marker
  /// would hold the pane on a skeleton for the rest of the session and across
  /// remounts, which is the one shape the editor's state table forbids. It
  /// expires after the same window [isPreviewRenderStale] uses, after which the
  /// pane shows the failed affordance and its retry.
  final Map<String, DateTime> _previewRenderRequested = {};

  /// In-memory cache of the status-page roster, populated by [reload]. Empty
  /// until the first successful fetch resolves.
  List<StatusPage> _pages = [];

  /// Whether a [reload] has completed at least once, successfully or not.
  bool _resolvedOnce = false;

  /// Whether the FIRST roster read is still in flight.
  ///
  /// Separates "we have not asked yet" from "we asked and there are none". The
  /// list view renders a skeleton while this is true instead of asserting an
  /// empty roster before the first answer arrives, which is what made a
  /// populated account flash "No status pages yet" on every cold open.
  ///
  /// Only the FIRST read counts: a later refetch (the view reloads on every
  /// route entry) leaves this false so the rows stay on screen rather than
  /// flashing a skeleton over data the operator is already reading.
  bool get isFirstLoad => !_resolvedOnce;

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
    // Seeded state is a resolved state, so a bound view renders the rows rather
    // than a skeleton waiting for a fetch the test never makes.
    _resolvedOnce = true;
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
  ///
  /// Resolving flips [isFirstLoad] false either way, so the view swaps its
  /// skeleton for the rows or for the honest empty state.
  Future<void> reload() async {
    final bool firstLoad = isFirstLoad;
    final List<StatusPage> pages = await StatusPage.all();
    _resolvedOnce = true;

    if (pages.isEmpty) {
      // The cache stands, but a first read that came back empty still has to
      // repaint: the view is showing a skeleton and needs to hear that the
      // answer arrived.
      if (firstLoad) refreshUI();
      return;
    }

    _pages = pages;
    refreshUI();
  }

  /// Refreshes the single page [pageId] from `GET /status-pages/{pageId}`
  /// (show) and publishes it into the cached roster.
  ///
  /// [reload] cannot stand in for this, and the difference is not a
  /// performance one. The two read endpoints answer with DIFFERENT keys:
  /// `index` deliberately omits `preview_image_url` because the signed URL is
  /// a capability and D5 keeps it out of list responses, so a roster read can
  /// never populate the preview pane's image. Only the show payload carries
  /// it, which makes this the read the editor needs on every open; without it
  /// the pane sat on the customer-view heading with no image under it.
  ///
  /// Publishes through [_replaceCachedPage] (so [statusPages] and [configById]
  /// answer with the show-derived fields the same way a poll tick's read
  /// does), then notifies listeners.
  ///
  /// A read that comes back for a different id than the one asked for is not
  /// this page and is dropped: an empty or unmatched envelope hydrates into a
  /// model with no id, which would otherwise be appended to the roster as a
  /// blank page.
  Future<void> reloadPage(String pageId) async {
    final StatusPage? page = await StatusPage.find(pageId);
    if (page == null || page.id != pageId) return;

    _replaceCachedPage(page);
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
    // Every per-page roster is unasked again, so the incoming identity gets a
    // skeleton rather than the previous tenant's subscriber counts.
    _resolvedSubscribers.clear();
    // Back to "not asked yet": the incoming identity must get a skeleton, not
    // the previous tenant's conclusion that there are no status pages.
    _resolvedOnce = false;
    // Preview-render bookkeeping is per page id and therefore per tenant. A
    // surviving entry would let the incoming identity inherit the previous one's
    // "still generating" or "check again" affordance, and bumping the generation
    // counters also stops any poll still in flight from writing into the new
    // session's cache. This controller is a Type-keyed singleton that outlives a
    // login, so nothing else clears these.
    _previewPollGeneration.clear();
    _previewPollCapped.clear();
    _previewRenderRequested.clear();
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

  /// Whether the subscriber roster of the page [pageId] has been answered at
  /// least once.
  ///
  /// The subscriber-roster equivalent of [isFirstLoad], but per page id, since
  /// [_subscribers] is a lazy per-page cache: one controller-wide flag would
  /// call page B resolved because page A had already loaded. The subscribers
  /// view renders a skeleton while this is false instead of asserting "No
  /// subscribers yet" (and a Total of zero) before the first answer for THAT
  /// page arrives.
  ///
  /// A `null` id has no roster to resolve and never will; the view renders its
  /// not-found state for it, so it reports as unresolved.
  bool hasResolvedSubscribers(String? pageId) =>
      pageId != null && _resolvedSubscribers.contains(pageId);

  /// Fetches the subscriber roster for [pageId] via `GET
  /// /status-pages/{pageId}/subscribers` and decodes the envelope into
  /// [Subscriber]s, publishing the result and notifying listeners.
  ///
  /// Degrades to the last-known-good cache on any failure (network error,
  /// non-2xx, or an empty payload) so a view never flickers into an empty
  /// state after a genuine earlier load.
  ///
  /// However it turns out, the page is marked resolved (see
  /// [hasResolvedSubscribers]) and, when that resolution is this page's first,
  /// listeners are notified even on the degraded paths: a subscribers view is
  /// sitting on a skeleton waiting to hear that the answer arrived, and would
  /// otherwise skeleton forever on an empty or failed first read.
  Future<void> _refreshSubscribers(String pageId) async {
    final bool firstLoad = !_resolvedSubscribers.contains(pageId);
    try {
      final response = await Http.get('/status-pages/$pageId/subscribers');
      _resolvedSubscribers.add(pageId);
      if (!response.successful) {
        Log.error(
          '[StatusPageController._refreshSubscribers] $pageId: '
          '${response.errorMessage}',
        );
        if (firstLoad) refreshUI();
        return;
      }

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List || raw.isEmpty) {
        if (firstLoad) refreshUI();
        return;
      }

      _subscribers[pageId] = raw
          .whereType<Map<String, dynamic>>()
          .map(Subscriber.fromMap)
          .toList();
      refreshUI();
    } catch (error) {
      Log.error(
        '[StatusPageController._refreshSubscribers] $pageId failed: $error',
      );
      // A thrown request is an answered one as far as the screen is concerned.
      _resolvedSubscribers.add(pageId);
      if (firstLoad) refreshUI();
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

  // ---------------------------------------------------------------------------
  // Preview render: trigger + bounded poll against `api/v1/status-pages`.
  // ---------------------------------------------------------------------------

  /// Poll cadence for [requestPreviewRender]: how often `GET
  /// /status-pages/{id}` (show) is re-fetched while the server still reports
  /// `rendering`. Exposed as a parameter default (rather than hardcoded)
  /// purely as a test seam: production callers use the default.
  static const Duration _defaultPreviewPollInterval = Duration(seconds: 2);

  /// Poll cap for [requestPreviewRender]: 45 attempts at the default 2s
  /// cadence is 90s, matching `retry_after` (`backend/config/queue.php`), the
  /// ceiling Laravel itself documents for a queued job to finish before being
  /// considered lost. Exposed as a parameter default for the same test-seam
  /// reason as [_defaultPreviewPollInterval].
  static const int _defaultPreviewPollMaxAttempts = 45;

  /// 2x [RenderStatusPagePreview]'s 120s uniqueness window
  /// (`backend/app/Jobs/RenderStatusPagePreview.php`): see
  /// [isPreviewRenderStale].
  static const int _previewRenderStaleAfterSeconds = 240;

  /// Whether [pageId]'s most recent [requestPreviewRender] poll hit its cap
  /// while the server still reported `rendering`.
  ///
  /// This is the check-again affordance's signal, and it is deliberately
  /// distinct from a `failed` status: see [_previewPollCapped].
  bool hasPreviewPollCapped(String pageId) =>
      _previewPollCapped.contains(pageId);

  /// Whether this client has an accepted [requestPreviewRender] outstanding for
  /// [pageId].
  ///
  /// The editor renders a page in this state as in-flight (the same sized
  /// skeleton a server-reported `rendering` gets, plus the check-again row once
  /// the poll caps) rather than as never rendered: see
  /// [_previewRenderRequested]. It stops mattering the moment the server
  /// reports any render state of its own, because the view only consults it
  /// while [StatusPage.previewRenderStatus] is null.
  ///
  /// Returns false once the request is older than
  /// [_previewRenderStaleAfterSeconds]. That bound is what stops an unconsumed
  /// `previews` queue from holding the pane on a skeleton indefinitely: the
  /// server never reports a state in that case, so nothing else would ever
  /// clear this. An expired request reads as failed, which is the honest answer
  /// (a render was asked for and nothing came back) and carries a retry.
  bool hasRequestedPreviewRender(String pageId) {
    final DateTime? requestedAt = _previewRenderRequested[pageId];
    if (requestedAt == null) return false;

    if (DateTime.now().difference(requestedAt).inSeconds >=
        _previewRenderStaleAfterSeconds) {
      return false;
    }

    return true;
  }

  /// Records an accepted render request for [pageId] as having been made at
  /// [requestedAt], so a test can exercise the expiry without waiting out the
  /// real window.
  @visibleForTesting
  void seedPreviewRequestForTest(String pageId, DateTime requestedAt) {
    _previewRenderRequested[pageId] = requestedAt;
  }

  /// Whether this client asked for a render for [pageId] and the request has
  /// since aged out with the server never reporting a state for it.
  ///
  /// The editor renders this as the failed affordance rather than as never
  /// rendered, because "asked and never answered" is a failure from the
  /// operator's side even though no server-side failure was recorded. The most
  /// likely cause is an unconsumed `previews` queue.
  bool hasPreviewRequestExpired(String pageId) {
    final DateTime? requestedAt = _previewRenderRequested[pageId];
    if (requestedAt == null) return false;

    return DateTime.now().difference(requestedAt).inSeconds >=
        _previewRenderStaleAfterSeconds;
  }

  /// Whether [page]'s server-reported `rendering` status has been open long
  /// enough that a lost job, not real progress, is the more honest read.
  ///
  /// Reads [StatusPage.updatedAt] (bumped by the render job's own `save()`
  /// when it flips the row to `rendering`) rather than any client-side poll
  /// clock, so a page whose editor is opened fresh, with no poll in flight,
  /// reports the same stale verdict a polling one would: a lost job must not
  /// be able to pin the pane on a skeleton forever just because nobody
  /// happens to be watching it right now.
  bool isPreviewRenderStale(StatusPage page) {
    if (page.previewRenderStatus != StatusPagePreviewStatus.rendering) {
      return false;
    }
    final Carbon? updatedAt = page.updatedAt;
    if (updatedAt == null) return false;
    return Carbon.now().diffInSeconds(updatedAt) >
        _previewRenderStaleAfterSeconds;
  }

  /// Triggers a headless PNG render of page [pageId] via `POST
  /// /status-pages/{pageId}/preview` (202, no body: the row still holds its
  /// pre-render state at that moment), then polls `GET
  /// /status-pages/{pageId}` (show) every [pollInterval] until the server
  /// reports `completed` or `failed`, updating the cached page and notifying
  /// listeners after every poll.
  ///
  /// [pollInterval] and [maxAttempts] default to the production cadence
  /// ([_defaultPreviewPollInterval] / [_defaultPreviewPollMaxAttempts]) and
  /// exist as parameters purely as a test seam, matching the `capture()` seam
  /// pattern the backend renderer uses.
  ///
  /// **The cap does not write `failed`.** When [maxAttempts] is reached with
  /// the server still reporting `rendering`, the render may yet succeed
  /// server-side, so the poll simply stops and marks [pageId] in
  /// [hasPreviewPollCapped] for a check-again affordance; it never fabricates
  /// a terminal state the server has not itself reached. Writing a
  /// client-side failure the server contradicts is the anti-pattern this
  /// repo has already fixed three times (dashboard KPIs, monitor-detail SLO,
  /// the pending-monitor "zero monitors" state).
  ///
  /// A fresh call for a page already being polled bumps that page's poll
  /// generation, so the earlier loop stops itself on its next tick rather
  /// than running alongside the new one; a disposed controller stops every
  /// loop the same way, via [isDisposed]. This is what keeps a page's poll
  /// bounded to at most one live loop and unable to keep firing past
  /// disposal, without needing an explicit `Timer` to cancel.
  Future<void> requestPreviewRender(
    String pageId, {
    Duration pollInterval = _defaultPreviewPollInterval,
    int maxAttempts = _defaultPreviewPollMaxAttempts,
  }) async {
    // The render this request is WAITING FOR has to be distinguishable from the
    // one the page already has, or the poll below mistakes the old one for the
    // new one. Captured before the trigger so nothing can land in between.
    final Carbon? knownRenderedAt = configById(pageId)?.previewRenderedAt;

    try {
      final response = await Http.post('/status-pages/$pageId/preview');
      if (!response.successful) {
        Log.error(
          '[StatusPageController.requestPreviewRender] $pageId: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return;
      }
    } catch (error) {
      Log.error(
        '[StatusPageController.requestPreviewRender] $pageId failed: $error',
      );
      _toastError(null);
      return;
    }

    // A fresh trigger supersedes any earlier loop for this page (see the
    // class docblock on [_previewPollGeneration]) and clears a stale
    // check-again signal: the operator asking to render again is exactly
    // the recovery path that signal exists to offer.
    final int generation = (_previewPollGeneration[pageId] ?? 0) + 1;
    _previewPollGeneration[pageId] = generation;
    _previewPollCapped.remove(pageId);

    // The server accepted the job but has not started it, so nothing in its
    // payload changes yet. Publish the client's own request (see
    // [_previewRenderRequested]) and repaint immediately, so the pane stops
    // reading as never-asked from this tap onwards rather than from whenever a
    // worker happens to pick the job up.
    _previewRenderRequested[pageId] = DateTime.now();
    refreshUI();

    for (int attempt = 1; attempt <= maxAttempts; attempt++) {
      await Future<void>.delayed(pollInterval);

      // Superseded (a newer requestPreviewRender call for this page started)
      // or disposed: stop making progress without touching state further.
      if (isDisposed || _previewPollGeneration[pageId] != generation) return;

      final StatusPage? page = await StatusPage.find(pageId);

      if (isDisposed || _previewPollGeneration[pageId] != generation) return;

      if (page != null) _replaceCachedPage(page);

      final StatusPagePreviewStatus? status = page?.previewRenderStatus;

      // A terminal state only ends this poll when it belongs to THIS request.
      // On a page that already had a render the server still reports the
      // PREVIOUS `completed` until a worker picks the new job up, so treating
      // any `completed` as terminal made the first tick conclude, two seconds
      // after the tap, that the render it asked for was already done. The
      // observable result was a Refresh that stopped tracking immediately and
      // left the old stamp in place, which is the most common path this feature
      // has. `failed` is exempt from the comparison: a failure does not move the
      // stamp, so there is nothing to compare, and re-reporting the previous
      // failure is the honest answer either way.
      final bool completedIsNew =
          status == StatusPagePreviewStatus.completed &&
          (knownRenderedAt == null ||
              (page?.previewRenderedAt != null &&
                  page!.previewRenderedAt!.isAfter(knownRenderedAt)));

      if (completedIsNew || status == StatusPagePreviewStatus.failed) {
        // The server now reports a terminal state for this request, so the
        // client's stand-in for the gap before it had one is handed back.
        _previewRenderRequested.remove(pageId);
        refreshUI();
        return;
      }

      if (attempt == maxAttempts) {
        _previewPollCapped.add(pageId);
      }
      refreshUI();
    }
  }

  /// Publishes [page]'s fresh poll read into the cached roster: overwrites
  /// the existing entry sharing its id, or appends it when the roster has
  /// not (yet) loaded it, so [statusPages]/[configById] reflect a poll tick
  /// without needing a full [reload].
  void _replaceCachedPage(StatusPage page) {
    final int index = _pages.indexWhere((StatusPage p) => p.id == page.id);
    if (index == -1) {
      _pages.add(page);
      return;
    }
    _pages[index] = page;
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
  /// Fills the backend's `Store`/`UpdateStatusPageRequest` field shape (the
  /// domain mode goes out as its enum `name`, the colour through the forward
  /// write-cast [_wireBrandColor]) and, when
  /// [existing] is true, stamps the id and marks the model as already existing
  /// so `save()` routes to `PUT` rather than `POST`.
  ///
  /// `monitorIds` is deliberately excluded: monitor membership is a separate
  /// pivot managed through [attachMonitor]/[detachMonitor].
  StatusPage _modelFrom(StatusPage draft, {required bool existing}) {
    final StatusPage page = StatusPage()
      ..fill(<String, dynamic>{
        'name': draft.name,
        'slug': draft.slug,
        'domain_mode': draft.domainMode.name,
        'brand_color': _wireBrandColor(draft.brandColor),
        'logo_text': draft.logoText,
        'description': draft.description,
        // The second half of a dropped write. This map enumerates the wire fields
        // explicitly, so a field the editor collects and this list omits is filled into
        // the draft, shown to the operator, and then silently discarded on the way out.
        // `is_public` was missing from BOTH ends: the editor had no control and this map
        // had no entry, which is why every page created in the product stayed private and
        // answered 404 with nothing in the UI able to change it.
        'is_public': draft.isPublic,
        'subscriptions_enabled': draft.subscriptionsEnabled,
      });
    if (existing) {
      page.id = draft.id;
      page.exists = true;
    }
    return page;
  }

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
