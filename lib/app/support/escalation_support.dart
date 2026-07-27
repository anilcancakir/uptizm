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
