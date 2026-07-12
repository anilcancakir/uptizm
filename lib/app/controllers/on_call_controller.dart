import 'package:magic/magic.dart';

import '../models/on_call_schedule.dart';
import '../mocks/teams_data.dart';

/// Controller backing the [OnCallScheduleView]'s live rotation/override
/// mutations against the S27 `api/v1/on-call/*` endpoints.
///
/// The view's rendered rotation/hero cards stay sourced from the
/// [onCallRotation]/[teamMembers] fixtures (they have no backend field
/// parity: the backend's `OnCallRotation` has no textual `span`, only
/// `shift_hours`, mirroring `StatusPageController`'s documented read/write
/// split), but [addToRotation], [removeFromRotation], and [addOverride] are
/// real writes: they resolve (or lazily create) the team's single on-call
/// [OnCallSchedule] backend record, POST/DELETE against it, and [reload]
/// the cached ring afterward, matching `monitor_controller.dart:145-221`'s
/// try/log/toast/refresh shape.
///
/// The team currently has exactly one backend schedule (there is no
/// schedule-picker UI yet), so [_scheduleId] and [_rotationIdByMember] cache
/// that single schedule's id and its rotation-row-per-member lookup (the
/// member id being the only stable key the mock rotation rows carry back to
/// the view).
class OnCallController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static OnCallController get instance => Magic.findOrPut(OnCallController.new);

  /// The backend id of the team's single on-call schedule, resolved by
  /// [reload] or lazily created by [_ensureSchedule]. `null` until either
  /// resolves.
  String? _scheduleId;

  /// Backend rotation row id keyed by [TeamMember.id], populated by [reload]
  /// so [removeFromRotation] can resolve which rotation row to delete without
  /// the view needing to carry a backend id on its own fixture-backed
  /// [OnCallShift] model.
  final Map<String, String> _rotationIdByMember = {};

  /// The resolved schedule id, or `null` before the first successful
  /// [reload]/[_ensureSchedule].
  String? get scheduleId => _scheduleId;

  /// The backend rotation row id for [memberId], or `null` when that member
  /// is not currently in the cached ring.
  String? rotationIdFor(String memberId) => _rotationIdByMember[memberId];

  /// Bootstraps the schedule cache the first time this controller backs a
  /// view.
  @override
  void onInit() {
    super.onInit();
    reload();
  }

  /// Refreshes the cached schedule id and member-to-rotation-row lookup from
  /// the backend: lists the team's schedules, then loads the first one's
  /// detail (with its rotation ring eager-loaded).
  ///
  /// Degrades silently (keeping the last-known-good cache) on any failure:
  /// no schedule yet, a non-2xx response, or a transport error, mirroring
  /// `MonitorController.reload`'s degradation shape.
  Future<void> reload() async {
    try {
      final List<OnCallSchedule> schedules = await OnCallSchedule.all();
      if (schedules.isEmpty) return;

      final String id = schedules.first.id;
      if (id.isEmpty) return;

      final OnCallSchedule? detail = await OnCallSchedule.find(id);
      if (detail == null) return;

      _applyScheduleDetail(detail);
    } catch (_) {
      // Deliberate degradation: a transport failure (including an
      // unregistered `network` service in a bare test host) keeps the
      // last-known-good schedule/ring cache.
    }
  }

  /// Republishes [_scheduleId] and the [_rotationIdByMember] lookup from a
  /// full [OnCallSchedule] (its `rotations` eager-loaded).
  ///
  /// Every mutating endpoint on this schedule (`addRotation`, `addOverride`,
  /// and the initial `store`/`show`) returns this same shape, so a mutation's
  /// own response is hydrated into an [OnCallSchedule] and applied directly
  /// instead of firing a redundant `GET /on-call/schedules/:id` round trip.
  void _applyScheduleDetail(OnCallSchedule schedule) {
    _scheduleId = schedule.id.isEmpty ? null : schedule.id;

    _rotationIdByMember.clear();
    for (final Map<String, dynamic> row in schedule.rotations) {
      final String? rotationId = row['id'] as String?;
      final String? userId = row['user_id'] as String?;
      if (rotationId != null && userId != null) {
        _rotationIdByMember[userId] = rotationId;
      }
    }
    refreshUI();
  }

  /// Resolves the team's schedule id, creating a default one via
  /// `POST /on-call/schedules` when none exists yet.
  ///
  /// Returns `null` (without surfacing a toast; the caller does that with
  /// action-specific copy) when neither the cache nor the create call
  /// resolves an id.
  Future<String?> _ensureSchedule() async {
    if (_scheduleId != null) return _scheduleId;

    await reload();
    if (_scheduleId != null) return _scheduleId;

    // `save()` returns false (never throws) when the create cannot reach the
    // backend; the caller surfaces the action-specific error toast, so a false
    // result degrades to a null id (the ORM already swallowed the transport
    // failure internally).
    final OnCallSchedule schedule = OnCallSchedule()
      ..fill(<String, dynamic>{'name': 'Primary rotation', 'timezone': 'UTC'});
    final bool ok = await schedule.save();
    if (!ok) return null;

    _scheduleId = schedule.id.isEmpty ? null : schedule.id;
    return _scheduleId;
  }

  // ---------------------------------------------------------------------------
  // Business actions
  // ---------------------------------------------------------------------------

  /// Adds [member] to the rotation ring via
  /// `POST /on-call/schedules/:id/rotations`, refreshes the cache, and
  /// surfaces a success toast.
  ///
  /// Returns `true` on success, `false` on any failure (an error toast is
  /// surfaced in that case; no exception is ever thrown to the caller).
  Future<bool> addToRotation(TeamMember member, {int? shiftHours}) async {
    final String? scheduleId = await _ensureSchedule();
    if (scheduleId == null) {
      _toastError(null);
      return false;
    }

    try {
      final response = await Http.post(
        '/on-call/schedules/$scheduleId/rotations',
        data: <String, dynamic>{
          'user_id': member.id,
          'position': _rotationIdByMember.length,
          'shift_hours': shiftHours ?? 24,
        },
      );
      if (!response.successful) {
        Log.error(
          '[OnCallController.addToRotation] ${member.id}: ${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return false;
      }

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (data is Map<String, dynamic>) {
        _applyScheduleDetail(OnCallSchedule.fromMap(data));
      }

      Magic.success(trans('uptizm.teams.oncall_add_button'), member.name);
      return true;
    } catch (error) {
      Log.error('[OnCallController.addToRotation] ${member.id} failed: $error');
      _toastError(null);
      return false;
    }
  }

  /// Removes [shift]'s responder from the rotation ring via
  /// `DELETE /on-call/schedules/:id/rotations/:rotationId`, refreshes the
  /// cache, and surfaces a success toast.
  ///
  /// Returns `false` without firing a request when no schedule or cached
  /// rotation row resolves for [shift] (an error toast is still surfaced).
  Future<bool> removeFromRotation(OnCallShift shift) async {
    final String? scheduleId = _scheduleId;
    final String? rotationId = _rotationIdByMember[shift.memberId];
    if (scheduleId == null || rotationId == null) {
      _toastError(null);
      return false;
    }

    try {
      final response = await Http.delete(
        '/on-call/schedules/$scheduleId/rotations/$rotationId',
      );
      if (!response.successful) {
        Log.error(
          '[OnCallController.removeFromRotation] $rotationId: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return false;
      }

      // The backend returns 204 No Content for a removal, so the cache is
      // updated locally rather than firing a redundant reload round trip.
      _rotationIdByMember.remove(shift.memberId);
      refreshUI();
      Magic.success(
        trans('uptizm.teams.oncall_remove_button'),
        shift.memberName,
      );
      return true;
    } catch (error) {
      Log.error(
        '[OnCallController.removeFromRotation] $rotationId failed: $error',
      );
      _toastError(null);
      return false;
    }
  }

  /// Hands the pager to [member] for a temporary window via
  /// `POST /on-call/schedules/:id/overrides`, refreshes the cache, and
  /// surfaces a success toast.
  ///
  /// [startsAt] defaults to now; [endsAt] defaults to a 24-hour window.
  /// Returns `true` on success, `false` on any failure (an error toast is
  /// surfaced in that case; no exception is ever thrown to the caller).
  Future<bool> addOverride(
    TeamMember member, {
    DateTime? startsAt,
    DateTime? endsAt,
  }) async {
    final String? scheduleId = await _ensureSchedule();
    if (scheduleId == null) {
      _toastError(null);
      return false;
    }

    final DateTime starts = startsAt ?? DateTime.now().toUtc();
    final DateTime ends = endsAt ?? starts.add(const Duration(hours: 24));

    try {
      final response = await Http.post(
        '/on-call/schedules/$scheduleId/overrides',
        data: <String, dynamic>{
          'user_id': member.id,
          'starts_at': starts.toIso8601String(),
          'ends_at': ends.toIso8601String(),
        },
      );
      if (!response.successful) {
        Log.error(
          '[OnCallController.addOverride] ${member.id}: ${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return false;
      }

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (data is Map<String, dynamic>) {
        _applyScheduleDetail(OnCallSchedule.fromMap(data));
      }

      Magic.success(trans('uptizm.teams.oncall_override_label'), member.name);
      return true;
    } catch (error) {
      Log.error('[OnCallController.addOverride] ${member.id} failed: $error');
      _toastError(null);
      return false;
    }
  }

  // ---------------------------------------------------------------------------
  // Toast helper
  // ---------------------------------------------------------------------------

  /// Surfaces a generic on-call write-failure toast.
  void _toastError(String? detail) {
    Magic.error(
      trans('uptizm.teams.on_call_error_title'),
      detail ?? trans('uptizm.teams.on_call_error_description'),
    );
  }
}
