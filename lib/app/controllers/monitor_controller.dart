import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'entitlement_controller.dart';
import '../models/monitor.dart';
import '../support/monitor_types.dart'
    show
        AnalyzeFailure,
        AnalyzeRunProgress,
        AnalyzeRunStatus,
        UptimeSegment,
        analyzeRunStatusFromWire,
        analyzeStepStateFromWire;
import '../enums/status_key.dart';
import '../enums/ai_confidence.dart';
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

  /// How much weight the review screen should give this suggestion, derived
  /// server-side from evidence quality (see the backend `AnalysisResult`
  /// docblock). Absent on a backend that predates the field, so
  /// [aiConfidenceFromWire] falls back to [AiConfidence.low], the most
  /// conservative reading rather than a fabricated "high".
  final AiConfidence confidence;

  /// What kind of service the target was read to be (e.g. `"json_api"`),
  /// or `"unknown"` when the classifier declined to guess.
  final String serviceClass;

  /// WHY [recommendedRegions] were suggested (a measured basis like
  /// `"geoip"` vs. an inferred one like `"default"`), a different question
  /// from what a lookup achieved. Mirrors the backend `RegionBasis` enum.
  final String regionBasis;

  /// One of the three uptime SLO targets the client offers, or `"none"`
  /// when a single probe does not justify committing to one.
  final String recommendedSloTarget;

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
    this.confidence = AiConfidence.low,
    this.serviceClass = 'unknown',
    this.regionBasis = 'default',
    this.recommendedSloTarget = 'none',
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
      confidence: aiConfidenceFromWire(map['confidence'] as String?),
      serviceClass: map['service_class'] as String? ?? 'unknown',
      regionBasis: map['region_basis'] as String? ?? 'default',
      recommendedSloTarget: map['recommended_slo_target'] as String? ?? 'none',
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

  /// The attributes only `GET /monitors/:id` measures: the trailing 24h uptime
  /// percentage plus the four 7-day and four 30-day reliability minutes, all
  /// computed from the raw check stream.
  ///
  /// Named once here because [_withMeasuredUptime] has to know exactly which
  /// fields a list row cannot speak for. Adding a show-only measurement to
  /// `MonitorController::show` on the backend means adding its key here, or the
  /// next list reload silently nulls it out again. The eight minute keys took
  /// the place of the two trailing-uptime percentage keys when the error budget
  /// moved off percentages; leaving the retired names here would have populated
  /// the reliability card once and emptied it on the next inventory refresh.
  static const List<String> _measuredUptimeAttributes = [
    'uptime_24h',
    'slo_down_minutes_7d',
    'slo_observed_minutes_7d',
    'slo_gap_minutes_7d',
    'slo_measured_minutes_7d',
    'slo_down_minutes_30d',
    'slo_observed_minutes_30d',
    'slo_gap_minutes_30d',
    'slo_measured_minutes_30d',
  ];

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
    _initialLoad = reload().whenComplete(() => _initialLoad = null);
  }

  /// The initial load of the monitor inventory while it is still in flight, so a second reader
  /// can JOIN it instead of issuing the same request again. Null before it
  /// starts and once it settles.
  Future<void>? _initialLoad;

  /// The read a newly mounted view should ask for.
  ///
  /// Joins the initial load while it is in flight and refetches once it has
  /// settled. [RefetchesOnMount] calls this rather than [reload] because on the
  /// mount that CREATES this controller `onInit` has already started the same
  /// request, and both firing sent it twice. Every later mount finds nothing in
  /// flight and refetches, which is the staleness the mixin exists to prevent.
  ///
  /// Deliberately NOT a change to [reload]: coalescing there would also join a
  /// refresh issued right after a mutation to a request that started before it,
  /// and hand back a snapshot without the row the operator just created.
  Future<void> ensureFresh() => _initialLoad ?? reload();

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
    for (final Timer timer in _resultWatchTimers.values) {
      timer.cancel();
    }
    _resultWatchTimers.clear();
    _resultWatchAttempts.clear();
    // The analyze poll outlives a single view (the run keeps advancing while the
    // operator is elsewhere), so a teardown has to cancel it here or a test's
    // `Magic.flush()` mid-run leaves a timer reading a network nobody is faking.
    // Settling too: a torn-down controller will never publish a terminal state,
    // so anyone still awaiting `analyze()` would wait for one forever.
    _stopAnalyzePoll();
    _settleAnalyze(null);
    _closed = true;
    super.onClose();
  }

  /// Whether this controller has been torn down.
  ///
  /// Read by [_watchForManualCheckResult], which sleeps between reads and must
  /// not touch a disposed controller (or hit the network) after a teardown,
  /// which in a test is a `Magic.flush()` mid-poll.
  bool _closed = false;

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

    _monitors = _withMeasuredUptime(fetched);
    refreshUI();
  }

  /// Carries the measured-uptime attributes from the inventory being replaced
  /// onto [fetched], so a list reload cannot erase what a show fetch measured.
  ///
  /// Required because the two endpoints hydrate into the SAME inventory while
  /// measuring different things. `GET /monitors/:id` computes the trailing 24h /
  /// 7d / 30d uptime from the raw check stream; `GET /monitors` deliberately does
  /// not (one aggregate query per row is an N+1 across the whole inventory), so a
  /// list row carries those fields as null. [reload] replaces the collection
  /// wholesale, so without this the null wins and the detail screen's reliability
  /// section reads "not enough data yet" for a monitor that has been checked for
  /// days. The list is fetched far more often than the show, so the erasure wins
  /// essentially always, and it looked like a backend bug rather than a merge one.
  ///
  /// Fills a null from a non-null and never the reverse: the list stays
  /// authoritative for every field it actually returns, and a monitor whose
  /// detail page was never opened keeps its honest no-data state instead of
  /// gaining a figure nothing measured.
  List<Monitor> _withMeasuredUptime(List<Monitor> fetched) {
    if (_monitors.isEmpty) return fetched;

    // 1. Index what is already known, so the carry-forward stays O(n) rather
    //    than scanning the inventory once per fetched row.
    final Map<String, Monitor> known = {
      for (final Monitor monitor in _monitors) monitor.id: monitor,
    };

    // 2. Rescue each measured field the incoming row does not carry.
    for (final Monitor monitor in fetched) {
      final Monitor? prior = known[monitor.id];
      if (prior == null) continue;

      for (final String key in _measuredUptimeAttributes) {
        final Object? measured = prior.getAttribute(key);

        if (measured != null && monitor.getAttribute(key) == null) {
          monitor.setAttribute(key, measured);
        }
      }
    }

    return fetched;
  }

  /// Drops the previous session's monitor inventory, publishes the cleared
  /// state, then refetches for the identity that is now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): [reload] treats
  /// an empty fetch as "keep the last-known-good inventory", which across an
  /// identity change would leave the previous team's monitors listed (and
  /// resolvable through [monitorById]) even when the refetch fails outright.
  /// [_lastAnalyzeWasGated] belongs to the previous session's plan wall, so it
  /// is cleared with the rest, and so does any analyze run in flight: a run is
  /// authorised on the team that started it, so the incoming identity's poll
  /// would only ever read the masked 404 that means "gone", and the view would
  /// show the new team a failure for work it never asked for.
  @override
  Future<void> resetForSession() async {
    _monitors = [];
    _lastAnalyzeWasGated = false;
    abandonAnalyzeRun();
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

  /// Applies a `check.recorded` broadcast to the cached inventory in place.
  ///
  /// The reading IS the update, so this patches the three denormalised fields
  /// the frame carries and fetches nothing. That is the difference from
  /// [refreshOne]: a monitor answering normally every 60 seconds must not cost a
  /// `GET /monitors/{id}` per region per cycle just to move a latency number.
  ///
  /// Three guards, each for a case that happens in production:
  ///
  ///  1. **Not in the cache: no-op.** The channel is team-wide, so a reading
  ///     arrives for every monitor the team owns, including ones this client has
  ///     never listed. Inserting a half-populated [Monitor] built from a reading
  ///     would put a row with no url and no interval into the inventory.
  ///  2. **Older than what is held: no-op.** Regions land in whatever order the
  ///     socket delivers them, and each frame carries the denorm state as of ITS
  ///     write, so applying a late frame would wind `last_checked_at` backwards
  ///     and re-trigger the detail view's refetch on stale data.
  ///  3. **Unparseable timestamp: no-op.** Without a comparable instant there is
  ///     no way to honour guard 2, and a reading with no time is not one the UI
  ///     can place anyway.
  ///
  /// The timestamp is stored as the RAW ISO-8601 string from the wire, never
  /// round-tripped through [DateTime]: magic's `setAttribute` formats a DateTime
  /// as `yyyy-MM-ddTHH:mm:ss` and drops the offset, which would silently shift
  /// every reading by the device's UTC offset.
  ///
  /// [refreshUI] is what makes the monitor DETAIL page live as well as the list:
  /// its `_fetchedAgainstCheckedAt` guard refetches the check-derived lists on a
  /// notify whose `last_checked_at` moved, which is exactly what just happened.
  void noteCheckRecorded(Map<String, dynamic> payload) {
    final String? id = payload['monitor_id'] as String?;
    final String? checkedAt = payload['last_checked_at'] as String?;
    if (id == null || checkedAt == null) return;

    final int index = _monitors.indexWhere((Monitor m) => m.id == id);
    if (index == -1) return;

    final Carbon? incoming = _parseInstant(checkedAt);
    if (incoming == null) return;

    final Monitor cached = _monitors[index];
    final Carbon? held = cached.lastCheckedAt;
    if (held != null && incoming.isBefore(held)) return;

    cached
      ..setAttribute('last_status', payload['last_status'])
      ..setAttribute('last_checked_at', checkedAt)
      ..setAttribute('last_response_ms', payload['last_response_ms']);
    refreshUI();
  }

  /// Parses a wire timestamp, returning null rather than throwing on a value the
  /// device cannot read. `Carbon.parse` throws on malformed input, and a bad
  /// frame must not take down the socket listener that delivered it.
  Carbon? _parseInstant(String raw) {
    try {
      return Carbon.parse(raw);
    } catch (_) {
      return null;
    }
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

  /// How many times a queued manual check is re-read before giving up, and how
  /// long between reads. Six reads two seconds apart covers a probe that has
  /// been measured at well under a second, with room for a busy worker, and
  /// stops rather than polling forever if the check never lands.
  static const int _manualCheckPollAttempts = 6;

  /// The gap between those reads.
  static const Duration _manualCheckPollInterval = Duration(seconds: 2);

  /// The pending result-watch [Timer] per monitor id, so a teardown can cancel
  /// it. Kept in the same shape as [_cooldownTimers] and cancelled in the same
  /// place, because a bare `Future.delayed` chain cannot be cancelled and the
  /// widget-test binding rightly fails a test that disposes the tree with a
  /// timer still pending.
  final Map<String, Timer> _resultWatchTimers = {};

  /// How many reads each monitor's watch has already spent.
  final Map<String, int> _resultWatchAttempts = {};

  /// Re-reads monitor [id] until its `last_checked_at` moves past [before], or
  /// the poll budget runs out.
  ///
  /// The immediate `refreshOne` in [runCheckNow] cannot see the result: the
  /// probe is queued and lands a second or two later, so a refresh at request
  /// time answers about the state BEFORE the check. Each read notifies
  /// listeners, which is what lets the detail screen swap its chart and table in
  /// (see `MonitorDetailView._onMonitorChanged`).
  ///
  /// Stops on the first read that shows movement, so the common case costs one
  /// extra request.
  void _watchForManualCheckResult(String id, DateTime? before) {
    _stopResultWatch(id);
    _resultWatchAttempts[id] = 0;
    _scheduleResultWatch(id, before);
  }

  /// Queues the next read in [id]'s watch.
  void _scheduleResultWatch(String id, DateTime? before) {
    _resultWatchTimers[id] = Timer(_manualCheckPollInterval, () async {
      final int attempt = (_resultWatchAttempts[id] ?? 0) + 1;
      _resultWatchAttempts[id] = attempt;

      await refreshOne(id);

      if (_closed) return;

      final DateTime? landed = _cachedById(id)?.lastCheckedAt?.toDateTime;
      if (landed != null && landed != before) {
        _stopResultWatch(id);

        return;
      }

      if (attempt >= _manualCheckPollAttempts) {
        _stopResultWatch(id);

        return;
      }

      _scheduleResultWatch(id, before);
    });
  }

  /// Cancels and forgets [id]'s watch.
  void _stopResultWatch(String id) {
    _resultWatchTimers.remove(id)?.cancel();
    _resultWatchAttempts.remove(id);
  }

  /// Runs an out-of-schedule check for the monitor [id] via
  /// `POST /monitors/:id/test`, then refreshes that monitor.
  ///
  /// The endpoint answers `202 Accepted` with no body: the probe is queued for
  /// every configured region and runs at the edge, so the result does not exist
  /// yet when this returns. The toast therefore says the check was QUEUED
  /// rather than claiming a result, and [refreshOne] picks up whatever has
  /// landed by the time it resolves.
  ///
  /// Anything later is picked up by [_watchForManualCheckResult], NOT by the
  /// realtime channel. This docblock used to defer to that channel, and it was
  /// wrong: `MonitorStatusChanged` fires on a status TRANSITION, so a manual
  /// check confirming an already-up monitor is still up broadcasts nothing. The
  /// operator pressed a button, waited, and watched the screen not move.
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
      final DateTime? before = monitor.lastCheckedAt?.toDateTime;
      await refreshOne(id);
      _watchForManualCheckResult(id, before);
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
  /// A successful create lands on the NEW MONITOR'S DETAIL route, not the list.
  /// `store()` sets `next_check_at` to now and queues the probes, so the detail
  /// screen is where the thing the operator just asked for actually happens: it
  /// shows "Waiting for the first check" and then swaps the real chart and table
  /// in when the result lands. Dropping them on the list instead made them hunt
  /// for the row they had just created and told them nothing about the check.
  /// Falls back to the list when the save returned no id (and for the
  /// navigation-only call, which has no monitor to open).
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

      final String id = monitor.id;
      MagicRoute.to(id.isEmpty ? '/monitors' : '/monitors/$id');

      return const {};
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

      // An omitted `auth_config` has to stay omitted ON THE WIRE, and filling
      // alone does not achieve that. The monitor was re-fetched above, so it
      // already carries the REDACTED map the API publishes (`type`, `username`
      // and `header`, never a secret: MonitorResource::redactAuthConfig), and
      // `save()` PUTs the whole `toArray()`. So a rename that deliberately sent
      // no credential would still ship `{type: basic, username: svc}`, and the
      // backend's `required_if:auth_config.type,basic` on the password answers
      // 422 for an edit the operator never made. Hiding the attribute is what
      // makes the form's "the operator did not touch the secret" decision
      // survive the round trip.
      if (!fields.containsKey('auth_config')) {
        monitor.makeHidden(['auth_config']);
      }

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

  // ---------------------------------------------------------------------------
  // The asynchronous AI analyze run
  //
  // THE PUBLIC CONTRACT, PINNED HERE ON PURPOSE, because the create view and
  // the realtime progress listener both consume it and neither may guess:
  //
  // 1. [analyze] STILL RESOLVES ONLY WHEN THE RUN IS OVER, and still with the
  //    [MonitorAnalysis] prefill or null. The 202 is NOT what it returns on:
  //    the POST accepting a run is now the first of many round trips, and the
  //    future this method hands back is settled by whichever read first sees a
  //    terminal status. So one `await controller.analyze(...)` still spans one
  //    operator action, which is why the create view's own call site keeps
  //    compiling and keeps meaning what it meant.
  //    WHAT DID CHANGE, and it is the part a caller must not miss: that await
  //    is now up to four minutes long and is NO LONGER the whole story. The
  //    step rows have to render from [analyzeProgress] WHILE it is pending, and
  //    a null result now has four distinct causes (see [AnalyzeFailure]) that
  //    the operator has to be told apart, instead of the single "couldn't
  //    analyze that URL" the synchronous call had.
  // 2. THE PUBLISHED STATE IS THE PRIMARY SURFACE; the returned future is a
  //    convenience over it. [analyzeProgress] and [analyzeResult] are
  //    republished with a `refreshUI()` on every advance, exactly like every
  //    other surface this controller backs, so a view that never awaited
  //    anything (or one that came back to a run already in flight) renders the
  //    same state from the same getters.
  // 3. THE POLL IS THE SOURCE OF TRUTH; a broadcast only advances the state
  //    early. [noteAnalyzeProgress] is the method the realtime handler calls
  //    with one decoded `analyze.progress` payload.
  // ---------------------------------------------------------------------------

  /// How long between poll reads of the run.
  ///
  /// 2500ms is a compromise the socket does not remove the need for: the
  /// progress event is `ShouldRescue`, so a Redis push failure is swallowed BY
  /// DESIGN; Reverb has no replay, so a frame that arrives while the tab is
  /// backgrounded (or between a reload and the resubscribe) is simply gone; and
  /// the result itself never travels in a broadcast at all (Reverb caps an
  /// inbound request at 10,000 bytes). So the poll is the mechanism and the
  /// socket is the accelerator, never the reverse.
  static const Duration _analyzePollInterval = Duration(milliseconds: 2500);

  /// How many reads one run gets before this client calls it dead.
  ///
  /// 96 reads at 2500ms is 240 seconds, sized off the server's own ceiling
  /// rather than picked: the analyze supervisor kills a worker at 170s, and the
  /// supervisor runs two processes, so a run can also sit in the queue behind
  /// another team's analyze for a while before a worker picks it up. Past that
  /// the run is not slow, it is gone (a worker killed without its `failed()`
  /// hook running, or a job that never reached a worker at all), and polling on
  /// is the eternal spinner this whole design exists to make impossible.
  static const int _analyzePollAttempts = 96;

  /// The run [analyze] accepted, or `null` when no run is being tracked.
  ///
  /// The authorisation anchor for a broadcast tick: the progress channel is
  /// team-wide (`private-teams.{id}`), so a teammate's ticks arrive at this
  /// client too and only the ones naming this run may touch the state.
  String? _analyzeRunId;

  /// The tracked run's progress, or `null` when no run has been started in this
  /// session.
  AnalyzeRunProgress? _analyzeProgress;

  /// The completed run's analysis, or `null` until one completes.
  MonitorAnalysis? _analyzeResult;

  /// The highest broadcast `sequence` already folded in.
  ///
  /// Production Horizon runs ten processes on the queue the broadcast jobs land
  /// on and Laravel guarantees ordering only for SQS FIFO, so ticks CAN arrive
  /// out of order. Dropping anything at or below what has been seen also
  /// collapses the two byte-identical failure ticks the job deliberately emits
  /// (both land on sequence 999) into one.
  int _analyzeSequence = 0;

  /// The pending poll read, kept in a field so a teardown can cancel it. Same
  /// shape and same reason as [_resultWatchTimers].
  Timer? _analyzePollTimer;

  /// Settles the future [analyze] handed its caller, once the run reaches a
  /// terminal state.
  ///
  /// A [Completer] and not an awaited loop, because the thing that ends a run
  /// is not the thing that started it: a terminal status can arrive on the
  /// poll, on the refetch a broadcast triggered, or on [abandonAnalyzeRun]
  /// when the operator walks away. EVERY exit has to settle this exactly once,
  /// or the caller's await never returns and the form spins on a run that is
  /// already over, which is the failure mode this whole design is against.
  Completer<MonitorAnalysis?>? _analyzeCompleter;

  /// How many reads this run's poll has already spent.
  int _analyzePollsSpent = 0;

  /// The tracked run's progress: the step ordinals that have reported, their
  /// terminal states, and the run's own status. `null` until a run is accepted.
  ///
  /// What the create view renders its step rows from. Read
  /// [AnalyzeRunProgress.inFlightStep] for the row that is working; the states
  /// are terminal only, so no row ever claims to be working on its own behalf.
  AnalyzeRunProgress? get analyzeProgress => _analyzeProgress;

  /// The completed run's prefill for the AI review step, or `null` while the
  /// run is in flight, failed, or was never started.
  ///
  /// Set exactly once per run, by the poll read (or the refetch a terminal
  /// broadcast triggers) that first sees `completed`.
  MonitorAnalysis? get analyzeResult => _analyzeResult;

  /// Runs an AI analyze for [url] and resolves the [MonitorAnalysis] prefill
  /// for the review step, or `null` when no analysis was produced.
  ///
  /// `POST /monitors/analyze` now answers **202**: it accepts a run, hands back
  /// a run id, and a worker does the model calls. So this method accepts the
  /// run, takes ownership of it, and resolves only once
  /// [fetchAnalyzeRun] first reads a terminal status (or the run is abandoned).
  /// The `await` a caller writes therefore still spans exactly one operator
  /// action, and still yields either the prefill or nothing.
  ///
  /// **A caller that only awaits this is not enough any more.** The wait is now
  /// up to four minutes and the operator has to see it move, so render
  /// [analyzeProgress] while the future is pending; and a `null` result has
  /// four causes the operator must be told apart, which [AnalyzeFailure] on
  /// [analyzeProgress] names (a plan wall or a refused request leaves
  /// [analyzeProgress] null instead, having already surfaced its own message).
  ///
  /// [region] optionally pins the probe location; omitted, the backend
  /// defaults to US East. A non-2xx response or a transport failure surfaces
  /// an error toast (reusing [create]'s `toast_save_failed_*` copy; there is
  /// no dedicated analyze-failed string yet) and logs via [Log.error], never
  /// a silent swallow. A 409's `message` is rendered VERBATIM (via
  /// [MagicResponse.errorMessage]), because it names the state the operator is
  /// in rather than the mechanism that refused them.
  ///
  /// [authConfig] is the same wire map the create form assembles
  /// (`MonitorCredential.toWireMap`), so a protected endpoint can be analyzed
  /// at all: the backend probes WITH it and redacts the credential out of the
  /// probe result before anything derives a prompt from it. Omitted for a
  /// public target, which is the ordinary case.
  Future<MonitorAnalysis?> analyze(
    String url, {
    String? region,
    Map<String, dynamic>? authConfig,
  }) async {
    _lastAnalyzeWasGated = false;
    // A new run starts from nothing: leaving the previous run's progress or
    // result published would let the view render a stale success (or a stale
    // failure) as this run's own.
    abandonAnalyzeRun();

    try {
      final response = await Http.post(
        '/monitors/analyze',
        data: {'url': url, 'region': ?region, 'auth_config': ?authConfig},
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

      final AnalyzeRunProgress accepted = AnalyzeRunProgress.fromMap(data);
      if (accepted.runId.isEmpty) {
        // Without a run id there is nothing to poll and nothing a tick could
        // name, so this is a malformed 202 rather than a run: refuse it here
        // instead of publishing a run the client can never resolve.
        Log.error('[MonitorController.analyze] $url: 202 carried no run_id');
        Magic.error(
          trans('uptizm.monitors.toast_save_failed_title'),
          trans('uptizm.monitors.toast_save_failed_description'),
        );
        return null;
      }

      _analyzeRunId = accepted.runId;
      // Two accepts in flight at once (a double press lands both POSTs before
      // either answers) must not orphan the first caller's future: taking the
      // slot settles whoever held it, so nobody is left awaiting a run this
      // controller has stopped tracking. `abandonAnalyzeRun()` above cannot
      // cover it, because the other call creates its completer after that ran.
      _settleAnalyze(null);
      final Completer<MonitorAnalysis?> completer =
          Completer<MonitorAnalysis?>();
      _analyzeCompleter = completer;

      // The local is not style, it is the fix for a crash on the terminal path.
      // [_publishAnalyzeProgress] settles the completer when the run is already
      // terminal, and settling nulls [_analyzeCompleter], so reading the field
      // back afterwards threw a null-check error that the catch below then
      // reported to the operator as a generic "couldn't analyze that URL". The
      // comment that used to sit here claimed this case was guarded; it was the
      // case that broke.
      _publishAnalyzeProgress(accepted);

      // The accept is `queued` in practice; the guard is for the run that
      // somehow finished (or failed) before the response was decoded, which
      // must not arm a poll for a run nothing will ever report on again.
      if (!accepted.status.isTerminal) _scheduleAnalyzePoll();

      // Awaited rather than returned bare, so the try block actually covers the
      // value it hands back (`unawaited_return_in_try_block`). Behaviour is
      // unchanged: [_settleAnalyze] is the only settler and it always calls
      // `complete()`, never `completeError()`, so this await can never throw and
      // the catch below cannot start swallowing a completion path it did not
      // handle before.
      return await completer.future;
    } catch (error) {
      Log.error('[MonitorController.analyze] $url failed: $error');
      Magic.error(
        trans('uptizm.monitors.toast_save_failed_title'),
        trans('uptizm.monitors.toast_save_failed_description'),
      );
      return null;
    }
  }

  /// Reads run [runId] from `GET /monitors/analyze/{run}` and publishes it as
  /// [analyzeProgress].
  ///
  /// The SOURCE OF TRUTH for a run's state, called every
  /// [_analyzePollInterval] and again immediately whenever a broadcast reports
  /// a terminal status (the result never travels in a broadcast, so a terminal
  /// tick is a prompt to read, not an answer).
  ///
  /// **A 404 IS A REAL FAILURE, NOT "NOT YET".** The run lives in a cache entry
  /// with a 900-second TTL, in a Redis running `volatile-lru` at a 512 MB
  /// ceiling, so the entry can be evicted or expire while the operator watches.
  /// It never comes back. Reading a 404 as "still running" is precisely the
  /// eternal spinner this design exists to make impossible, so it publishes
  /// [AnalyzeFailure.lost] and stops the poll; the view's copy tells the
  /// operator to run it again.
  ///
  /// Every OTHER unsuccessful read (a 500, a transport blip) returns `null`
  /// WITHOUT touching the state: those are transient and the next poll asks
  /// again, bounded by [_analyzePollAttempts].
  ///
  /// Returns the progress it published, or `null` when it published nothing:
  /// a read that did not resolve, or a read whose run is no longer the tracked
  /// one (the operator started another analyze while this read was in flight,
  /// and a stale run's answer must not overwrite the current run's progress).
  Future<AnalyzeRunProgress?> fetchAnalyzeRun(String runId) async {
    try {
      final response = await Http.get('/monitors/analyze/$runId');

      if (response.notFound) {
        return _publishLostRun(runId);
      }

      if (!response.successful) {
        Log.error(
          '[MonitorController.fetchAnalyzeRun] $runId: '
          '${response.statusCode} ${response.errorMessage}',
        );
        return null;
      }

      final Object? body = response.data;
      final Object? data = body is Map<String, dynamic> ? body['data'] : null;
      if (data is! Map<String, dynamic>) {
        Log.error(
          '[MonitorController.fetchAnalyzeRun] $runId: malformed response',
        );
        return null;
      }

      if (_analyzeRunId != runId) {
        Log.warning(
          '[MonitorController.fetchAnalyzeRun] $runId: no longer the tracked '
          'run, discarding',
        );
        return null;
      }

      final AnalyzeRunProgress progress = AnalyzeRunProgress.fromMap(data);
      if (progress.status != AnalyzeRunStatus.completed) {
        _publishAnalyzeProgress(progress);

        return progress;
      }

      return _publishCompletedRun(progress, data['result']);
    } catch (error) {
      Log.error('[MonitorController.fetchAnalyzeRun] $runId failed: $error');

      return null;
    }
  }

  /// Folds one decoded `analyze.progress` broadcast payload into
  /// [analyzeProgress], so the step rows advance without waiting for the next
  /// poll.
  ///
  /// **This is the method the realtime `analyze.progress` handler calls**, with
  /// the event's already-decoded `data` map (`run_id`, `sequence`, `step`,
  /// `state`, `status`). It reads no other key and it never fetches the
  /// monitor list.
  ///
  /// It is also the ONLY authorisation point for a tick: the channel is
  /// team-wide, so a teammate's run reports here too, and a payload whose
  /// `run_id` is not the tracked run is dropped. Ticks at or below the highest
  /// `sequence` already seen are dropped too, which is what keeps an
  /// out-of-order tick from winding the rows backwards.
  ///
  /// **A BROADCAST NEVER CONCLUDES A RUN.** A tick reporting `completed` or
  /// `failed` is published as `analyzing` and triggers an immediate
  /// [fetchAnalyzeRun]; the read is what writes the terminal status. Two
  /// reasons, and the first is a bug this cost once already: the result and the
  /// failure reason exist ONLY over the authorised GET (the payload is bounded
  /// by Reverb's 10,000-byte inbound ceiling), so a tick that concluded the run
  /// would hand the caller a completed run with no analysis in it. And ending
  /// the run also cancels the poll, so a tick that concluded on a read that
  /// then failed transiently would have cancelled the one thing left that could
  /// recover.
  ///
  /// The visible cost is one round trip of a row that has already reported
  /// `failed` sitting next to a row rendered as in flight. That is the accepted
  /// price of having exactly one place that ends a run.
  void noteAnalyzeProgress(Map<String, dynamic> payload) {
    final String? runId = _analyzeRunId;
    final AnalyzeRunProgress? current = _analyzeProgress;
    if (runId == null || current == null) return;
    if (payload['run_id'] != runId) return;

    final int sequence = (payload['sequence'] as num?)?.toInt() ?? 0;
    if (sequence <= _analyzeSequence) return;
    _analyzeSequence = sequence;

    final AnalyzeRunStatus reported = analyzeRunStatusFromWire(
      payload['status'] as String?,
    );
    _publishAnalyzeProgress(
      current.withTick(
        step: (payload['step'] as num?)?.toInt() ?? current.step,
        state: analyzeStepStateFromWire(payload['state'] as String?),
        status: reported.isTerminal ? AnalyzeRunStatus.analyzing : reported,
      ),
    );

    if (reported.isTerminal) fetchAnalyzeRun(runId);
  }

  /// Stops tracking the current analyze run and drops everything published
  /// about it.
  ///
  /// Called by [analyze] before it starts a new run, by [resetForSession] on an
  /// identity change (a run belongs to one team, so the next team's poll would
  /// only ever get a masked 404), and by the create view when it leaves the AI
  /// flow, so an abandoned run stops costing a read every 2500ms.
  void abandonAnalyzeRun() {
    _stopAnalyzePoll();
    _analyzeRunId = null;
    _analyzeProgress = null;
    _analyzeResult = null;
    _analyzeSequence = 0;
    // Abandoning is an exit like any other, so it settles the caller's future
    // too: whoever is still awaiting the previous run gets `null` now rather
    // than never, and the reason they get it is on screen already (they either
    // started another run or left the flow).
    _settleAnalyze(null);
  }

  /// Publishes [progress], and on a terminal status stops the poll and settles
  /// the future [analyze] handed its caller.
  ///
  /// The single place a run ends, so that every path into a terminal
  /// state (the poll, a broadcast-triggered refetch, a lost run, a spent poll
  /// budget) settles the caller exactly once and by construction.
  void _publishAnalyzeProgress(AnalyzeRunProgress progress) {
    _analyzeProgress = progress;
    if (progress.status.isTerminal) {
      _stopAnalyzePoll();
      _settleAnalyze(
        progress.status == AnalyzeRunStatus.completed ? _analyzeResult : null,
      );
    }
    refreshUI();
  }

  /// Completes the pending [analyze] future with [result], at most once.
  ///
  /// Guarded rather than assumed: the terminal state is written by a poll read
  /// and again by the refetch a terminal broadcast triggers, and completing a
  /// [Completer] twice throws.
  void _settleAnalyze(MonitorAnalysis? result) {
    final Completer<MonitorAnalysis?>? completer = _analyzeCompleter;
    if (completer == null || completer.isCompleted) return;

    _analyzeCompleter = null;
    completer.complete(result);
  }

  /// Publishes the terminal [AnalyzeFailure.lost] state for a run whose cache
  /// entry is gone, or `null` when that run is no longer the tracked one.
  AnalyzeRunProgress? _publishLostRun(String runId) {
    final AnalyzeRunProgress? current = _analyzeProgress;
    if (_analyzeRunId != runId || current == null) return null;

    Log.error(
      '[MonitorController.fetchAnalyzeRun] $runId: the run is gone (evicted '
      'or expired); reporting it failed rather than still running',
    );
    final AnalyzeRunProgress lost = current.asFailure(AnalyzeFailure.lost);
    _publishAnalyzeProgress(lost);

    return lost;
  }

  /// Publishes a completed run: decodes [result] into [analyzeResult] and
  /// republishes the team's remaining metered setups from its `meta`.
  ///
  /// A `completed` run whose `result` cannot be decoded is published as a
  /// FAILURE rather than as a completion: there is no prefill for the review
  /// step to render, so calling it complete would leave the operator on a
  /// finished run with nothing in it.
  AnalyzeRunProgress _publishCompletedRun(
    AnalyzeRunProgress progress,
    Object? result,
  ) {
    final Object? data = result is Map<String, dynamic> ? result['data'] : null;
    if (data is! Map<String, dynamic>) {
      Log.error(
        '[MonitorController.fetchAnalyzeRun] ${progress.runId}: completed with '
        'no decodable result',
      );
      final AnalyzeRunProgress failed = progress.asFailure(
        AnalyzeFailure.errored,
      );
      _publishAnalyzeProgress(failed);

      return failed;
    }

    // A metered team just spent one setup, and the 202 could not say so: the
    // trial is consumed by the worker, long after the request that asked for it
    // returned. So the number rides the completion payload's `meta` instead,
    // and republishing it here is what makes the allowance the user sees count
    // down without a second entitlement read.
    final Object? meta = result is Map<String, dynamic> ? result['meta'] : null;
    if (meta is Map<String, dynamic>) {
      EntitlementController.instance.noteAiAnalysisTrialsRemaining(
        (meta['ai_analysis_trials_remaining'] as num?)?.toInt(),
      );
    }

    _analyzeResult = MonitorAnalysis.fromMap(data);
    _publishAnalyzeProgress(progress);

    return progress;
  }

  /// Queues the next poll read of the tracked run.
  ///
  /// A [Timer] rather than an awaited `Future.delayed` chain for the same
  /// reason as [_scheduleResultWatch]: a delayed chain cannot be cancelled, and
  /// a widget test that disposes the tree with one pending rightly fails.
  void _scheduleAnalyzePoll() {
    _analyzePollTimer?.cancel();
    _analyzePollTimer = Timer(_analyzePollInterval, () async {
      final String? runId = _analyzeRunId;
      if (_closed || runId == null) return;

      _analyzePollsSpent++;
      if (_analyzePollsSpent > _analyzePollAttempts) {
        _publishTimedOutRun(runId);

        return;
      }

      await fetchAnalyzeRun(runId);
      if (_closed) return;

      // Re-read both, because the read above took time: the operator may have
      // started another run, and a terminal status has already stopped the poll.
      final bool stillRunning =
          _analyzeRunId == runId &&
          !(_analyzeProgress?.status.isTerminal ?? true);
      if (stillRunning) _scheduleAnalyzePoll();
    });
  }

  /// Publishes the terminal [AnalyzeFailure.timedOut] state for a run that
  /// spent its whole poll budget without reaching one of its own.
  void _publishTimedOutRun(String runId) {
    final AnalyzeRunProgress? current = _analyzeProgress;
    if (_analyzeRunId != runId || current == null) return;

    Log.error(
      '[MonitorController] analyze run $runId never reached a terminal state '
      'in $_analyzePollAttempts reads; reporting it failed',
    );
    _publishAnalyzeProgress(current.asFailure(AnalyzeFailure.timedOut));
  }

  /// Cancels and forgets the pending poll read.
  void _stopAnalyzePoll() {
    _analyzePollTimer?.cancel();
    _analyzePollTimer = null;
    _analyzePollsSpent = 0;
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
