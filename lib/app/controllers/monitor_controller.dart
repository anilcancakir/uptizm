import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';

import '../mocks/monitors.dart';

/// Controller backing the four routed monitor screens ([MonitorsListView],
/// [MonitorDetailView], [MonitorCreateView], [MonitorEditView]).
///
/// Sources the monitor inventory from the backend `api/v1` (`GET /monitors`,
/// `GET /monitors/:id`) instead of the design-lab fixtures, and drives the
/// pause/resume/delete lifecycle actions against the real endpoints. The
/// inventory is cached in [_monitors] so the synchronous [monitors] and
/// [monitorById] getters (both read directly inside a view's `build()`) never
/// need to await a request; [reload] and the background per-monitor refresh
/// inside [monitorById] keep that cache warm.
///
/// [create] and [save] both accept an OPTIONAL `fields` map: `MonitorForm`'s
/// `onSubmit` threads its `buildFields()` result through so Submit fires the
/// real `POST`/`PUT`, while Cancel calls `create()`/`save(id)` with no
/// arguments and stays navigation-only — firing the same write on Cancel as
/// on Submit would silently persist stale field values.
class MonitorController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static MonitorController get instance =>
      Magic.findOrPut(MonitorController.new);

  /// In-memory cache of the monitor inventory, populated by [reload] and kept
  /// warm by the per-monitor background refresh in [monitorById]. Empty until
  /// the first successful fetch resolves.
  List<MonitorSummary> _monitors = [];

  /// The monitor inventory, sourced from `GET /monitors`.
  List<MonitorSummary> get monitors => _monitors;

  /// Seeds the in-memory inventory directly for a widget/controller test,
  /// bypassing the network.
  ///
  /// The wired [reload]/[monitorById] path sources the inventory from
  /// `GET /monitors`, which a bare test host cannot serve; this lets a test
  /// populate [monitors] (and therefore [monitorById]) with known fixtures
  /// before pumping a bound view, so the view renders against real data
  /// instead of the empty degradation state. Notifies listeners so an
  /// already-mounted view rebuilds against the seeded inventory.
  @visibleForTesting
  void seedForTest(List<MonitorSummary> seed) {
    _monitors = List<MonitorSummary>.from(seed);
    refreshUI();
  }

  /// Bootstraps the inventory the first time this controller backs a view.
  @override
  void onInit() {
    super.onInit();
    reload();
  }

  /// Non-destructive list refresh: fetches `GET /monitors` and republishes
  /// the inventory on success. Preserves the previously loaded inventory on
  /// any failure (network error, non-2xx) so the list view never flickers
  /// into an empty state between reloads.
  Future<void> reload() async {
    try {
      final response = await Http.get('/monitors');
      if (!response.successful) return;

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return;

      _monitors = raw
          .whereType<Map<String, dynamic>>()
          .map(MonitorSummary.fromMap)
          .toList();
      refreshUI();
    } catch (_) {
      // Deliberate degradation: a transport failure (including an unregistered
      // `network` service in a bare test host) or a malformed payload keeps the
      // last-known-good inventory (empty before the first successful fetch), so
      // `onInit`/`reload` never throws and the list renders its empty or stale
      // state instead of crashing.
    }
  }

  /// Resolves a monitor by [id] from the cached inventory, or `null` when
  /// none matches (unknown id, or the cache has not loaded yet).
  ///
  /// The views call this synchronously inside `build()`, so this cannot
  /// itself be a `Future`. It answers from [_monitors] immediately and, when
  /// [id] is present, also fires a background `GET /monitors/:id` refresh
  /// (via [_refreshOne]) that republishes the freshest single-resource
  /// fields and triggers a rebuild once it resolves.
  MonitorSummary? monitorById(String? id) {
    if (id == null) return null;

    final MonitorSummary? cached = _cachedById(id);
    _refreshOne(id);
    return cached;
  }

  /// Synchronous cache lookup by [id]. Returns `null` when absent.
  MonitorSummary? _cachedById(String id) {
    for (final MonitorSummary m in _monitors) {
      if (m.id == id) return m;
    }
    return null;
  }

  /// Background single-resource refresh for [id]: fetches `GET
  /// /monitors/:id` and merges the result into [_monitors] (replacing an
  /// existing entry or appending a new one), then notifies listeners.
  /// Silently no-ops on failure so a transient error never disturbs the
  /// already-cached entry.
  Future<void> _refreshOne(String id) async {
    try {
      final response = await Http.get('/monitors/$id');
      if (!response.successful) return;

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (data is! Map<String, dynamic>) return;

      final MonitorSummary fresh = MonitorSummary.fromMap(data);
      final int index = _monitors.indexWhere((m) => m.id == id);
      _monitors = index == -1
          ? [..._monitors, fresh]
          : [for (final MonitorSummary m in _monitors) m.id == id ? fresh : m];
      refreshUI();
    } catch (_) {
      // Silent no-op on failure (including an unregistered `network` service):
      // a transient single-resource error never disturbs the already-cached
      // entry, and the synchronous `monitorById` caller never sees a throw.
    }
  }

  // ---------------------------------------------------------------------------
  // Business actions
  // ---------------------------------------------------------------------------

  /// Pauses the monitor [id] via `POST /monitors/:id/pause`, refreshes the
  /// inventory, and surfaces the paused toast. No-op when [id] resolves to no
  /// cached monitor, or when the request fails (degrades silently rather than
  /// blocking the UI).
  Future<void> pause(String id) async {
    final MonitorSummary? monitor = _cachedById(id);
    if (monitor == null) return;

    try {
      final response = await Http.post('/monitors/$id/pause');
      if (!response.successful) {
        Log.error('[MonitorController.pause] $id: ${response.errorMessage}');
        return;
      }

      await reload();
      Magic.success(
        trans('uptizm.monitors.toast_paused_title'),
        trans('uptizm.monitors.toast_paused_description', {
          'name': monitor.name,
        }),
      );
    } catch (error) {
      Log.error('[MonitorController.pause] $id failed: $error');
    }
  }

  /// Resumes the monitor [id] via `POST /monitors/:id/resume`, refreshes the
  /// inventory, and surfaces the resumed toast. No-op when [id] resolves to
  /// no cached monitor, or when the request fails.
  Future<void> resume(String id) async {
    final MonitorSummary? monitor = _cachedById(id);
    if (monitor == null) return;

    try {
      final response = await Http.post('/monitors/$id/resume');
      if (!response.successful) {
        Log.error('[MonitorController.resume] $id: ${response.errorMessage}');
        return;
      }

      await reload();
      Magic.success(
        trans('uptizm.monitors.toast_resumed_title'),
        trans('uptizm.monitors.toast_resumed_description', {
          'name': monitor.name,
        }),
      );
    } catch (error) {
      Log.error('[MonitorController.resume] $id failed: $error');
    }
  }

  /// Deletes the monitor [id] via `DELETE /monitors/:id` (the view runs the
  /// confirm dialog before calling), evicts it from the cache, surfaces the
  /// deleted toast, and returns to the monitors list. No-op when [id]
  /// resolves to no cached monitor, or when the request fails.
  Future<void> delete(String id) async {
    final MonitorSummary? monitor = _cachedById(id);
    if (monitor == null) return;

    try {
      final response = await Http.delete('/monitors/$id');
      if (!response.successful) {
        Log.error('[MonitorController.delete] $id: ${response.errorMessage}');
        return;
      }

      _monitors = _monitors.where((m) => m.id != id).toList();
      refreshUI();
      Magic.success(
        trans('uptizm.monitors.toast_deleted_title'),
        trans('uptizm.monitors.toast_deleted_description', {
          'name': monitor.name,
        }),
      );
      MagicRoute.to('/monitors');
    } catch (error) {
      Log.error('[MonitorController.delete] $id failed: $error');
    }
  }

  /// Creates a monitor and returns to the monitors list.
  ///
  /// [fields] is the raw create-form field map (`name`, `url`, `type`,
  /// `method`, `check_interval_sec`, `timeout_sec`, `regions`,
  /// `expected_status_code`, ...); omitted, this stays navigation-only
  /// exactly as before (see the class docblock for why). When present, it
  /// fires `POST /monitors` and reloads the inventory before navigating.
  Future<void> create([Map<String, dynamic>? fields]) async {
    if (fields != null) {
      try {
        final response = await Http.post('/monitors', data: fields);
        if (response.successful) {
          await reload();
        } else {
          Log.error('[MonitorController.create] ${response.errorMessage}');
        }
      } catch (error) {
        Log.error('[MonitorController.create] failed: $error');
      }
    }
    MagicRoute.to('/monitors');
  }

  /// Saves the monitor [id] and returns to its detail route.
  ///
  /// [fields] is the raw edit-form field map; omitted, this stays
  /// navigation-only exactly as before (see the class docblock). When
  /// present, it fires `PUT /monitors/:id` and reloads the inventory before
  /// navigating.
  Future<void> save(String id, [Map<String, dynamic>? fields]) async {
    if (fields != null) {
      try {
        final response = await Http.put('/monitors/$id', data: fields);
        if (response.successful) {
          await reload();
        } else {
          Log.error('[MonitorController.save] $id: ${response.errorMessage}');
        }
      } catch (error) {
        Log.error('[MonitorController.save] $id failed: $error');
      }
    }
    MagicRoute.to('/monitors/$id');
  }
}
