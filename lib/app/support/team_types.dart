import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart' show trans;
import 'package:magic_payments/magic_payments.dart' show UsageStat;

import '../enums/team_role.dart' show TeamRole;

/// A person with access to the current team.
///
/// Mirrors the `Member` interface in the React teams mock.
@immutable
class TeamMember {
  /// Stable identifier used for the active-on-call and self checks.
  final String id;

  /// Full display name.
  final String name;

  /// Account email shown under the name.
  final String email;

  /// Two-letter avatar initials.
  final String initials;

  /// Access level within the team.
  final TeamRole role;

  /// Relative join date string, e.g. `"3 months ago"`.
  final String joinedAt;

  /// Whether this row is the signed-in user (drives the "Remove" hide rule).
  final bool isSelf;

  const TeamMember({
    required this.id,
    required this.name,
    required this.email,
    required this.initials,
    required this.role,
    required this.joinedAt,
    required this.isSelf,
  });
}

/// A teammate invited but not yet joined.
///
/// Mirrors the `Invite` interface in the React teams mock.
@immutable
class TeamInvitation {
  /// Stable identifier used for the revoke action.
  final String id;

  /// The invited email address.
  final String email;

  /// The role the invite will grant once accepted.
  final TeamRole role;

  /// Relative time string of when the invite was sent, e.g. `"2 days ago"`.
  final String invitedAt;

  const TeamInvitation({
    required this.id,
    required this.email,
    required this.role,
    required this.invitedAt,
  });
}

/// Two-letter uppercase avatar initials for [name].
///
/// Takes the first letter of up to the first two words, falling back to `'?'`
/// when [name] is null or blank. Shared by every on-call value object below so
/// an avatar tile never has to be fed a pre-baked `initials` wire field (the
/// backend does not send one).
String teamInitials(String? name) {
  final String trimmed = name?.trim() ?? '';
  if (trimmed.isEmpty) return '?';

  final List<String> words = trimmed.split(RegExp(r'\s+'));
  final String first = words[0][0];
  final String second = words.length > 1 && words[1].isNotEmpty
      ? words[1][0]
      : '';
  return (first + second).toUpperCase();
}

/// A real team member, decoded from the `GET /teams/{team}/members` payload
/// that `MagicStarterTeamController.members` caches.
///
/// Distinct from the fixture-shaped [TeamMember]: the wire roster carries only
/// `id`/`name`/`email`/`profile_photo_url`/`role`, so there is no `joinedAt` or
/// `isSelf` to fill and [role] stays nullable rather than defaulting to a level
/// the server never claimed.
@immutable
class TeamResponder {
  /// The member's user id (the `user_id` every on-call write posts).
  final String id;

  /// Full display name.
  final String name;

  /// Access level within the team; `null` when the roster omits it or sends a
  /// value this client does not know.
  final TeamRole? role;

  /// Creates a [TeamResponder].
  const TeamResponder({
    required this.id,
    required this.name,
    this.role,
  });

  /// Decodes one entry of the team-members roster.
  ///
  /// The id is stringified because the backend is UUID-optional (an integer
  /// key is just as valid as a UUID), and every on-call route path and
  /// `user_id` body field is a string on the wire.
  factory TeamResponder.fromMemberMap(Map<String, dynamic> map) {
    return TeamResponder(
      id: map['id']?.toString() ?? '',
      name: (map['name'] as String?) ?? '',
      role: _teamRoleFromWire(map['role'] as String?),
    );
  }

  /// Decodes the whole roster `MagicStarterTeamController.members` publishes,
  /// dropping any entry without both an id and a name.
  ///
  /// An unusable entry is skipped rather than rendered as a blank row: a picker
  /// offering a nameless responder invites paging nobody. Shared by every
  /// surface that targets a real person (the on-call rotation and override
  /// pickers, the escalation-rung target picker).
  static List<TeamResponder> listFromMemberMaps(
    List<Map<String, dynamic>> members,
  ) {
    final List<TeamResponder> responders = [];
    for (final Map<String, dynamic> member in members) {
      final TeamResponder responder = TeamResponder.fromMemberMap(member);
      if (responder.id.isEmpty || responder.name.isEmpty) continue;
      responders.add(responder);
    }

    return responders;
  }

