import 'package:flutter/widgets.dart' show Color;
import 'package:magic/magic.dart';

import '../mocks/status_pages.dart';
import '../mocks/status_pages.dart' as status_pages_fixture;
import '../../resources/views/status/status_form_support.dart' show aiDraftFor;

/// Controller backing the four routed status-page views (list, editor,
/// preview, subscribers).
///
/// [statusPages]/[configById]/[subscribersFor] stay fixture-backed reads (see
/// their docblocks): the `StatusPageConfig`/`Subscriber` fixture models have
/// no wire codec and no field parity with the backend's `domain_mode`/
/// `brand_color` shape (see [_wireDomainMode]/[_wireBrandColor]), and giving
/// them one is outside this controller's file scope. The business actions
/// below ([save], [create], [attachMonitor], [detachMonitor],
/// [reorderMonitors]) DO write through to the live `api/v1` status-page
/// endpoints (`StatusPageController` on the backend), mirroring
/// `monitor_controller.dart:145-221`'s try/log/toast/refresh shape.
/// [removeSubscriber] stays a local-only mutation: the backend has no
/// status-page-subscriber write endpoint yet (only the `StatusPageSubscriber`
/// model exists, unrouted), so there is nothing to persist to.
class StatusPageController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static StatusPageController get instance =>
      Magic.findOrPut(StatusPageController.new);

  /// Mutable per-page subscriber working sets, keyed by [StatusPageConfig.id].
  ///
  /// Lazily seeded from the const fixtures on first access (the fixture map is
  /// never mutated in place); [removeSubscriber] edits the working copy so a
  /// removal survives across rebuilds within the controller's lifetime.
  final Map<String, List<Subscriber>> _subscribers = {};

  /// Every configured status page (fixture access; see the class docblock for
  /// why the read side stays fixture-backed).
  List<StatusPageConfig> get statusPages => status_pages_fixture.statusPages;

  /// Resolves a status page by [id]; `null` when none matches (fixture access).
  StatusPageConfig? configById(String? id) => findStatusPage(id);

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

  /// Saves an existing status page [draft] via `PUT /status-pages/{id}`.
  ///
  /// On success, refreshes the bound view, surfaces a success toast, and
  /// returns to the list. On a failed response or a transport error, logs the
  /// failure and surfaces an error toast without navigating away, so the
  /// operator can retry from the still-open editor.
  Future<void> save(StatusPageConfig draft) async {
    try {
      final response = await Http.put(
        '/status-pages/${draft.id}',
        data: _wirePayload(draft),
      );
      if (!response.successful) {
        Log.error(
          '[StatusPageController.save] ${draft.id}: ${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return;
      }

      refreshUI();
      Magic.success(trans('uptizm.status.editor_form_save'), draft.name);
      MagicRoute.to('/status');
    } catch (error) {
      Log.error('[StatusPageController.save] ${draft.id} failed: $error');
      _toastError(null);
    }
  }

  /// Creates a new status page from [draft] via `POST /status-pages`.
  ///
  /// On success, refreshes the bound view, surfaces a success toast, and
  /// returns to the list. On a failed response or a transport error, logs the
  /// failure and surfaces an error toast without navigating away.
  Future<void> create(StatusPageConfig draft) async {
    try {
      final response = await Http.post(
        '/status-pages',
        data: _wirePayload(draft),
      );
      if (!response.successful) {
        Log.error(
          '[StatusPageController.create] ${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return;
      }

      refreshUI();
      Magic.success(
        trans('uptizm.status.editor_form_create_page'),
        draft.name,
      );
      MagicRoute.to('/status');
    } catch (error) {
      Log.error('[StatusPageController.create] failed: $error');
      _toastError(null);
    }
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

  /// Removes [subscriber] from the page [pageId] roster, surfaces a success
  /// toast, and rebuilds the bound view.
  ///
  /// Local-only mutation: the backend has no status-page-subscriber write
  /// endpoint yet (see the class docblock), so this cannot persist beyond the
  /// controller's own lifetime.
  void removeSubscriber(String pageId, Subscriber subscriber) {
    subscribersFor(pageId).remove(subscriber);
    Magic.success(
      trans('uptizm.status.subscribers_remove_confirm_title'),
      subscriber.email,
    );
    refreshUI();
  }

  // ---------------------------------------------------------------------------
  // Wire helpers
  // ---------------------------------------------------------------------------

  /// Maps a [StatusPageConfig] draft to the backend's
  /// `Store`/`UpdateStatusPageRequest` field shape.
  ///
  /// `monitorIds`/`metricKeys` are deliberately excluded: monitor membership
  /// is a separate pivot managed through [attachMonitor]/[detachMonitor], and
  /// metric selection has no live endpoint yet (the backend's `metrics()`
  /// pivot exists for schema completeness only, per `StatusPage.php`).
  Map<String, dynamic> _wirePayload(StatusPageConfig draft) {
    return <String, dynamic>{
      'name': draft.name,
      'slug': draft.slug,
      'domain_mode': _wireDomainMode(draft.domainMode),
      'brand_color': _wireBrandColor(draft.brandColor),
      'logo_text': draft.logoText,
      'description': draft.description,
      'subscriptions_enabled': draft.subscriptionsEnabled,
    };
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
