import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/teams.dart';
import '../../../app/mocks/teams_data.dart';
import '../../../ui/layouts/page_container.dart';

/// **Team Members screen (`/teams/members`).**
///
/// A faithful Flutter port of the React `TeamMembersPage.tsx`: an invite form,
/// the current member roster, and the pending-invitations list, reached from
/// the team switcher dropdown.
///
/// - **Invite card** — an email [Input] plus a Member/Admin [SegmentedControl]
///   and a "Send invite" [Button] (disabled until the email is non-empty).
///   Sending adds a [TeamInvitation] to the local pending list and clears the
///   email field; nothing is emailed, matching the React mock's toast-only
///   affordance.
/// - **Members card** — one row per [teamMembers] entry: an initials avatar
///   tile, name + email, a token-tinted role pill (NOT [StatusBadge], which
///   takes a monitoring [StatusKey] rather than a [TeamRole]), and a ghost
///   "Remove" [Button] on every row except the owner/self.
/// - **Pending invites card** — rendered only when the pending list is
///   non-empty: email, "Invited `<relative time>`" meta, role pill, and a
///   ghost "Revoke" [Button].
///
/// Both Remove and Revoke open a [MagicStarterConfirmDialog] first; on
/// confirm they mutate the local list via [setState] and surface a
/// [Magic.success] toast, mirroring `sessions_settings_view.dart` exactly
/// (including the `if (!mounted) return;` guard after the awaited dialog).
///
/// This is a mock screen: nothing persists past the local widget state.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/teams/members', () => const TeamMembersView());
/// ```
@immutable
class TeamMembersView extends StatefulWidget {
  /// Creates the [TeamMembersView].
  const TeamMembersView({super.key});

  @override
  State<TeamMembersView> createState() => _TeamMembersViewState();
}

class _TeamMembersViewState extends State<TeamMembersView> {
  /// Roles selectable in the invite form's [SegmentedControl], in display
  /// order (mirrors the React `INVITE_ROLES` list: Member first, Admin
  /// second; owner is never invitable).
  static const List<TeamRole> _inviteRoles = <TeamRole>[
    TeamRole.member,
    TeamRole.admin,
  ];

  /// The mutable working copy of the team's members.
  ///
  /// Seeded once in [initState] from [teamMembers]; the fixture list is
  /// never mutated in place. Remove mutates this list via [setState].
  late List<TeamMember> _members;

  /// The mutable working copy of the team's pending invitations.
  ///
  /// Seeded once in [initState] from [pendingInvitations]; the fixture list
  /// is never mutated in place. Invite/Revoke mutate this list via
  /// [setState].
  late List<TeamInvitation> _pendingInvites;

  /// The invite form's email field value.
  String _inviteEmail = '';

  /// The invite form's selected role, indexing into [_inviteRoles].
  int _inviteRoleIndex = 0;

  @override
  void initState() {
    super.initState();
    _members = teamMembers.toList();
    _pendingInvites = pendingInvitations.toList();
  }

