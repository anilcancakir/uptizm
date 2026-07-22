import 'package:flutter/foundation.dart';

import '../mocks/teams_data.dart' show teamMembers;
import '../support/team_types.dart' show TeamMember;

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
/// Sourced from the same [teamMembers] fixture the on-call schedule reads
/// (there is no backend team-member roster read on the client yet, mirroring
/// `on_call_schedule_view.dart`). The on-call entry maps to
/// `target_type: on_call` (no id); each member entry maps to
/// `target_type: user` with that member's id.
List<EscalationTargetOption> escalationTargetOptions() {
  return [
    const EscalationTargetOption(
      type: EscalationTargetType.onCall,
      label: 'On-call rotation',
    ),
    for (final TeamMember member in teamMembers)
      EscalationTargetOption(
        type: EscalationTargetType.user,
        userId: member.id,
        label: member.name,
      ),
  ];
}

/// Turns a rung delay into its display label.
///
/// `0` minutes reads as "Immediately"; anything else composes "After :n min".
/// Mirrors `escalationDelayLabel` in the React oncall mock. No i18n: the
/// label is assembled directly, matching the source's plain template string.
///
/// ```dart
/// escalationDelayLabel(0); // "Immediately"
/// escalationDelayLabel(5); // "After 5 min"
/// ```
String escalationDelayLabel(int afterMinutes) {
  if (afterMinutes == 0) return 'Immediately';
  return 'After $afterMinutes min';
}
