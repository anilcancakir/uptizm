import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/on_call_schedule.dart';
import '../support/team_types.dart'
    show
        OnCallOverrideWindow,
        OnCallResponder,
        OnCallRotationSlot,
        TeamResponder;

/// The render phase of the on-call read, so the view can tell "this team has no
/// schedule" apart from "the read failed" apart from "still loading".
///
/// The distinction is load-bearing rather than cosmetic: on a paging screen an
/// [error] rendered as [empty] reads as "nobody is on call", which is the one
/// claim this screen must never make without the server having said it.
enum OnCallPhase {
  /// The first read has not resolved yet.
  loading,

  /// A schedule is loaded; [OnCallController.rotation],
  /// [OnCallController.overrides] and [OnCallController.currentResponder] are
  /// the server's answer.
  ready,

  /// The team genuinely has no on-call schedule yet.
  empty,

  /// The read failed; nothing is published, because a partially known ring
  /// cannot be rendered honestly.
  error,
}

/// Controller backing [OnCallScheduleView]: the team's on-call schedule, its
/// rotation ring, its overrides, and who the backend says is holding the pager.
///
/// ## Everything rendered comes from the API
///
/// [reload] issues exactly two reads:
///
/// 1. `GET /on-call/schedules`, whose rows now carry their `rotations` and
///    `overrides` eager-loaded (`OnCallController::index()`), so the ring
///    arrives with the list and no per-schedule follow-up is needed.
/// 2. `GET /on-call/current?schedule_id=...`, whose `data.user` is the
///    responder the backend `RotationResolver` resolved (an active override
///    beats the ring), or `null` for "nobody is on call".
///
/// There is deliberately NO client-side rotation math: which responder holds
/// the pager right now is the server's answer, and [activeOverride] only picks
/// the label ("override until ..." versus "shift") for the responder the server
/// already named.
///
/// ## Why the reads are raw `Http.get`, not `OnCallSchedule.all()`
///
/// The ORM's `all()` swallows a transport failure into an empty list, which
/// makes "this team has no schedule" indistinguishable from "the read failed".
/// This screen must render those two cases differently (see [OnCallPhase]), so
/// the read path keeps the raw response in hand. The [OnCallSchedule] model
/// still owns hydration ([OnCallSchedule.fromMap]) and the create write
/// ([createSchedule] persists through its ORM `save()`).
///
/// ## Failure degrades to nothing, not to stale rows
///
/// Unlike `MonitorController`/`StatusPageController`, a failed reload does NOT
/// keep the last-known-good ring: it clears and publishes [OnCallPhase.error]
/// with a retry. A stale rotation here is a person who may no longer be
/// reachable, and every write re-reads through the same path, so the screen is
/// either the API's current answer or an honest error.
///
/// ## Writes
///
/// [createSchedule], [addToRotation], [removeFromRotation], [reorderRotation],
/// [addOverride] and [removeOverride] each write, then `await [reload]`: the
/// mutation responses cannot be applied blind because a rotation or override
/// change moves the resolved responder, which only `GET /on-call/current`
/// knows. Each returns `true`/`false` and surfaces its own toast; none ever
/// throws to the caller.
class OnCallController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor, registering the controller on first access.
  static OnCallController get instance => Magic.findOrPut(OnCallController.new);

  /// The current render phase of the read.
  OnCallPhase _phase = OnCallPhase.loading;

  /// The team's on-call schedule, or `null` outside [OnCallPhase.ready].
  OnCallSchedule? _schedule;

  /// The ring, ordered by `position`. Empty outside [OnCallPhase.ready] and for
  /// a schedule whose ring genuinely has no responder.
  List<OnCallRotationSlot> _rotation = const [];

  /// The schedule's temporary responder swaps.
  List<OnCallOverrideWindow> _overrides = const [];

  /// Who the backend resolved as on call, or `null` when it resolved nobody.
  OnCallResponder? _currentResponder;

  /// The current render phase of the read.
  OnCallPhase get phase => _phase;

  /// The loaded schedule, or `null` when none is loaded.
  OnCallSchedule? get schedule => _schedule;

  /// The loaded schedule's backend id, or `null` when none is loaded.
  String? get scheduleId {
    final String? id = _schedule?.id;
    return (id == null || id.isEmpty) ? null : id;
  }

  /// The rotation ring, ordered by `position`.
  List<OnCallRotationSlot> get rotation => _rotation;

  /// The schedule's temporary responder swaps, newest window first.
  List<OnCallOverrideWindow> get overrides => _overrides;

  /// The responder the backend says is on call, or `null` when it says nobody
  /// is (an empty ring with no covering override).
  OnCallResponder? get currentResponder => _currentResponder;

  /// The override that explains why [currentResponder] holds the pager, or
  /// `null` when the ring (or nothing at all) does.
  ///
  /// Label resolution only: the responder itself is always the server's.
  OnCallOverrideWindow? get activeOverride {
    final OnCallResponder? responder = _currentResponder;
    if (responder == null) return null;

    final DateTime now = DateTime.now();
    for (final OnCallOverrideWindow window in _overrides) {
      if (window.userId == responder.id && window.covers(now)) return window;
    }
    return null;
  }

  /// The ring slot held by [userId], or `null` when that user is not in the
  /// loaded ring.
  OnCallRotationSlot? slotFor(String userId) {
    for (final OnCallRotationSlot slot in _rotation) {
      if (slot.userId == userId) return slot;
    }
    return null;
  }

  /// The backend rotation row id for [userId], or `null` when that user is not
  /// in the loaded ring.
  String? rotationIdFor(String userId) => slotFor(userId)?.id;

  /// Bootstraps the schedule the first time this controller backs a view.
  @override
  void onInit() {
    super.onInit();
    _initialLoad = reload().whenComplete(() => _initialLoad = null);
  }

  /// The initial load of the on-call schedule while it is still in flight, so a second reader
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

  // ---------------------------------------------------------------------------
  // Reads
  // ---------------------------------------------------------------------------

  /// Re-reads the whole screen from the API: the team's schedule with its
  /// eager-loaded ring and overrides, then the server-resolved responder.
  ///
  /// Publishes exactly one of [OnCallPhase.ready] (a schedule was loaded),
  /// [OnCallPhase.empty] (the team has none) or [OnCallPhase.error] (any
  /// non-2xx, malformed payload, or transport failure, including an
  /// unregistered `network` service in a bare test host). Never throws.
  Future<void> reload() async {
    try {
      // 1. The schedule list, rings and overrides included.
      final MagicResponse listed = await Http.get('/on-call/schedules');
      if (!listed.successful) {
        Log.error('[OnCallController.reload] schedules: ${listed.errorMessage}');
        _publishFailure();
        return;
      }

      final List<Map<String, dynamic>> rows = _dataRows(listed);
      if (rows.isEmpty) {
        _publishEmpty();
        return;
      }

      // 2. The team drives a single schedule (there is no schedule-picker UI),
      //    so the newest one the index returns is the one the screen renders.
      final OnCallSchedule schedule = OnCallSchedule.fromMap(rows.first);
      if (schedule.id.isEmpty) {
        Log.error('[OnCallController.reload] schedule row carries no id');
        _publishFailure();
        return;
      }

      // 3. Who holds the pager, resolved server-side for that schedule.
      final MagicResponse current = await Http.get(
        '/on-call/current',
        query: <String, dynamic>{'schedule_id': schedule.id},
      );
      if (!current.successful) {
        Log.error('[OnCallController.reload] current: ${current.errorMessage}');
        _publishFailure();
        return;
      }

      _publishSchedule(schedule, _responderFrom(current));
    } catch (error, stackTrace) {
      Log.error('[OnCallController.reload] failed: $error\n$stackTrace');
      _publishFailure();
    }
  }

  /// Drops the previous session's schedule, ring, overrides and resolved
  /// responder, publishes the cleared state, then refetches for the identity
  /// that is now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): the cached
  /// schedule id is the target of every write, so a stale one would page
  /// against the previous team's schedule.
  @override
  Future<void> resetForSession() async {
    _phase = OnCallPhase.loading;
    _schedule = null;
    _rotation = const [];
    _overrides = const [];
    _currentResponder = null;
    refreshUI();

    await reload();
  }

  /// Publishes a loaded [schedule] plus the responder the server resolved.
  void _publishSchedule(OnCallSchedule schedule, OnCallResponder? responder) {
    final List<OnCallRotationSlot> ring = schedule.rotations
        .map(OnCallRotationSlot.fromMap)
        .toList()
      ..sort((a, b) => a.position.compareTo(b.position));

    _phase = OnCallPhase.ready;
    _schedule = schedule;
    _rotation = ring;
    _overrides = schedule.overrides.map(OnCallOverrideWindow.fromMap).toList();
    _currentResponder = responder;
    refreshUI();
  }

  /// Publishes "this team has no on-call schedule yet".
  void _publishEmpty() {
    _phase = OnCallPhase.empty;
    _schedule = null;
    _rotation = const [];
    _overrides = const [];
    _currentResponder = null;
    refreshUI();
  }

  /// Publishes a read failure, clearing everything: a half-known ring cannot be
  /// rendered honestly, and the view offers a retry instead.
  void _publishFailure() {
    _phase = OnCallPhase.error;
    _schedule = null;
    _rotation = const [];
    _overrides = const [];
    _currentResponder = null;
    refreshUI();
  }

  /// The `data[]` rows of a paginated index response, or an empty list when the
  /// payload does not carry any.
  List<Map<String, dynamic>> _dataRows(MagicResponse response) {
    final Object? payload = response.data;
    if (payload is! Map<String, dynamic>) return const [];

    final Object? data = payload['data'];
    if (data is! List) return const [];

    return data.whereType<Map<String, dynamic>>().toList();
  }

  /// Decodes `data.user` of a `GET /on-call/current?schedule_id=...` response.
  ///
  /// `null` is the endpoint's honest answer for "nobody is on call" (empty ring,
  /// no covering override), so it is passed through untouched.
  OnCallResponder? _responderFrom(MagicResponse response) {
    final Object? payload = response.data;
    if (payload is! Map<String, dynamic>) return null;

    final Object? data = payload['data'];
    if (data is! Map<String, dynamic>) return null;

    final Object? user = data['user'];
    if (user is! Map<String, dynamic>) return null;

    return OnCallResponder.fromMap(user);
  }

  // ---------------------------------------------------------------------------
  // Writes: live against `api/v1/on-call/*`, each followed by a full re-read.
  // ---------------------------------------------------------------------------

  /// Creates the team's on-call schedule via `POST /on-call/schedules` through
  /// the model's ORM `save()`, then re-reads.
  ///
  /// The timezone is the one [DateManager] resolved for this device at boot, so
  /// the created schedule is anchored where the operator actually is.
  Future<bool> createSchedule() async {
    final OnCallSchedule schedule = OnCallSchedule()
      ..fill(<String, dynamic>{
        'name': trans('uptizm.teams.oncall_default_schedule_name'),
        'timezone': DateManager.instance.timezoneName,
      });

    final bool ok = await schedule.save();
    if (!ok) {
      Log.error('[OnCallController.createSchedule] save() returned false');
      _toastError(null);
      return false;
    }

    await reload();
    Magic.success(
      trans('uptizm.teams.oncall_create_button'),
      schedule.name ?? '',
    );
    return true;
  }

  /// Adds [member] to the end of the ring via
  /// `POST /on-call/schedules/:id/rotations`, then re-reads.
  ///
  /// [shiftHours] defaults to a 24-hour shift, matching the backend's own
  /// default for a slot created without one.
  Future<bool> addToRotation(TeamResponder member, {int? shiftHours}) async {
    final String? id = scheduleId;
    if (id == null) {
      Log.error('[OnCallController.addToRotation] no schedule loaded');
      _toastError(null);
      return false;
    }

    final bool ok = await _write(
      label: 'addToRotation ${member.id}',
      request: () => Http.post(
        '/on-call/schedules/$id/rotations',
        data: <String, dynamic>{
          'user_id': member.id,
          'position': _rotation.length,
          'shift_hours': shiftHours ?? 24,
        },
      ),
    );
    if (!ok) return false;

    Magic.success(trans('uptizm.teams.oncall_add_button'), member.name);
    return true;
  }

  /// Removes [slot] from the ring via
  /// `DELETE /on-call/schedules/:id/rotations/:rotationId`, then re-reads.
  Future<bool> removeFromRotation(OnCallRotationSlot slot) async {
    final String? id = scheduleId;
    if (id == null || slot.id.isEmpty) {
      Log.error('[OnCallController.removeFromRotation] unresolved slot target');
      _toastError(null);
      return false;
    }

    final bool ok = await _write(
      label: 'removeFromRotation ${slot.id}',
      request: () =>
          Http.delete('/on-call/schedules/$id/rotations/${slot.id}'),
    );
    if (!ok) return false;

    Magic.success(
      trans('uptizm.teams.oncall_remove_button'),
      slot.userName ?? '',
    );
    return true;
  }

  /// Repositions the ring to the order of [order] via
  /// `PUT /on-call/schedules/:id/rotations/reorder`, then re-reads.
  ///
  /// `PUT`, not `PATCH`: the route accepts both and magic's network driver has
  /// no `patch` verb. Returns `false` without a request for a ring of fewer
  /// than two slots (there is no order to change).
  ///
  /// The only write without a success toast: the re-read reorders the rows on
  /// screen, which is the feedback, and a toast per nudge would stack up.
  Future<bool> reorderRotation(List<OnCallRotationSlot> order) async {
    final String? id = scheduleId;
    if (id == null || order.length < 2) return false;

    return _write(
      label: 'reorderRotation $id',
      request: () => Http.put(
        '/on-call/schedules/$id/rotations/reorder',
        data: <String, dynamic>{
          'order': <Map<String, dynamic>>[
            for (final (int index, OnCallRotationSlot slot) in order.indexed)
              <String, dynamic>{'id': slot.id, 'position': index},
          ],
        },
      ),
    );
  }

  /// Hands the pager to [member] for a temporary window via
  /// `POST /on-call/schedules/:id/overrides`, then re-reads.
  ///
  /// [startsAt] defaults to now; [endsAt] defaults to a 24-hour window.
  Future<bool> addOverride(
    TeamResponder member, {
    DateTime? startsAt,
    DateTime? endsAt,
  }) async {
    final String? id = scheduleId;
    if (id == null) {
      Log.error('[OnCallController.addOverride] no schedule loaded');
      _toastError(null);
      return false;
    }

    final DateTime starts = startsAt ?? DateTime.now().toUtc();
    final DateTime ends = endsAt ?? starts.add(const Duration(hours: 24));

    final bool ok = await _write(
      label: 'addOverride ${member.id}',
      request: () => Http.post(
        '/on-call/schedules/$id/overrides',
        data: <String, dynamic>{
          'user_id': member.id,
          'starts_at': starts.toIso8601String(),
          'ends_at': ends.toIso8601String(),
        },
      ),
    );
    if (!ok) return false;

    Magic.success(trans('uptizm.teams.oncall_override_label'), member.name);
    return true;
  }

  /// Lifts the override [window] via
  /// `DELETE /on-call/schedules/:id/overrides/:overrideId`, then re-reads (the
  /// pager returns to whoever the ring resolves to).
  Future<bool> removeOverride(OnCallOverrideWindow window) async {
    final String? id = scheduleId;
    if (id == null || window.id.isEmpty) {
      Log.error('[OnCallController.removeOverride] unresolved override target');
      _toastError(null);
      return false;
    }

    final bool ok = await _write(
      label: 'removeOverride ${window.id}',
      request: () =>
          Http.delete('/on-call/schedules/$id/overrides/${window.id}'),
    );
    if (!ok) return false;

    Magic.success(
      trans('uptizm.teams.oncall_override_remove_button'),
      window.userName ?? '',
    );
    return true;
  }

  /// Runs one write [request] and, on success, re-reads the whole screen.
  ///
  /// Returns whether the write succeeded. A non-2xx or a thrown transport
  /// error logs (tagged with [label]) and surfaces the write-failure toast; the
  /// caller adds its own success toast. Never throws.
  Future<bool> _write({
    required String label,
    required Future<MagicResponse> Function() request,
  }) async {
    try {
      final MagicResponse response = await request();
      if (!response.successful) {
        Log.error('[OnCallController.$label] ${response.errorMessage}');
        _toastError(response.errorMessage);
        return false;
      }

      await reload();
      return true;
    } catch (error, stackTrace) {
      Log.error('[OnCallController.$label] failed: $error\n$stackTrace');
      _toastError(null);
      return false;
    }
  }

  /// Surfaces a generic on-call write-failure toast.
  void _toastError(String? detail) {
    Magic.error(
      trans('uptizm.teams.on_call_error_title'),
      detail ?? trans('uptizm.teams.on_call_error_description'),
    );
  }
}
