import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/team_controller.dart';
import '../../../app/enums/team_role.dart';
import '../../../app/models/team_invitation.dart';
import '../../../app/models/user.dart';
import '../components/app_card.dart';

/// Team Members View
///
/// Page for managing team members and invitations.
class TeamMembersView extends MagicStatefulView<TeamController> {
  const TeamMembersView({super.key});

  @override
  State<TeamMembersView> createState() => _TeamMembersViewState();
}

class _TeamMembersViewState
    extends MagicStatefulViewState<TeamController, TeamMembersView> {
  @override
  void onInit() {
    super.onInit();
    controller.loadSettingsData();
  }

  @override
  Widget build(BuildContext context) {
    final team = controller.currentTeam;

    return WDiv(
      className: 'overflow-y-auto flex flex-col gap-6 p-4 lg:p-6',
      scrollPrimary: true,
      children: [
          // Header
          WDiv(
            className:
                'flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between',
            children: [
              WDiv(
                className: 'flex flex-col',
                children: [
                  WText(
                    trans('teams.team_members'),
                    className:
                        'text-2xl font-bold text-gray-900 dark:text-white',
                  ),
                  const WSpacer(className: 'h-1'),
                  WText(
                    trans('teams.team_members_desc'),
                    className: 'text-sm text-gray-600 dark:text-gray-400',
                  ),
                ],
              ),
              MagicCan(
                ability: 'manage-team-members',
                arguments: team,
                child: WButton(
                  onTap: _showInviteDialog,
                  className: '''
                    px-4 py-2 rounded-lg
                    bg-primary hover:bg-green-600
                    text-white
                    text-sm font-medium
                  ''',
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(
                        Icons.person_add_outlined,
                        size: 16,
                        color: Colors.white,
                      ),
                      const WSpacer(className: 'w-1.5'),
                      WText(trans('teams.invite_member')),
                    ],
                  ),
                ),
              ),
            ],
          ),

          // Members List
          _buildMembersSection(),

          // Pending Invitations
          _buildInvitationsSection(),
        ],
    );
  }

  Widget _buildMembersSection() {
    return ValueListenableBuilder<List<User>>(
      valueListenable: controller.members,
      builder: (context, members, _) {
        return AppCard(
          title: trans('teams.team_members'),
          body: WDiv(
            className: 'flex flex-col gap-3',
            children: [
              if (members.isEmpty)
                WDiv(
                  className: 'py-8 text-center',
                  child: WText(
                    trans('teams.no_members'),
                    className: 'text-sm text-gray-500 dark:text-gray-400',
                  ),
                )
              else
                ...members.map((member) => _buildMemberRow(member)),
            ],
          ),
        );
      },
    );
  }

  Widget _buildMemberRow(User member) {
    final team = controller.currentTeam;
    final isOwnerRow = team?.ownerId == member.id;

    return WDiv(
      className: '''
        flex flex-row items-center justify-between
        p-3 rounded-lg
        bg-gray-50 dark:bg-gray-800/50
        border border-gray-100 dark:border-gray-700
      ''',
      children: [
        // Left: Avatar + Info
        WDiv(
          className: 'flex-1 flex flex-row items-center gap-3',
          children: [
            // Avatar
            WImage(
              src: member.profilePhotoUrl ?? '',
              className: 'w-10 h-10 rounded-full',
              errorBuilder: (context, error, stackTrace) => WDiv(
                className: '''
                  w-10 h-10 rounded-full
                  bg-primary/10
                  flex items-center justify-center
                ''',
                child: WText(
                  member.name?.substring(0, 1).toUpperCase() ?? 'U',
                  className: 'font-bold text-primary',
                ),
              ),
            ),
            // Name + Email
            WDiv(
              className: 'flex-1 flex flex-col items-start min-w-0',
              children: [
                WText(
                  member.name ?? '',
                  className:
                      'text-sm font-medium text-gray-900 dark:text-white truncate',
                ),
                WText(
                  member.email ?? '',
                  className:
                      'text-xs text-gray-500 dark:text-gray-400 truncate',
                ),
              ],
            ),
          ],
        ),
        // Right: Role badge + Actions
        WDiv(
          className: 'flex flex-row items-center gap-2',
          children: [
            // Role badge
            WDiv(
              className:
                  '''
                px-2.5 py-1 rounded-full
                ${isOwnerRow ? 'bg-primary/10 text-primary' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'}
                text-xs font-medium
              ''',
              child: WText(TeamRole.label(member.teamRole ?? 'member')),
            ),
            // Actions (only for non-owners, and only if current user can manage)
            if (!isOwnerRow)
              MagicCan(
                ability: 'manage-team-members',
                arguments: team,
                child: WDiv(
                  className: 'flex flex-row items-center gap-1',
                  children: [
                    // Change Role Button
                    WButton(
                      onTap: () => _showChangeRoleDialog(member),
                      className: '''
                        p-1.5 rounded-lg
                        hover:bg-gray-200 dark:hover:bg-gray-700
                        text-gray-500 dark:text-gray-400
                        hover:text-primary
                        duration-150
                      ''',
                      child: WIcon(Icons.swap_horiz, className: 'text-lg'),
                    ),
                    // Remove Button
                    WButton(
                      onTap: () => _confirmRemoveMember(member),
                      className: '''
                        p-1.5 rounded-lg
                        hover:bg-red-50 dark:hover:bg-red-900/20
                        text-gray-500 dark:text-gray-400
                        hover:text-red-500
                        duration-150
                      ''',
                      child: WIcon(
                        Icons.person_remove_outlined,
                        className: 'text-lg',
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ],
    );
  }

  Widget _buildInvitationsSection() {
    return ValueListenableBuilder<List<TeamInvitation>>(
      valueListenable: controller.invitations,
      builder: (context, invitations, _) {
        if (invitations.isEmpty) return const SizedBox.shrink();

        return AppCard(
          title: trans('teams.pending_invitations'),
          body: WDiv(
            className: 'flex flex-col gap-3',
            children: [
              ...invitations.map(
                (invitation) => WDiv(
                  className: '''
                    flex flex-row items-center justify-between
                    p-3 rounded-lg
                    bg-orange-50 dark:bg-orange-900/10
                    border border-orange-100 dark:border-orange-900/20
                  ''',
                  children: [
                    // Left: Email + Role
                    WDiv(
                      className: 'flex-1 flex flex-row items-center gap-3',
                      children: [
                        // Mail icon
                        WDiv(
                          className: '''
                            w-10 h-10 rounded-full
                            bg-orange-100 dark:bg-orange-900/30
                            flex items-center justify-center
                          ''',
                          child: WIcon(
                            Icons.mail_outline,
                            className:
                                'text-lg text-orange-600 dark:text-orange-400',
                          ),
                        ),
                        WDiv(
                          className: 'flex-1 flex flex-col items-start min-w-0',
                          children: [
                            WText(
                              invitation.email ?? '',
                              className:
                                  'text-sm font-medium text-gray-900 dark:text-white truncate',
                            ),
                            WText(
                              '${trans('teams.invited_as')}: ${TeamRole.label(invitation.role ?? 'member')}',
                              className:
                                  'text-xs text-orange-600 dark:text-orange-400 truncate',
                            ),
                          ],
                        ),
                      ],
                    ),
                    // Right: Cancel button
                    MagicCan(
                      ability: 'manage-team-invitations',
                      arguments: controller.currentTeam,
                      child: WButton(
                        onTap: () => _confirmCancelInvitation(invitation),
                        className: '''
                          px-3 py-1.5 rounded-lg
                          bg-white dark:bg-gray-800
                          border border-gray-300 dark:border-gray-600
                          text-sm font-medium
                          text-red-500 hover:text-red-700
                          hover:bg-red-50 dark:hover:bg-red-900/20
                          duration-150
                        ''',
                        child: WText(trans('common.revoke')),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  void _showInviteDialog() {
    final inviteForm = MagicFormData({'email': '', 'role': 'member'});

    Magic.dialog(
      MagicForm(
        formData: inviteForm,
        child: WDiv(
          className: 'flex flex-col gap-4',
          children: [
            // Title
            WText(
              trans('teams.invite_member'),
              className: 'text-lg font-semibold text-gray-900 dark:text-white',
            ),
            // Email Input
            WFormInput(
              label: trans('attributes.email'),
              controller: inviteForm['email'],
              type: InputType.email,
              validator: rules([Required(), Email()], field: 'email'),
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-1',
              className: '''
                w-full bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-3
                text-gray-900 dark:text-white text-sm
                focus:border-primary focus:ring-2 focus:ring-primary/20
                duration-150
              ''',
            ),
            // Role Select
            WFormSelect<String>(
              label: trans('attributes.role'),
              value: 'member',
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-1',
              className: '''
                w-full bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-3
                text-gray-900 dark:text-white text-sm
                focus:border-primary focus:ring-2 focus:ring-primary/20
                duration-150
              ''',
              menuClassName: '''
                bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700
              ''',
              options: TeamRole.selectOptions,
              onChange: (v) => inviteForm.set('role', v ?? 'member'),
            ),
            // Actions
            WDiv(
              className: 'flex flex-row justify-end gap-2 mt-2 w-full',
              children: [
                WButton(
                  onTap: () => Magic.closeDialog(),
                  className: '''
                    px-4 py-2 rounded-lg
                    bg-gray-200 dark:bg-gray-700
                    text-gray-700 dark:text-gray-200
                    hover:bg-gray-300 dark:hover:bg-gray-600
                    text-sm font-medium
                  ''',
                  child: WText(trans('common.cancel')),
                ),
                WButton(
                  onTap: () async {
                    if (inviteForm.validate()) {
                      final success = await controller.doInvite(
                        email: inviteForm.get('email'),
                        role: inviteForm.get('role'),
                      );
                      if (success) Magic.closeDialog();
                    }
                  },
                  className: '''
                    px-4 py-2 rounded-lg
                    bg-primary hover:bg-green-600
                    text-white
                    text-sm font-medium
                  ''',
                  child: WText(trans('teams.send_invite')),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  void _showChangeRoleDialog(User member) {
    String selectedRole = member.teamRole ?? 'member';

    Magic.dialog(
      StatefulBuilder(
        builder: (context, setDialogState) => WDiv(
          className: 'flex flex-col gap-4',
          children: [
            WText(
              trans('teams.change_role'),
              className: 'text-lg font-semibold text-gray-900 dark:text-white',
            ),
            WText(
              '${member.name} (${member.email})',
              className: 'text-sm text-gray-500 dark:text-gray-400',
            ),
            WFormSelect<String>(
              label: trans('attributes.role'),
              value: selectedRole,
              labelClassName:
                  'text-sm font-medium text-gray-700 dark:text-gray-300 mb-1',
              className: '''
                w-full bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-3
                text-gray-900 dark:text-white text-sm
                focus:border-primary focus:ring-2 focus:ring-primary/20
                duration-150
              ''',
              menuClassName: '''
                bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700
              ''',
              options: TeamRole.selectOptions,
              onChange: (v) {
                setDialogState(() {
                  selectedRole = v ?? 'member';
                });
              },
            ),
            WDiv(
              className: 'flex flex-row justify-end gap-2 mt-2 w-full',
              children: [
                WButton(
                  onTap: () => Magic.closeDialog(),
                  className: '''
                    px-4 py-2 rounded-lg
                    bg-gray-200 dark:bg-gray-700
                    text-gray-700 dark:text-gray-200
                    hover:bg-gray-300 dark:hover:bg-gray-600
                    text-sm font-medium
                  ''',
                  child: WText(trans('common.cancel')),
                ),
                WButton(
                  onTap: () async {
                    final success = await controller.updateRole(
                      member,
                      selectedRole,
                    );
                    if (success) Magic.closeDialog();
                  },
                  className: '''
                    px-4 py-2 rounded-lg
                    bg-primary hover:bg-green-600
                    text-white
                    text-sm font-medium
                  ''',
                  child: WText(trans('common.save')),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _confirmRemoveMember(User member) async {
    final confirmed = await Magic.confirm(
      title: trans('teams.remove_member'),
      message: trans('teams.remove_member_confirm'),
      confirmText: trans('common.remove'),
      isDangerous: true,
    );
    if (confirmed == true) {
      controller.removeMember(member);
    }
  }

  Future<void> _confirmCancelInvitation(TeamInvitation invitation) async {
    final confirmed = await Magic.confirm(
      title: trans('teams.cancel_invitation'),
      message: trans('teams.cancel_invitation_confirm'),
      confirmText: trans('common.revoke'),
      isDangerous: true,
    );
    if (confirmed == true) {
      controller.cancelInvitation(invitation);
    }
  }
}