  /// Avatar initials for the member's [name].
  String get initials => teamInitials(name);
}

/// Decodes a team-membership `role` wire string into a [TeamRole].
///
/// Returns `null` (rather than defaulting to [TeamRole.member]) for an absent
/// or unrecognized value, so a role badge is omitted instead of asserting an
/// access level the server never sent.
TeamRole? _teamRoleFromWire(String? wire) {
  return switch (wire) {
    'owner' => TeamRole.owner,
    'admin' => TeamRole.admin,
    'member' => TeamRole.member,
    _ => null,
  };
}

/// One responder slot in a schedule's rotation ring, as returned by
/// `GET /on-call/schedules` (`OnCallScheduleResource`'s `rotations` array).
///
/// The backend models a slot as a position plus a shift LENGTH
/// (`shift_hours`), not a wall-clock span: the actual window a responder holds
/// is derived server-side by `RotationResolver` from the schedule anchor, so
/// this object never carries (or invents) a `"Mon 09:00 - Wed 09:00"` label.
@immutable
class OnCallRotationSlot {
  /// The backend rotation row id (the `DELETE`/reorder target).
  final String id;

  /// The responder's user id.
  final String userId;

  /// The responder's display name; `null` when the server could not resolve
  /// the user behind the slot.
  final String? userName;

  /// Ascending order within the ring.
  final int position;

  /// How many hours this responder holds the pager per cycle.
  final int shiftHours;

  /// Creates an [OnCallRotationSlot].
  const OnCallRotationSlot({
    required this.id,
    required this.userId,
    required this.userName,
    required this.position,
    required this.shiftHours,
  });

  /// Decodes one `rotations[]` wire entry.
  factory OnCallRotationSlot.fromMap(Map<String, dynamic> map) {
    return OnCallRotationSlot(
      id: map['id']?.toString() ?? '',
      userId: map['user_id']?.toString() ?? '',
      userName: map['user_name'] as String?,
      position: (map['position'] as num?)?.toInt() ?? 0,
      shiftHours: (map['shift_hours'] as num?)?.toInt() ?? 0,
    );
  }

  /// Avatar initials for the slot's responder.
  String get initials => teamInitials(userName);
}

/// A temporary responder swap layered over the ring, as returned by
/// `GET /on-call/schedules` (`OnCallScheduleResource`'s `overrides` array).
@immutable
class OnCallOverrideWindow {
  /// The backend override row id (the `DELETE` target).
  final String id;

  /// The overriding responder's user id.
  final String userId;

  /// The overriding responder's display name; `null` when the server could
  /// not resolve the user behind the override.
  final String? userName;

  /// When the override starts holding the pager; `null` when the wire omits
  /// or malforms it.
  final DateTime? startsAt;

  /// When the override hands the pager back; `null` when the wire omits or
  /// malforms it.
  final DateTime? endsAt;

  /// Creates an [OnCallOverrideWindow].
  const OnCallOverrideWindow({
    required this.id,
    required this.userId,
    required this.userName,
    required this.startsAt,
    required this.endsAt,
  });

  /// Decodes one `overrides[]` wire entry.
  factory OnCallOverrideWindow.fromMap(Map<String, dynamic> map) {
    return OnCallOverrideWindow(
      id: map['id']?.toString() ?? '',
      userId: map['user_id']?.toString() ?? '',
      userName: map['user_name'] as String?,
      startsAt: DateTime.tryParse((map['starts_at'] as String?) ?? ''),
      endsAt: DateTime.tryParse((map['ends_at'] as String?) ?? ''),
    );
  }

  /// Avatar initials for the overriding responder.
  String get initials => teamInitials(userName);

