import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'entitlement_controller.dart';
import '../models/monitor.dart';
import '../support/monitor_types.dart' show UptimeSegment;
import '../enums/status_key.dart';
import '../../resources/views/monitors/monitor_form_support.dart' show AiMetricSeed;

/// The AI-derived monitor configuration returned by `POST /monitors/analyze`.
///
/// Mirrors the backend `AnalysisResult` wire shape (see the Laravel
/// `AnalysisResult::toArray()`): a SUGGESTION for a URL not yet turned into a
/// monitor, never a decision. The AI-flow review step in [MonitorCreateView]
/// prefills [MonitorForm] from this value; the operator still submits the
/// form themselves.
@immutable
class MonitorAnalysis {
  /// The analyzed URL, echoed back by the backend.
  final String url;

  /// The AI-suggested display name for the monitor.
  final String name;

  /// The suggested check interval, in seconds.
  final int recommendedIntervalSeconds;

  /// The suggested warn-severity response-time bound, in milliseconds.
  final int recommendedWarnThresholdMs;

  /// The suggested critical-severity response-time bound, in milliseconds.
  final int recommendedCriticalThresholdMs;

  /// The suggested relay regions to probe from.
  final List<String> recommendedRegions;

  /// The narration behind the suggestion.
  final String rationale;

  /// The AI-proposed custom metrics for this monitor, decoded from
  /// `suggested_metrics`. Each entry's `path` was generated and proven
  /// evaluable by the backend (the model only selects among candidates, it
  /// never authors a path). Empty when the backend omits the key (a stale
  /// client against a new backend, or a new client against an old backend
  /// must both keep working) or proposes nothing.
  final List<AiMetricSeed> suggestedMetrics;

  /// Creates a [MonitorAnalysis].
  const MonitorAnalysis({
    required this.url,
    required this.name,
    required this.recommendedIntervalSeconds,
    required this.recommendedWarnThresholdMs,
    required this.recommendedCriticalThresholdMs,
    required this.recommendedRegions,
    required this.rationale,
    this.suggestedMetrics = const [],
  });

