import 'package:flutter/widgets.dart' show Color, immutable;

/// **Team + account mock fixtures.**
///
/// Ported from the design lab's `src/lib/teams.ts`. A team is the tenant
/// boundary that owns the monitors, incidents, and status pages; the sidebar
/// switcher and the (not-yet-built) team-management screens read from here.
///
/// This is design-lab mock data, not theme configuration: [Team.color] is a
/// per-team brand color (the avatar tint), the direct analogue of the React
/// source's inline `style={{ background: team.color }}`. It is content data, so
/// it lives here as a raw [Color] and is passed inline to `WDiv.backgroundColor`
/// at the leaf, NOT a semantic Wind token (there is no token for an arbitrary
/// per-tenant color).
@immutable
class Team {
  /// Stable identity used for the active-team check.
  final String id;

  /// Display name shown in the switcher trigger and the team list.
  final String name;

  /// URL-safe handle (mirrors the source; unused by the mock UI yet).
  final String slug;

  /// Brand/avatar tint applied as the switcher avatar background.
  final Color color;

  /// Creates a [Team].
  const Team({
    required this.id,
    required this.name,
    required this.slug,
    required this.color,
  });

  /// The leading initial rendered inside the colored avatar square.
  String get initial => name.isEmpty ? '?' : name.substring(0, 1).toUpperCase();
}

/// The signed-in user shown in the sidebar account menu.
@immutable
class CurrentUser {
  /// Full display name.
  final String name;

  /// Account email shown under the name.
  final String email;

  /// Two-letter avatar initials.
  final String initials;

  /// Creates a [CurrentUser].
  const CurrentUser({
    required this.name,
    required this.email,
    required this.initials,
  });
}

/// The available teams, newest-first as fixtured (Acme is the active default).
const List<Team> teams = [
  Team(id: 'acme', name: 'Acme Inc.', slug: 'acme', color: Color(0xFF16A34A)),
  Team(
    id: 'personal',
    name: 'Personal',
    slug: 'personal',
    color: Color(0xFF6366F1),
  ),
];

/// The signed-in user (account menu).
const CurrentUser currentUser = CurrentUser(
  name: 'Anılcan Çakır',
  email: 'anil@acme.com',
  initials: 'AÇ',
);