  @override
  Widget build(BuildContext context) {
    final Team currentTeam = teams.first;

    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PageHeader(
            title: trans('uptizm.teams.members_title'),
            subtitle: trans('uptizm.teams.members_description', {
              'name': currentTeam.name,
            }),
            backLabel: trans('nav.dashboard'),
            backFallback: '/',
          ),
          const SizedBox(height: 24),
          _buildInviteCard(currentTeam),
          const SizedBox(height: 24),
          _buildMembersCard(),
          if (_pendingInvites.isNotEmpty) ...[
            const SizedBox(height: 24),
            _buildPendingInvitesCard(),
          ],
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Invite card
  // ---------------------------------------------------------------------------

  /// Builds the "Invite a teammate" [Card]: email [Input], role
  /// [SegmentedControl], and the "Send invite" [Button] (disabled until the
  /// email is non-empty).
  Widget _buildInviteCard(Team currentTeam) {
    return Card(
      title: trans('uptizm.teams.members_invite_header'),
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          WText(
            trans('uptizm.teams.members_invite_hint', {
              'name': currentTeam.name,
            }),
            className: 'text-sm text-fg-muted',
          ),
          WDiv(
            className: 'flex flex-col gap-3 sm:flex-row sm:items-center',
            children: [
              Expanded(
                child: Input(
                  type: InputType.email,
                  value: _inviteEmail,
                  onChanged: (value) => setState(() => _inviteEmail = value),
                  placeholder: trans('uptizm.teams.members_invite_placeholder'),
                ),
              ),
              SegmentedControl<String>(
                options: [for (final TeamRole role in _inviteRoles) role.label],
                selectedIndex: _inviteRoleIndex,
                onChanged: (index) => setState(() => _inviteRoleIndex = index),
              ),
              Button(
                disabled: _inviteEmail.trim().isEmpty,
                onPressed: _sendInvite,
                child: WText(trans('uptizm.teams.members_send_button')),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Adds a [TeamInvitation] for the current invite-form state to the local
  /// pending list, clears the email field, and surfaces a success toast.
  /// Nothing is emailed; this is a mock affordance.
  void _sendInvite() {
    final String email = _inviteEmail.trim();
    if (email.isEmpty) return;

    final TeamInvitation invitation = TeamInvitation(
      id: 'inv-${DateTime.now().microsecondsSinceEpoch}',
      email: email,
      role: _inviteRoles[_inviteRoleIndex],
      // No i18n key covers a relative invite timestamp; composed directly in
      // Dart, mirroring `escalationDelayLabel`'s plain-string precedent.
      invitedAt: 'just now',
    );

    setState(() {
      _pendingInvites = [..._pendingInvites, invitation];
      _inviteEmail = '';
    });

    // Reuses `members_invite_header` as the toast title (the
    // status_page_subscribers_view precedent of reusing the nearest existing
    // copy rather than inventing an uncovered i18n key).
    Magic.success(
      trans('uptizm.teams.members_invite_header'),
      invitation.email,
    );
  }

  // ---------------------------------------------------------------------------
  // Members card
  // ---------------------------------------------------------------------------

  /// Builds the "Members · :count" [Card] holding one row per [_members]
  /// entry.
  Widget _buildMembersCard() {
    return Card(
      title: trans('uptizm.teams.members_list_header', {
        'count': '${_members.length}',
      }),
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: [
          for (final (int index, TeamMember member) in _members.indexed)
            _buildMemberRow(member, isLast: index == _members.length - 1),
        ],
      ),
    );
  }

  /// Builds one member row: initials avatar tile, name + email, a
  /// token-tinted role pill, and a ghost "Remove" [Button] on every row
  /// except the owner/self.
  Widget _buildMemberRow(TeamMember member, {required bool isLast}) {
    return WDiv(
      className: isLast
          ? 'flex flex-row items-center gap-3 px-5 py-3.5'
          : 'flex flex-row items-center gap-3 px-5 py-3.5 border-b border-color-border',
      children: [
        WDiv(
          className:
              'grid size-9 shrink-0 place-items-center rounded-full '
              'bg-surface-container-high',
          child: WText(
            member.initials,
            className: 'text-xs font-semibold text-fg',
          ),
        ),
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText(
                member.name,
                className: 'truncate text-sm font-medium text-fg',
              ),
              WText(member.email, className: 'truncate text-xs text-fg-muted'),
            ],
          ),
        ),
        _buildRolePill(member.role),
        if (!member.isSelf && member.role != TeamRole.owner)
          Button(
            intent: ButtonIntent.ghost,
            size: ButtonSize.sm,
            onPressed: () => _confirmRemove(member),
            child: WText(trans('uptizm.teams.members_remove_button')),
          ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Pending invites card
  // ---------------------------------------------------------------------------

  /// Builds the "Pending invites · :count" [Card], only rendered when
  /// [_pendingInvites] is non-empty.
  Widget _buildPendingInvitesCard() {
    return Card(
      title: trans('uptizm.teams.members_pending_header', {
        'count': '${_pendingInvites.length}',
      }),
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: [
          for (final (int index, TeamInvitation invite)
              in _pendingInvites.indexed)
            _buildPendingInviteRow(
              invite,
              isLast: index == _pendingInvites.length - 1,
            ),
        ],
      ),
    );
  }

  /// Builds one pending-invite row: a dashed envelope tile, the email +
  /// "Invited `<relative time>`" meta, a role pill, and a ghost "Revoke"
  /// [Button].
  Widget _buildPendingInviteRow(TeamInvitation invite, {required bool isLast}) {
    return WDiv(
      className: isLast
          ? 'flex flex-row items-center gap-3 px-5 py-3.5'
          : 'flex flex-row items-center gap-3 px-5 py-3.5 border-b border-color-border',
      children: [
        WDiv(
          className:
              'grid size-9 shrink-0 place-items-center rounded-full '
              'border border-dashed border-color-border',
          child: WIcon(
            Icons.mail_outline,
            className: 'text-[16px] text-fg-muted',
          ),
        ),
        Expanded(
          child: WDiv(
            className: 'flex flex-col min-w-0',
            children: [
              WText(
                invite.email,
                className: 'truncate text-sm font-medium text-fg',
              ),
              WText(
                // No i18n key covers this row's "Invited <relative time>"
                // meta; composed directly in Dart from the React copy
                // (`Invited · {inv.sentAt}`), mirroring
                // `escalationDelayLabel`'s plain-string precedent.
                'Invited · ${invite.invitedAt}',
                className: 'truncate text-xs text-fg-muted',
              ),
            ],
          ),
        ),
        _buildRolePill(invite.role),
        Button(
          intent: ButtonIntent.ghost,
          size: ButtonSize.sm,
          onPressed: () => _confirmRevoke(invite),
          child: WText(trans('uptizm.teams.members_revoke_button')),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Role pill
  // ---------------------------------------------------------------------------

  /// Builds a small token-tinted role pill: a rounded [WDiv] + [WText]
  /// carrying [role]'s label. NOT [StatusBadge], which takes a monitoring
  /// [StatusKey] rather than a [TeamRole]. Mirrors the React `Badge`
  /// component's `tone="primary"` (owner) / `tone="outline"` (admin, member).
  Widget _buildRolePill(TeamRole role) {
    final bool isOwner = role == TeamRole.owner;

    return WDiv(
      className: isOwner
          ? 'flex flex-row items-center rounded-full '
                'bg-primary-container px-2.5 py-0.5'
          : 'flex flex-row items-center rounded-full border '
                'border-color-border px-2.5 py-0.5',
      child: WText(
        role.label,
        className: isOwner
            ? 'text-xs font-medium text-fg'
            : 'text-xs font-medium text-fg-muted',
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Remove / Revoke confirmation
  // ---------------------------------------------------------------------------

  /// Opens the Remove [MagicStarterConfirmDialog]; on confirm, removes
  /// [member] from the local list and surfaces a success toast. Mirrors
  /// `sessions_settings_view.dart`'s `_confirmSignOut` exactly.
  Future<void> _confirmRemove(TeamMember member) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.teams.members_remove_confirm_title', {
        'name': member.name,
      }),
      description: trans('uptizm.teams.members_remove_confirm_description', {
        'name': member.name,
        'team': teams.first.name,
      }),
      confirmLabel: trans('uptizm.teams.members_remove_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open (mirrors sessions_settings_view's
    // precedent).
    if (!mounted) return;

    setState(() => _members.remove(member));
    // Reuses the confirm title as the toast title (the
    // status_page_subscribers_view precedent).
    Magic.success(
      trans('uptizm.teams.members_remove_confirm_title', {'name': member.name}),
      member.email,
    );
  }

  /// Opens the Revoke [MagicStarterConfirmDialog]; on confirm, removes
  /// [invite] from the local pending list and surfaces a success toast.
  /// Mirrors `sessions_settings_view.dart`'s `_confirmSignOut` exactly.
  Future<void> _confirmRevoke(TeamInvitation invite) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.teams.members_revoke_confirm_title'),
      description: trans('uptizm.teams.members_revoke_confirm_description', {
        'email': invite.email,
      }),
      confirmLabel: trans('uptizm.teams.members_revoke_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open (mirrors sessions_settings_view's
    // precedent).
    if (!mounted) return;

    setState(() => _pendingInvites.remove(invite));
    // Reuses the confirm title as the toast title (the
    // status_page_subscribers_view precedent).
    Magic.success(
      trans('uptizm.teams.members_revoke_confirm_title'),
      invite.email,
    );
  }
}
