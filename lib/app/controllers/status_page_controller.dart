import 'package:flutter/widgets.dart' show Color, visibleForTesting;
import 'package:magic/magic.dart';

import '../models/status_page.dart';
import '../mocks/status_pages.dart';
import '../mocks/status_pages.dart' as status_pages_fixture;
import '../../resources/views/status/status_form_support.dart' show aiDraftFor;

/// Controller backing the four routed status-page views (list, editor,
/// preview, subscribers).
///
/// The read side is ORM-native as of Wave 2: [reload] fetches the roster
/// through `StatusPage.all()` (`GET /status-pages`) and caches it in [_pages],
/// degrading to the last-known-good cache on any failure (empty before the
/// first success); [statusPages] and [configById] answer synchronously from
/// that cache so a view's `build()` never awaits. The write actions [save] and
/// [create] map the editor's [StatusPageConfig] draft to a [StatusPage] model
/// and persist through its ORM `save()` (bool-checked, toast on failure),
/// while [attachMonitor]/[detachMonitor]/[reorderMonitors] stay raw `Http.*`
/// against the monitor-membership pivot sub-resource. [removeSubscriber] stays
/// a local-only mutation: the backend has no status-page-subscriber write
/// endpoint yet (only the `StatusPageSubscriber` model exists, unrouted), so
/// there is nothing to persist to; the subscriber roster is still fixture-fed
/// through [subscribersFor].
class StatusPageController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static StatusPageController get instance =>
      Magic.findOrPut(StatusPageController.new);

  /// Mutable per-page subscriber working sets, keyed by status-page id.
  ///
  /// Lazily seeded from the const fixtures on first access (the fixture map is
  /// never mutated in place); [removeSubscriber] edits the working copy so a
  /// removal survives across rebuilds within the controller's lifetime.
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

  /// The working subscriber roster for the page with [id]; empty for a `null`
  /// id.
  ///
  /// Seeds the working copy from the fixtures on first access, then returns the
  /// same mutable list so [removeSubscriber] edits persist across rebuilds.
  List<Subscriber> subscribersFor(String? id) {
    if (id == null) return const [];
    return _subscribers.putIfAbsent(
      id,
      () => status_pages_fixture.subscribersFor(id).toList(),
    );
  }

  /// Composes an AI-drafted [StatusPageConfig] over the given [monitorIds].
  ///
  /// A pure fill: the view assigns the returned draft into its local compose
  /// state, so the draft and its slug latch stay ephemeral in the view.
  StatusPageConfig generateWithAi(List<String> monitorIds) =>
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
  /// toast, and returns to the list; on a false result (non-2xx or a swallowed
  /// transport failure), logs it and surfaces an error toast without navigating
  /// away, so the operator can retry from the still-open editor.
  Future<void> save(StatusPageConfig draft) async {
    final StatusPage page = _modelFrom(draft, existing: true);

    final bool ok = await page.save();
    if (!ok) {
      Log.error('[StatusPageController.save] ${draft.id}: save() returned false');
      _toastError(null);
      return;
    }

    refreshUI();
    Magic.success(trans('uptizm.status.editor_form_save'), draft.name);
    MagicRoute.to('/status');
  }

  /// Creates a new status page from [draft] via the ORM `StatusPage.save()`
  /// (`POST /status-pages`).
  ///
  /// Maps the value-object draft to a fresh (non-existing) model so `save()`
  /// issues a create, then checks the bool result: on success, refreshes the
  /// bound view, surfaces a success toast, and returns to the list; on a false
  /// result, logs it and surfaces an error toast without navigating away.
  Future<void> create(StatusPageConfig draft) async {
    final StatusPage page = _modelFrom(draft, existing: false);

    final bool ok = await page.save();
    if (!ok) {
      Log.error('[StatusPageController.create] save() returned false');
      _toastError(null);
      return;
    }

    refreshUI();
    Magic.success(trans('uptizm.status.editor_form_create_page'), draft.name);
    MagicRoute.to('/status');
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

  /// Removes [subscriber] from the page [pageId] roster, surfaces an honest
  /// info toast (the change is local-only, not persisted), and rebuilds the
  /// bound view.
  ///
  /// Local-only mutation: the backend has no status-page-subscriber write
  /// endpoint yet (see the class docblock), so this cannot persist beyond the
  /// controller's own lifetime.
  void removeSubscriber(String pageId, Subscriber subscriber) {
    subscribersFor(pageId).remove(subscriber);
    MagicFeedback.info(
      trans('uptizm.status.subscribers_remove_toast_title'),
      trans('uptizm.status.subscribers_remove_toast_description', {
        'email': subscriber.email,
      }),
    );
    refreshUI();
  }

  // ---------------------------------------------------------------------------
  // Wire helpers
  // ---------------------------------------------------------------------------

  /// Builds a [StatusPage] persistence model from a [StatusPageConfig] draft.
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
  StatusPage _modelFrom(StatusPageConfig draft, {required bool existing}) {
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