  /// Decodes a [MonitorAnalysis] from the `data` object of the `POST
  /// /monitors/analyze` response.
  factory MonitorAnalysis.fromMap(Map<String, dynamic> map) {
    return MonitorAnalysis(
      url: map['url'] as String? ?? '',
      name: map['name'] as String? ?? '',
      recommendedIntervalSeconds:
          (map['recommended_interval_seconds'] as num?)?.toInt() ?? 30,
      recommendedWarnThresholdMs:
          (map['recommended_warn_threshold_ms'] as num?)?.toInt() ?? 0,
      recommendedCriticalThresholdMs:
          (map['recommended_critical_threshold_ms'] as num?)?.toInt() ?? 0,
      recommendedRegions:
          (map['recommended_regions'] as List?)?.whereType<String>().toList() ??
          const [],
      rationale: map['rationale'] as String? ?? '',
      suggestedMetrics:
          (map['suggested_metrics'] as List?)
              ?.whereType<Map<String, dynamic>>()
              .map(AiMetricSeed.fromMap)
              .toList() ??
          const [],
    );
  }
}

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
/// arguments and stays navigation-only; firing the same write on Cancel as
/// on Submit would silently persist stale field values.
class MonitorController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor, registering the controller on first access.
  static MonitorController get instance =>
      Magic.findOrPut(MonitorController.new);

  /// In-memory cache of the monitor inventory, populated by [reload] and kept
  /// warm by the per-monitor background refresh in [monitorById]. Empty until
  /// the first successful fetch resolves.
  List<Monitor> _monitors = [];

  /// The monitor inventory, sourced from `GET /monitors` via [Monitor.all].
  List<Monitor> get monitors => _monitors;

  /// Whether a [reload] has completed at least once, successfully or not.
  bool _resolvedOnce = false;

  /// Whether the FIRST inventory read is still in flight.
  ///
  /// Separates "we have not asked yet" from "we asked and there are none". The
  /// list view renders a skeleton while this is true instead of asserting an
  /// empty inventory before the first answer arrives, which is what made a
  /// populated account flash "No monitors yet" on every cold open.
  ///
  /// Only the FIRST read counts: a later refetch (the view reloads on every
  /// route entry) leaves this false so the rows stay on screen rather than
  /// flashing a skeleton over data the operator is already reading.
  bool get isFirstLoad => !_resolvedOnce;

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
  void seedForTest(List<Monitor> seed) {
    _monitors = List<Monitor>.from(seed);
    // Seeded state is a resolved state, so a bound view renders the rows rather
    // than a skeleton waiting for a fetch the test never makes.
    _resolvedOnce = true;
    refreshUI();
  }

  /// Bootstraps the inventory the first time this controller backs a view.
  @override
  void onInit() {
    super.onInit();
    reload();
  }

  /// Cancels every pending manual-check cooldown [Timer] (see
  /// [_startCooldown]) before the base class marks this controller disposed.
  ///
  /// This controller is a long-lived singleton the widget tree never
  /// disposes, so in practice each cooldown timer already self-cancels once
  /// it reaches zero; this is the defensive backstop for the case where the
  /// controller itself is torn down (e.g. a test's `Magic.flush()`) while one
  /// is still running.
  @override
  void onClose() {
    for (final Timer timer in _cooldownTimers.values) {
      timer.cancel();
    }
    _cooldownTimers.clear();
    super.onClose();
  }

  /// Non-destructive list refresh: republishes the inventory from [Monitor.all]
  /// (`GET /monitors`) on a non-empty fetch, preserving the previously loaded
  /// inventory otherwise so the list view never flickers into an empty state
  /// between reloads.
  ///
  /// [Monitor.all] absorbs transport failures internally and resolves an empty
  /// list rather than throwing (including for an unregistered `network` service
  /// in a bare test host); it cannot distinguish that failure from a genuine
  /// empty result. Treating both as "keep the last-known-good inventory"
  /// mirrors the pre-ORM decode, which returned early on a malformed or
  /// failed payload: `onInit`/`reload` never throws, and an explicit removal
  /// still updates the cache through [delete] rather than a reload.
  ///
  /// Resolving flips [isFirstLoad] false either way, so the view swaps its
  /// skeleton for the rows or for the honest empty state.
  Future<void> reload() async {
    final bool firstLoad = isFirstLoad;
    final List<Monitor> fetched = await Monitor.all();
    _resolvedOnce = true;

    if (fetched.isEmpty) {
      // The cache stands, but a first read that came back empty still has to
      // repaint: the view is showing a skeleton and needs to hear that the
      // answer arrived.
      if (firstLoad) refreshUI();
      return;
    }

    _monitors = fetched;
    refreshUI();
  }

  /// Drops the previous session's monitor inventory, publishes the cleared
  /// state, then refetches for the identity that is now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): [reload] treats
  /// an empty fetch as "keep the last-known-good inventory", which across an
  /// identity change would leave the previous team's monitors listed (and
  /// resolvable through [monitorById]) even when the refetch fails outright.
  /// [_lastAnalyzeWasGated] belongs to the previous session's plan wall, so it
  /// is cleared with the rest.
  @override
  Future<void> resetForSession() async {
    _monitors = [];
    _lastAnalyzeWasGated = false;
    // Back to "not asked yet": the incoming identity must get a skeleton, not
    // the previous tenant's conclusion that there are no monitors.
    _resolvedOnce = false;
    refreshUI();

    await reload();
  }

  /// Resolves a monitor by [id] from the cached inventory, or `null` when
  /// none matches (unknown id, or the cache has not loaded yet).
  ///
  /// The views call this synchronously inside `build()`, so it MUST stay a
  /// pure, side-effect-free cache read: it answers from [_monitors] and never
  /// performs I/O or notifies listeners. A single-resource refresh is a
  /// separate, explicit [refreshOne] call a view issues ONCE from `initState`
  /// (or on an id change), never from `build`: firing it from `build` self
  /// loops (refresh -> `refreshUI` -> rebuild -> `build` -> refresh), pegging
  /// the main isolate with ~10 `GET /monitors/:id` per second and dropping
  /// scroll to a few FPS.
  Monitor? monitorById(String? id) {
    if (id == null) return null;

    return _cachedById(id);
  }

  /// Synchronous cache lookup by [id]. Returns `null` when absent.
  Monitor? _cachedById(String id) {
    for (final Monitor m in _monitors) {
      if (m.id == id) return m;
    }
    return null;
  }

  /// Background single-resource refresh for [id]: fetches the monitor via
  /// [Monitor.find] (`GET /monitors/:id`) and merges the result into
  /// [_monitors] (replacing an existing entry or appending a new one), then
  /// notifies listeners. Silently no-ops on failure so a transient error never
  /// disturbs the already-cached entry.
  ///
  /// [Monitor.find] absorbs transport failures internally and resolves `null`
  /// rather than throwing (including for an unregistered `network` service), so
  /// the synchronous `monitorById` caller never sees a throw. It can, however,
  /// hydrate an empty model from a bodyless `200 {}` response (the ORM treats a
  /// `data`-less envelope as valid), so the merge is gated on `fresh.id == id`:
  /// only a fetch that actually resolved the requested monitor replaces the
  /// cached entry, mirroring the pre-ORM decode's no-op on a malformed payload.
  ///
  /// Call this ONCE from a view's `initState` (or on an id change), NEVER from
  /// `build`: its `refreshUI()` notifies listeners, so a `build`-time call self
  /// loops and floods the backend.
  Future<void> refreshOne(String id) async {
    final Monitor? fresh = await Monitor.find(id);
    if (fresh == null || fresh.id != id) return;

    final int index = _monitors.indexWhere((m) => m.id == id);
    _monitors = index == -1
        ? [..._monitors, fresh]
        : [for (final Monitor m in _monitors) m.id == id ? fresh : m];
    refreshUI();
  }

  // ---------------------------------------------------------------------------
  // Manual-check cooldown
  // ---------------------------------------------------------------------------

  /// Remaining manual-check cooldown, in whole seconds, keyed by monitor id.
  /// Populated by [_startCooldown] when [runCheckNow] is refused with a 429
  /// (see the backend's `manualCheckCooldownResponse()`); absent for a
  /// monitor that is not currently cooling down.
  final Map<String, int> _cooldownSecondsRemaining = {};

  /// The ticking [Timer] counting a monitor's cooldown down to zero, keyed by
  /// monitor id. Cancels itself once the cooldown reaches zero (see
  /// [_startCooldown]); [onClose] cancels any still running defensively.
  final Map<String, Timer> _cooldownTimers = {};

  /// The remaining manual-check cooldown, in whole seconds, for monitor [id].
  /// `null` means the monitor is not currently cooling down, so the "Check
  /// now" action is available. The view polls this synchronously in `build()`
  /// and rebuilds on every tick via [refreshUI].
  int? cooldownSecondsFor(String id) => _cooldownSecondsRemaining[id];

  /// Starts (or restarts) the manual-check cooldown countdown for monitor
  /// [id] at [seconds] remaining.
  ///
  /// Ticks once a second, decrementing the remaining count and notifying
  /// listeners so the "Check now" button re-renders its countdown label; this
  /// is a local clock, never a re-ask of the server for the remaining time.
  /// Self-cancels (and clears the entry) once the cooldown reaches zero, so
  /// the button re-enables without any further network round-trip.
  void _startCooldown(String id, int seconds) {
    _cooldownTimers[id]?.cancel();
    _cooldownSecondsRemaining[id] = seconds;
    refreshUI();

    _cooldownTimers[id] = Timer.periodic(const Duration(seconds: 1), (timer) {
      final int remaining = (_cooldownSecondsRemaining[id] ?? 1) - 1;
      if (remaining <= 0) {
        timer.cancel();
        _cooldownTimers.remove(id);
        _cooldownSecondsRemaining.remove(id);
      } else {
        _cooldownSecondsRemaining[id] = remaining;
      }
      refreshUI();
    });
  }

  /// Extracts `retry_after_seconds` from a 429 cooldown response body (the
  /// backend's `manualCheckCooldownResponse()` shape), defaulting to 1 second
  /// when the body is malformed or the key is absent so the button still
  /// recovers rather than staying disabled forever.
  int _retryAfterSeconds(MagicResponse response) {
    final Object? data = response.data;
    if (data is! Map<String, dynamic>) return 1;
    return (data['retry_after_seconds'] as num?)?.toInt() ?? 1;
  }

  // ---------------------------------------------------------------------------
  // Business actions
  // ---------------------------------------------------------------------------

  /// Pauses the monitor [id] via `POST /monitors/:id/pause`, refreshes the
  /// inventory, and surfaces the paused toast. No-op when [id] resolves to no
  /// cached monitor, or when the request fails (degrades silently rather than
  /// blocking the UI).
  Future<void> pause(String id) async {
    final Monitor? monitor = _cachedById(id);
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
    final Monitor? monitor = _cachedById(id);
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

  /// Runs an out-of-schedule check for the monitor [id] via
  /// `POST /monitors/:id/test`, then refreshes that monitor.
  ///
  /// The endpoint answers `202 Accepted` with no body: the probe is queued for
  /// every configured region and runs at the edge, so the result does not exist
  /// yet when this returns. The toast therefore says the check was QUEUED
  /// rather than claiming a result, and [refreshOne] picks up whatever has
  /// landed by the time it resolves; anything later arrives over the team's
  /// realtime channel like any scheduled check.
  ///
  /// No-op when [id] resolves to no cached monitor. A failed request logs and
  /// surfaces an error toast rather than silently doing nothing, since the
  /// operator explicitly asked for a check. A 429 is not a failure: it means
  /// the per-monitor cooldown is still running (every manual check queues a
  /// real signed relay call per region, so the cooldown exists to stop the
  /// button from spending money), and starts the countdown in
  /// [cooldownSecondsFor] instead of the error toast.
  Future<void> runCheckNow(String id) async {
    final Monitor? monitor = _cachedById(id);
    if (monitor == null) return;

    try {
      final response = await Http.post('/monitors/$id/test');
      if (!response.successful) {
        if (response.statusCode == 429) {
          _startCooldown(id, _retryAfterSeconds(response));
          return;
        }

        Log.error(
          '[MonitorController.runCheckNow] $id: ${response.errorMessage}',
        );
        Magic.error(
          trans('uptizm.monitors.toast_check_failed_title'),
          response.errorMessage ??
              trans('uptizm.monitors.toast_check_failed_description'),
        );

        return;
      }

      Magic.success(
        trans('uptizm.monitors.toast_check_queued_title'),
        trans('uptizm.monitors.toast_check_queued_description', {
          'name': monitor.name,
        }),
      );
      await refreshOne(id);
    } catch (error) {
      Log.error('[MonitorController.runCheckNow] $id failed: $error');
      Magic.error(
        trans('uptizm.monitors.toast_check_failed_title'),
        trans('uptizm.monitors.toast_check_failed_description'),
      );
    }
  }

  /// Deletes the monitor [id] through the [Monitor] ORM (`DELETE /monitors/:id`;
  /// the view runs the confirm dialog before calling), evicts it from the
  /// cache, surfaces the deleted toast, and returns to the monitors list. No-op
  /// when [id] resolves to no cached monitor.
  ///
  /// [Monitor.delete] absorbs transport failures internally and returns `false`
  /// rather than throwing; on a `false` result the delete surfaces the
  /// save-failed error toast and leaves the cache untouched instead of silently
  /// evicting a monitor the backend still holds.
  Future<void> delete(String id) async {
    final Monitor? monitor = _cachedById(id);
    if (monitor == null) return;

    final bool ok = await monitor.delete();
    if (!ok) {
      Log.error('[MonitorController.delete] $id: delete returned false');
      Magic.error(
        trans('uptizm.monitors.toast_save_failed_title'),
        trans('uptizm.monitors.toast_save_failed_description'),
      );
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
  }

  /// Creates a monitor and returns to the monitors list.
  ///
  /// [fields] is the raw create-form field map (`name`, `url`, `type`,
  /// `method`, `check_interval_sec`, `timeout_sec`, `regions`,
  /// `expected_status_code`, ...); omitted, this stays navigation-only
  /// exactly as before (see the class docblock for why). When present, it
  /// mass-assigns them into a fresh [Monitor] and persists it through the ORM
  /// (`POST /monitors`), then reloads the inventory before navigating.
  ///
  /// Returns the backend per-field validation errors (single message per field,
  /// keyed by the wire field name) so the form can render a server 422 inline;
  /// an empty map means success (or a navigation-only call). A `false` save that
  /// carries field errors ([Monitor.validationErrors]) STAYS on the form with no
  /// toast so the user corrects the flagged fields; a `false` save with NO field
  /// errors (a transport error / 500) keeps the generic save-failed toast and
  /// returns an empty map. [Monitor.save] absorbs transport failures internally
  /// and returns `false` rather than throwing.
  Future<Map<String, String>> create([Map<String, dynamic>? fields]) async {
    if (fields != null) {
      final Monitor monitor = Monitor()..fill(fields);
      final bool ok = await monitor.save();
      if (!ok) {
        final Map<String, String>? fieldErrors = _fieldErrorsOrToast(monitor);
        if (fieldErrors != null) return fieldErrors;
        return const {};
      }
      await reload();
    }
    MagicRoute.to('/monitors');
    return const {};
  }

  /// Saves the monitor [id] and returns to its detail route.
  ///
  /// [fields] is the raw edit-form field map; omitted, this stays
  /// navigation-only exactly as before (see the class docblock). When present,
  /// it resolves the monitor through the ORM ([Monitor.find]), mass-assigns the
  /// edited fields, and persists it (`PUT /monitors/:id`), then reloads the
  /// inventory before navigating.
  ///
  /// Returns the backend per-field validation errors (single message per field,
  /// keyed by the wire field name) so the form can render a server 422 inline;
  /// an empty map means success, a navigation-only call, or a missing monitor
  /// (the id no longer resolves). A `false` save that carries field errors
  /// ([Monitor.validationErrors]) STAYS on the form with no toast; a `false`
  /// save with NO field errors (a transport error / 500) keeps the generic
  /// save-failed toast and returns an empty map. [Monitor.save] absorbs
  /// transport failures internally and returns `false` rather than throwing.
  Future<Map<String, String>> save(
    String id, [
    Map<String, dynamic>? fields,
  ]) async {
    if (fields != null) {
      final Monitor? monitor = await Monitor.find(id);
      if (monitor == null) return const {};

      monitor.fill(fields);
      final bool ok = await monitor.save();
      if (!ok) {
        final Map<String, String>? fieldErrors = _fieldErrorsOrToast(monitor);
        if (fieldErrors != null) return fieldErrors;
        return const {};
      }
      await reload();
    }
    MagicRoute.to('/monitors/$id');
    return const {};
  }

  /// Resolves a failed [monitor] save into either its per-field validation
  /// errors or a generic toast.
  ///
  /// Returns the field errors (single message per field, keyed by the wire
  /// field name) when the failed save carried the Laravel 422 shape via
  /// [Monitor.validationErrors], so the caller hands them back to the form for
  /// inline display and stays put. Returns `null` for a non-field failure (a
  /// transport error / 500) after surfacing the generic save-failed toast and
  /// logging the cause, so the caller falls back to its empty-map contract.
  Map<String, String>? _fieldErrorsOrToast(Monitor monitor) {
    final Map<String, List<String>> errors = monitor.validationErrors;
    if (errors.isNotEmpty) {
      return {
        for (final MapEntry<String, List<String>> entry in errors.entries)
          entry.key: entry.value.first,
      };
    }

    Log.error('[MonitorController] save returned false with no field errors');
    Magic.error(
      trans('uptizm.monitors.toast_save_failed_title'),
      trans('uptizm.monitors.toast_save_failed_description'),
    );
    return null;
  }

  /// Whether the last [analyze] was refused by a plan wall rather than failing.
  ///
  /// The prompt is already shown by [analyze]; this lets the view skip its own
  /// "check that the URL is reachable" hint, which misdiagnoses a reachable URL
  /// the plan simply does not cover.
  bool _lastAnalyzeWasGated = false;

  /// Whether the last [analyze] hit a plan wall (see [_lastAnalyzeWasGated]).
  bool get lastAnalyzeWasGated => _lastAnalyzeWasGated;

  /// Runs the AI analyze probe for [url] via `POST /monitors/analyze` and
  /// returns the [MonitorAnalysis] prefill for the AI-flow review step, or
  /// `null` on failure.
  ///
  /// [region] optionally pins the probe location; omitted, the backend
  /// defaults to US East. A non-2xx response or a transport failure surfaces
  /// an error toast (reusing [create]'s `toast_save_failed_*` copy; there is
  /// no dedicated analyze-failed string yet) and logs via [Log.error], never
  /// a silent swallow.
  Future<MonitorAnalysis?> analyze(String url, {String? region}) async {
    _lastAnalyzeWasGated = false;
    try {
      final response = await Http.post(
        '/monitors/analyze',
        data: {'url': url, 'region': ?region},
      );
      if (!response.successful) {
        Log.error('[MonitorController.analyze] $url: ${response.errorMessage}');
        // A plan wall is not a failure the user can retry: surface it with the
        // upgrade action instead of an error toast that names the tier and
        // leaves them to find billing themselves. The flag lets the view skip
        // its own "check the URL is reachable" hint, which would be a wrong
        // diagnosis of a perfectly reachable URL.
        _lastAnalyzeWasGated = UpgradePrompt.showIfGated(response);
        if (_lastAnalyzeWasGated) return null;

        Magic.error(
          trans('uptizm.monitors.toast_save_failed_title'),
          response.errorMessage ??
              trans('uptizm.monitors.toast_save_failed_description'),
        );
        return null;
      }

      final Object? body = response.data;
      final Object? data = body is Map<String, dynamic> ? body['data'] : null;
      if (data is! Map<String, dynamic>) {
        Log.error('[MonitorController.analyze] $url: malformed response');
        Magic.error(
          trans('uptizm.monitors.toast_save_failed_title'),
          trans('uptizm.monitors.toast_save_failed_description'),
        );
        return null;
      }

      // A metered team just spent one setup: republish what the backend says is
      // left so the allowance the user sees counts down without another read.
      final Object? meta = body is Map<String, dynamic> ? body['meta'] : null;
      if (meta is Map<String, dynamic>) {
        EntitlementController.instance.noteAiAnalysisTrialsRemaining(
          (meta['ai_analysis_trials_remaining'] as num?)?.toInt(),
        );
      }

      return MonitorAnalysis.fromMap(data);
    } catch (error) {
      Log.error('[MonitorController.analyze] $url failed: $error');
      Magic.error(
        trans('uptizm.monitors.toast_save_failed_title'),
        trans('uptizm.monitors.toast_save_failed_description'),
      );
      return null;
    }
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
      final response = await Http.get('/monitors/$id/response-times?range=90d');
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
  /// data at all keeps a null status (rendered as a neutral no-data segment,
  /// never a fabricated green "up" day). A bucket falling outside the trailing
  /// 90-day window is ignored.
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
        // A day with no check keeps a null status: the bar renders it as a
        // neutral no-data segment instead of a fabricated green "up" day.
        status: days[i],
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