  /// Whether this window contains [moment] (inclusive on both ends, matching
  /// the backend `RotationResolver`'s `betweenIncluded` comparison).
  ///
  /// Used ONLY to label the hero card ("override until ..." versus "shift"):
  /// WHO holds the pager is resolved server-side by `GET /on-call/current` and
  /// is never recomputed here. A window missing either bound covers nothing.
  bool covers(DateTime moment) {
    final DateTime? start = startsAt;
    final DateTime? end = endsAt;
    if (start == null || end == null) return false;

    return !moment.isBefore(start) && !moment.isAfter(end);
  }
}

/// The responder `GET /on-call/current` resolved for a schedule.
///
/// A `null` [OnCallResponder] (rather than an instance with a blank name) is
/// how "nobody is on call" travels: the endpoint answers `user: null` for an
/// empty ring with no covering override, and that must never be softened into
/// a placeholder person.
@immutable
class OnCallResponder {
  /// The responder's user id.
  final String id;

  /// The responder's display name.
  final String name;

  /// The responder's account email; `null` when the wire omits it.
  final String? email;

  /// Creates an [OnCallResponder].
  const OnCallResponder({
    required this.id,
    required this.name,
    this.email,
  });

  /// Decodes the `data.user` object of a `GET /on-call/current` response.
  factory OnCallResponder.fromMap(Map<String, dynamic> map) {
    return OnCallResponder(
      id: map['id']?.toString() ?? '',
      name: (map['name'] as String?) ?? '',
      email: map['email'] as String?,
    );
  }

  /// Avatar initials for the responder's [name].
  String get initials => teamInitials(name);
}

// ---------------------------------------------------------------------------
// Billing usage: the numbers come from the package, the words from uptizm
// ---------------------------------------------------------------------------

/// The display copy uptizm holds for each metered resource
/// `GET /billing/usage` reports, keyed by the resource's wire key.
///
/// A catalogue key rather than a literal, because the billing page rendered
/// "Monitors", "Responders", "Checks this month" and "checks" in English inside
/// an otherwise fully Turkish page. Resolved at call time (see
/// [withUsageCopy]) rather than held in a `const` map, since a `trans()` read at
/// library-load time would resolve before any loader is registered.
///
/// A key absent from this table is a resource this app has no name for, and it
/// is deliberately NOT defaulted to the wire key: a meter labelled
/// `checks_this_month` is a raw key on a customer's screen. It reaches a caller
/// with a null [UsageStat.label] instead, and the meter grid skips it.
Map<String, ({String label, String unit})> _usageCopy() {
  return {
    'monitors': (label: trans('uptizm.teams.usage_monitors'), unit: ''),
    'responders': (label: trans('uptizm.teams.usage_responders'), unit: ''),
    'checks_this_month': (
      label: trans('uptizm.teams.usage_checks_this_month'),
      unit: trans('uptizm.teams.usage_unit_checks'),
    ),
  };
}

/// Pairs uptizm's display copy onto the [UsageStat]s `Payments.getUsage()`
/// decoded.
///
/// `magic_payments` decodes the numbers and deliberately carries no label: a
/// display name is product copy, and a package that shipped one would render its
/// author's English in every consumer. So the wire decode stays there, and the
/// pairing is done here by [UsageStat.key], which is the only stable handle on a
/// resource (the label moves with the language, so nothing may key logic on it).
///
/// Every resource the producer reports is returned, in the order it sent them,
/// including one this app cannot name: a gate looks a resource up by key and
/// does not need a word for it. Such a stat keeps a null [UsageStat.label], and
/// naming it is the renderer's problem rather than this function's.
List<UsageStat> withUsageCopy(List<UsageStat> stats) {
  final Map<String, ({String label, String unit})> copy = _usageCopy();

  return stats.map((UsageStat stat) {
    final ({String label, String unit})? words = copy[stat.key];
    if (words == null) return stat;

    return UsageStat(
      key: stat.key,
      used: stat.used,
      limit: stat.limit,
      label: words.label,
      unit: words.unit,
    );
  }).toList();
}
