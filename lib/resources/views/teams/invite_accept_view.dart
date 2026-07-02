import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../../app/mocks/teams.dart';

/// **The standalone team-invite acceptance screen at `/invite/:token`.**
///
/// A faithful Flutter port of the React `InviteAcceptPage`: a centered card
/// with the Uptizm wordmark, a team avatar tile, a "Join :team on Uptizm"
/// heading, body copy, and Accept/Decline actions. Registered OUTSIDE
/// [AppLayout] (Step 11), so it renders with no sidebar and no shell chrome,
/// mirroring the React router placing `/invite/:token` outside its app shell.
///
/// [token] is a design-lab mock: any value (including `null`) resolves to
/// `teams.first`, with no verification. Accept shows a [Magic.success] toast
/// and routes to `/`; Decline routes to `/` directly.
///
/// ### Example
/// ```dart
/// // Registered as a top-level route with no AppLayout wrapper:
/// MagicRoute.page('/invite/:token', (String token) => InviteAcceptView(token: token));
/// ```
@immutable
class InviteAcceptView extends StatelessWidget {
  /// The invite token from the route. Unused beyond presence: the mock
  /// resolves any token to `teams.first`, with no verification.
  final String? token;

  /// Creates the [InviteAcceptView] for the given [token].
  const InviteAcceptView({super.key, this.token});

  @override
  Widget build(BuildContext context) {
    // 1. Design-lab mock: any token resolves to the first team, unverified.
    final Team team = teams.first;

    return WDiv(
      className:
          'min-h-screen flex items-center justify-center bg-surface px-4',
      child: WDiv(
        className:
            'w-full max-w-sm rounded-lg border border-color-border '
            'bg-surface-container p-8',
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            _buildWordmark(),
            const SizedBox(height: 28),
            _buildTeamAvatar(team),
            const SizedBox(height: 16),
            WText(
              trans('uptizm.teams.invite_accept_heading', {'name': team.name}),
              className:
                  'text-xl font-semibold tracking-tight text-fg text-center',
            ),
            const SizedBox(height: 8),
            WText(
              trans('uptizm.teams.invite_accept_body'),
              className: 'text-sm text-fg-muted text-center',
            ),
            const SizedBox(height: 24),
            _buildActions(),
          ],
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Wordmark
  // ---------------------------------------------------------------------------

  /// Builds the "Uptizm" wordmark: a brand-tinted glyph tile next to the
  /// product name, mirroring the React `Wordmark` shared auth part.
  Widget _buildWordmark() {
    return WDiv(
      className: 'flex flex-row items-center justify-center gap-2',
      children: [
        WDiv(
          className:
              'size-7 rounded-lg bg-primary flex items-center justify-center',
          child: const WIcon(
            Icons.show_chart,
            className: 'text-on-primary text-base',
          ),
        ),
        WText(
          'Uptizm',
          className: 'text-base font-semibold tracking-tight text-fg',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Team avatar
  // ---------------------------------------------------------------------------

  /// Builds the team avatar tile: [Team.color] content color with the team's
  /// leading initial in white, the sanctioned raw-color exception (the
  /// `StatusPagePreview` brand-header precedent).
  Widget _buildTeamAvatar(Team team) {
    return WDiv(
      backgroundColor: team.color,
      className: 'size-14 rounded-2xl flex items-center justify-center',
      child: WText(team.initial, className: 'text-2xl font-bold text-white'),
    );
  }

  // ---------------------------------------------------------------------------
  // Actions
  // ---------------------------------------------------------------------------

  /// Builds the Accept/Decline action column.
  Widget _buildActions() {
    return WDiv(
      className: 'flex flex-col gap-2 w-full',
      children: [
        Button(
          onPressed: _accept,
          child: WText(trans('uptizm.teams.invite_accept_button')),
        ),
        Button(
          intent: ButtonIntent.ghost,
          onPressed: _decline,
          child: WText(trans('uptizm.teams.invite_accept_decline_button')),
        ),
      ],
    );
  }

  /// Accepts the invite: shows a success toast and returns to the app root
  /// (mock: nothing persists).
  void _accept() {
    Magic.success(
      trans('uptizm.teams.invite_accepted'),
      trans('uptizm.teams.invite_accept_heading', {'name': teams.first.name}),
    );
    MagicRoute.to('/');
  }

  /// Declines the invite and returns to the app root.
  void _decline() {
    MagicRoute.to('/');
  }
}
