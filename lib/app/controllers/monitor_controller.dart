import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';

import '../mocks/monitors.dart';
import '../mocks/status.dart';

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
        if (!response.successful) {
          Log.error('[MonitorController.create] ${response.errorMessage}');
          Magic.error(
            trans('uptizm.monitors.toast_save_failed_title'),
            response.errorMessage ??
                trans('uptizm.monitors.toast_save_failed_description'),
          );
          // Stay on the form so the user can correct + retry instead of being
          // bounced to the list with no monitor created and no feedback.
          return;
        }
        await reload();
      } catch (error) {
        Log.error('[MonitorController.create] failed: $error');
        Magic.error(
          trans('uptizm.monitors.toast_save_failed_title'),
          trans('uptizm.monitors.toast_save_failed_description'),
        );
        return;
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
        if (!response.successful) {
          Log.error('[MonitorController.save] $id: ${response.errorMessage}');
          Magic.error(
            trans('uptizm.monitors.toast_save_failed_title'),
            response.errorMessage ??
                trans('uptizm.monitors.toast_save_failed_description'),
          );
          return;
        }
        await reload();
      } catch (error) {
        Log.error('[MonitorController.save] $id failed: $error');
        Magic.error(
          trans('uptizm.monitors.toast_save_failed_title'),
          trans('uptizm.monitors.toast_save_failed_description'),
        );
        return;
      }
    }
    MagicRoute.to('/monitors/$id');
  }

  // ---------------------------------------------------------------------------
  // 90-day uptime history
  // ---------------------------------------------------------------------------

  /// Loads the 90-day uptime history for monitor [id] from the existing
  /// bucketed `GET /monitors/:id/response-times?range=90d` endpoint,
  /// bucketing the response into daily [UptimeSegment]s via
  /// [mapBucketsToUptime90].
  ///
  /// Degrades to an empty list on any failure (network error, non-2xx, or a
  /// malformed payload), logged rather than thrown, so the calling view's
  /// uptime bar renders its own empty state instead of crashing.
  Future<List<UptimeSegment>> loadUptime90(String id) async {
    try {
      final response = await Http.get(
        '/monitors/$id/response-times?range=90d',
      );
      if (!response.successful) {
        Log.error(
          '[MonitorController.loadUptime90] $id: ${response.errorMessage}',
        );
        return const [];
      }

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return const [];

      return mapBucketsToUptime90(
        raw.whereType<Map<String, dynamic>>().toList(),
      );
    } catch (error) {
      Log.error('[MonitorController.loadUptime90] $id failed: $error');
      return const [];
    }
  }

  /// Maps bucketed `MonitorCheckResource` rows (as returned by `GET
  /// /monitors/:id/response-times?range=90d`) into 90 daily [UptimeSegment]s,
  /// oldest-first (index 0, ~89 days ago) to newest-last (index 89, today),
  /// matching the axis labels rendered below [UptimeBar].
  ///
  /// Each row's `checked_at` is bucketed into a day offset from [now]
  /// (defaults to [DateTime.now()]); a day with multiple buckets folds to
  /// the WORST status seen that day (down > degraded > everything else maps
  /// to up), mirroring the backend's own bucket-folding precedence
  /// (`CheckAggregateService::responseTimeSamples`). A day with no bucket
  /// data at all defaults to [StatusKey.up], matching the design-lab
  /// [uptime90] generator's existing unspecified-day default. A bucket
  /// falling outside the trailing 90-day window is ignored.
  ///
  /// Exposed as a public static method (rather than a private instance
  /// method) so the mapping contract is directly unit-testable without a
  /// network round-trip.
  static List<UptimeSegment> mapBucketsToUptime90(
    List<Map<String, dynamic>> rows, {
    DateTime? now,
  }) {
    final DateTime today = _dateOnly(now ?? DateTime.now());
    final List<StatusKey?> days = List<StatusKey?>.filled(90, null);

    for (final Map<String, dynamic> row in rows) {
      final DateTime? checkedAt = DateTime.tryParse(
        (row['checked_at'] as String?) ?? '',
      )?.toLocal();
      if (checkedAt == null) continue;

      final int daysAgo = today.difference(_dateOnly(checkedAt)).inDays;
      if (daysAgo < 0 || daysAgo > 89) continue;

      final int index = 89 - daysAgo;
      final StatusKey status = statusKeyFromWire(
        row['status'] as String?,
        fallback: StatusKey.up,
      );
      days[index] = _worseOf(days[index], status);
    }

    return List<UptimeSegment>.generate(90, (i) {
      final int daysAgo = 89 - i;
      return UptimeSegment(
        status: days[i] ?? StatusKey.up,
        label: daysAgo == 0 ? 'today' : '${daysAgo}d ago',
      );
    });
  }

  /// Truncates [dt] to a local calendar day (midnight), used for the
  /// day-bucketing arithmetic in [mapBucketsToUptime90].
  static DateTime _dateOnly(DateTime dt) => DateTime(dt.year, dt.month, dt.day);

  /// Folds two [StatusKey]s to the worse one for a single day's bucket,
  /// mirroring the backend's down > degraded > up precedence. Any status
  /// other than `down`/`degraded` (e.g. `paused`, `info`, `ai`) is treated
  /// as `up` for this fold, since the uptime bar's vocabulary is
  /// up/down/degraded only.
  static StatusKey _worseOf(StatusKey? current, StatusKey next) {
    const Map<StatusKey, int> precedence = {
      StatusKey.down: 2,
      StatusKey.degraded: 1,
    };
    final int currentRank = precedence[current] ?? 0;
    final int nextRank = precedence[next] ?? 0;
    return nextRank >= currentRank ? next : (current ?? StatusKey.up);
  }
}
