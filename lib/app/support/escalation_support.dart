import 'package:flutter/foundation.dart';

import 'package:magic/magic.dart' show trans;

import '../support/team_types.dart' show TeamResponder;

/// The kind of recipient an escalation rung pages, mirroring the backend
/// `EscalationTargetType`.
///
/// Escalation is people-only: a rung pages either the team's shared on-call
/// rotation or one specific team member. There is deliberately no `channel`
/// case: notification channels (Slack, webhook) self-fire on incidents and are
/// not an escalation target, matching the backend enum (the `channel` case was
/// removed there in the same two-sided contract change).
enum EscalationTargetType {
  /// The team's on-call rotation (whoever currently holds the pager). Carries
  /// no target id.
  onCall('on_call'),

  /// A specific team member, identified by the rung's target user id.
  user('user');

  const EscalationTargetType(this.wire);

  /// The value posted as `target_type` and decoded from the backend.
  final String wire;

  /// Resolves a wire `target_type` string to its [EscalationTargetType],
  /// defaulting to [onCall] for an unknown or absent value.
  static EscalationTargetType fromWire(String? wire) {
    return switch (wire) {
      'user' => EscalationTargetType.user,
      _ => EscalationTargetType.onCall,
    };
  }
}

/// One selectable escalation-rung target: the shared on-call rotation, or a
/// specific team member.
///
/// The picker offers exactly one [EscalationTargetType.onCall] entry followed
/// by one [EscalationTargetType.user] entry per team member; a rung resolves to
/// a single option (one backend `EscalationStep` carries one target).
@immutable
class EscalationTargetOption {
  /// The kind of recipient this option pages.
  final EscalationTargetType type;

  /// The team-member id, present only when [type] is
  /// [EscalationTargetType.user].
  final String? userId;

  /// The display label shown in the picker.
  final String label;

  /// Creates an [EscalationTargetOption].
  const EscalationTargetOption({
    required this.type,
    this.userId,
    required this.label,
  });

  /// The stable select key: `on_call` for the rotation, or `user:<id>` for a
  /// specific member. Used as the single-select value so the on-call and
  /// per-member choices never collide.
  String get key => escalationTargetKey(type, userId);
}

/// The stable select key for a rung target: `on_call` for the rotation, or
/// `user:<userId>` for a specific member.
String escalationTargetKey(EscalationTargetType type, String? userId) {
  return type == EscalationTargetType.user ? 'user:$userId' : type.wire;
}

/// Resolves a picked select [key] back to its `(type, userId)` pair:
/// `user:<id>` yields `(user, <id>)`; anything else yields `(onCall, null)`.
(EscalationTargetType, String?) escalationTargetFromKey(String key) {
  const String userPrefix = 'user:';
  if (key.startsWith(userPrefix)) {
    return (EscalationTargetType.user, key.substring(userPrefix.length));
  }
  return (EscalationTargetType.onCall, null);
}

/// Builds the escalation-rung target choices: the shared on-call rotation
/// first, then one entry per team member.
///
/// [responders] is the team's REAL member roster, supplied by the caller (the
/// editor reads `MagicStarterTeamController.members`). It used to be sourced
/// from the `teamMembers` fixture, so a rung could be pointed at a person who
/// does not exist: the ladder would then page nobody during an outage, which is
/// the failure mode escalation exists to prevent. Passing the roster in keeps
/// this function pure and leaves the fetch with the view.
///
/// The on-call entry maps to `target_type: on_call` (no id); each member entry
/// maps to `target_type: user` with that member's id.
List<EscalationTargetOption> escalationTargetOptions(
  List<TeamResponder> responders,
) {
  return [
    EscalationTargetOption(
      type: EscalationTargetType.onCall,
      label: trans('uptizm.teams.escalation_target_on_call'),
    ),
    for (final TeamResponder responder in responders)
      EscalationTargetOption(
        type: EscalationTargetType.user,
        userId: responder.id,
        label: responder.name,
      ),
  ];
}

/// Turns a rung delay into its display label.
///
/// `0` minutes reads as "immediately"; anything else composes the delay. Goes
/// through [trans] like every other user-facing string: the label was assembled
/// from English literals, so a Turkish operator read "After 5 min" inside an
/// otherwise translated ladder.
///
/// ```dart
/// escalationDelayLabel(0); // "Immediately"
/// escalationDelayLabel(5); // "After 5 min"
/// ```
String escalationDelayLabel(int afterMinutes) {
  if (afterMinutes == 0) {
    return trans('uptizm.teams.escalation_delay_immediate');
  }

  return trans('uptizm.teams.escalation_delay_after', {'n': '$afterMinutes'});
}

/// One wire-shaped escalation step, as returned by
/// `GET /escalation-policies/{id}` (`EscalationPolicyResource::toArray`).
///
/// Carries the backend [id] so the editor can diff a saved ladder against a
/// fresh draft and issue exactly the add/remove/reorder calls the change
/// requires.
///
/// Lives here rather than on the controller because [EscalationPolicy] decodes
/// into it: a model importing a controller inverted the layering and closed an
/// import cycle, so the model could not be used without pulling in the
/// controller and, transitively, magic_starter. Its sibling wire value objects
/// (`incident_types`, `team_types`, `status_page_types`, `monitor_types`) all
/// live beside their domain in `support/`, and [EscalationTargetType] above
/// describes the very field [targetType] carries.
@immutable
class EscalationStepWire {
  /// Backend step id, or `null` when the payload carried none.
  ///
  /// Nullable so a step the backend cannot identify is representable instead of
  /// throwing: decoding it as a required `String` took the whole policy decode
  /// down on one odd step, blanking the editor rather than degrading. A null id
  /// lands in the editor's draft as a null too, which its save-diff already
  /// treats as a step to create rather than one to reorder in place.
  final String? id;

  /// Ascending fire order within the policy.
  final int position;

  /// Minutes to wait after the previous step (or after incident open, for
  /// the first step) before this step fires.
  final int delayMinutes;

  /// `on_call` / `user`, per [EscalationTargetType] (people-only).
  final String targetType;

  /// The targeted user id, present only when [targetType] is `user`.
  final String? targetId;

  /// Creates an [EscalationStepWire].
  const EscalationStepWire({
    required this.id,
    required this.position,
    required this.delayMinutes,
    required this.targetType,
    this.targetId,
  });
}

/// An editable escalation rung, carrying the backend step [id] once persisted
/// so `EscalationController.save` can diff a draft ladder against its
/// previously loaded chain.
///
/// [id] is `null` for a brand-new rung (never persisted) OR a previously
/// persisted rung whose [afterMinutes]/[targetType]/[targetUserId] were edited
/// in place: since the backend has no step-update endpoint, an in-place edit
/// clears [id] so the save treats it as "remove the old row, add a fresh one"
/// rather than silently dropping the edit.
@immutable
class EscalationRungDraft {
  /// The backend step id, or `null` when not (or no longer) persisted.
  final String? id;

  /// Minutes to wait after the previous rung fires. 0 means immediately.
  final int afterMinutes;

  /// Who this rung pages: the shared on-call rotation, or a specific member.
  final EscalationTargetType targetType;

  /// The paged member id, present only when [targetType] is
  /// [EscalationTargetType.user]; `null` for the on-call rotation.
  final String? targetUserId;

  /// Creates an [EscalationRungDraft].
  const EscalationRungDraft({
    this.id,
    required this.afterMinutes,
    required this.targetType,
    this.targetUserId,
  });
}
