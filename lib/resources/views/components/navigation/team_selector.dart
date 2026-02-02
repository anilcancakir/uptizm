import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../../app/models/team.dart';
import '../../../../app/models/user.dart';

/// Team Selector
///
/// Reusable team/organization selector with dropdown.
/// Used in both sidebar (desktop) and drawer (mobile).
class TeamSelector extends StatelessWidget {
  final bool compact;
  final void Function(Team)? onTeamSelect;
  final VoidCallback? onCreateTeam;
  final VoidCallback? onTeamSettings;

  const TeamSelector({
    super.key,
    this.compact = false,
    this.onTeamSelect,
    this.onCreateTeam,
    this.onTeamSettings,
  });

  @override
  Widget build(BuildContext context) {
    final user = User.current;
    final currentTeam = user.currentTeam;

    // Use current team or first available if null (safety fallback)
    final activeTeam =
        currentTeam ?? (user.allTeams.isNotEmpty ? user.allTeams.first : null);

    if (activeTeam == null) return const SizedBox();

    // Use allTeams from user model
    final List<Team> displayTeams = user.allTeams;

    return WPopover(
      alignment: PopoverAlignment.bottomLeft,
      className: '''
        w-64 
        bg-white dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
        rounded-xl shadow-xl
      ''',
      triggerBuilder: (context, isOpen, isHovering) =>
          _buildTrigger(context, isOpen, isHovering, activeTeam),
      contentBuilder: (context, close) =>
          _buildContent(context, close, displayTeams, activeTeam),
    );
  }

  Widget _buildTrigger(
    BuildContext context,
    bool isOpen,
    bool isHovering,
    Team team,
  ) {
    final Set<String> states = {
      if (compact) 'compact',
      if (isOpen) 'active',
      if (isHovering) 'hover',
    };

    return WDiv(
      states: states,
      className: '''
        p-3 compact:p-2 
        rounded-xl compact:rounded-lg 
        w-full
        bg-gray-100 dark:bg-gray-800 compact:bg-transparent dark:compact:bg-transparent
        hover:bg-gray-200 dark:hover:bg-gray-700
        compact:hover:bg-gray-100 dark:compact:hover:bg-gray-800
        active:bg-gray-200 dark:active:bg-gray-700
        flex items-center gap-3 duration-150
      ''',
      children: [
        // Team Icon
        WImage(
          src: team.profilePhotoUrl ?? '',
          className: 'w-10 h-10 compact:w-8 compact:h-10 rounded-lg',
          errorBuilder: (context, error, stackTrace) {
            return WDiv(
              className: '''
                w-10 h-10 compact:w-8 compact:h-10 rounded-lg 
                bg-gray-200 dark:bg-gray-700
                flex items-center justify-center
                border border-gray-300 dark:border-gray-600
              ''',
              child: WIcon(
                Icons.business,
                className: 'text-gray-500 dark:text-gray-400',
              ),
            );
          },
        ),
        // Team Name
        WDiv(
          className: 'flex-1 flex flex-col min-w-0',
          children: [
            WText(
              team.name ?? '',
              className:
                  'text-sm font-bold text-gray-900 dark:text-white truncate',
            ),
            WText(
              team.isPersonalTeam
                  ? trans('teams.personal')
                  : trans('teams.team'),
              className: 'text-xs text-gray-500 dark:text-gray-400 truncate',
            ),
          ],
        ),
        WIcon(
          isOpen ? Icons.unfold_less : Icons.unfold_more,
          states: states,
          className:
              'text-xl compact:text-2xl text-gray-400 dark:text-gray-500',
        ),
      ],
    );
  }

  Widget _buildContent(
    BuildContext context,
    VoidCallback close,
    List<Team> teams,
    Team currentTeam,
  ) {
    return WDiv(
      // mainAxisSize: MainAxisSize.min,
      // crossAxisAlignment: CrossAxisAlignment.stretch,
      className: 'flex flex-col axis-min justify-stretch overflow-y-auto',
      children: [
        // Header
        WDiv(
          className: '''
            px-4 py-3 w-full
            border-b border-gray-200 dark:border-gray-700
          ''',
          child: WText(
            trans('team.switch_team'),
            className:
                'text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider',
          ),
        ),

        // Team list
        WDiv(
          className: 'flex flex-col w-full',
          children: teams
              .map((team) => _buildTeamItem(context, team, currentTeam, close))
              .toList(),
        ),

        // Team Management
        if (onTeamSettings != null || onCreateTeam != null) ...[
          WDiv(
            className: 'h-[1px] w-full bg-gray-200 dark:bg-gray-700 my-1',
            child: const SizedBox.shrink(),
          ),
          // Team Settings
          if (onTeamSettings != null)
            WAnchor(
              onTap: () {
                close();
                onTeamSettings?.call();
              },
              child: WDiv(
                className: '''
                px-4 py-3 w-full
                hover:bg-gray-50 dark:hover:bg-gray-700
                flex items-center gap-3
              ''',
                children: [
                  WDiv(
                    className: '''
                      w-8 h-8 rounded-lg 
                      border border-gray-200 dark:border-gray-600
                      flex items-center justify-center
                    ''',
                    child: WIcon(
                      Icons.settings_outlined,
                      className: 'text-lg text-gray-400 dark:text-gray-500',
                    ),
                  ),
                  WText(
                    trans('team_settings.title'),
                    className:
                        'text-sm font-medium text-gray-700 dark:text-gray-300',
                  ),
                ],
              ),
            ),

          // Create Team
          if (onCreateTeam != null)
            WAnchor(
              onTap: () {
                close();
                onCreateTeam?.call();
              },
              child: WDiv(
                className: '''
                px-4 py-3 w-full
                hover:bg-gray-50 dark:hover:bg-gray-700
                flex items-center gap-3
              ''',
                children: [
                  WDiv(
                    className: '''
                    w-8 h-8 rounded-lg 
                    border border-gray-200 dark:border-gray-600
                    flex items-center justify-center
                  ''',
                    child: WIcon(
                      Icons.add,
                      className: 'text-lg text-gray-400 dark:text-gray-500',
                    ),
                  ),
                  WText(
                    trans('team.create_team'),
                    className:
                        'text-sm font-medium text-gray-700 dark:text-gray-300',
                  ),
                ],
              ),
            ),
        ],
      ],
    );
  }

  Widget _buildTeamItem(
    BuildContext context,
    Team team,
    Team currentTeam,
    VoidCallback close,
  ) {
    final isCurrent = team.id == currentTeam.id;
    return WAnchor(
      onTap: () {
        close();
        onTeamSelect?.call(team);
      },
      child: WDiv(
        states: isCurrent ? {'selected'} : {},
        className: '''
          px-4 py-3 
          hover:bg-gray-50 dark:hover:bg-gray-700
          selected:bg-primary/5 dark:selected:bg-primary/10
          flex items-center gap-3
        ''',
        children: [
          // Team icon
          WDiv(
            className: '''
              w-8 h-8 rounded-lg 
              bg-gradient-to-br from-white to-gray-200 
              dark:from-gray-700 dark:to-gray-800
              flex items-center justify-center
              border border-gray-200 dark:border-gray-600
            ''',
            child: WIcon(Icons.business, className: 'text-base text-primary'),
          ),
          // Team info
          Expanded(
            child: WDiv(
              className: 'flex flex-col min-w-0',
              children: [
                WText(
                  team.name ?? '',
                  states: isCurrent ? {'selected'} : {},
                  className: '''
                    text-sm font-medium text-gray-700 dark:text-gray-200 
                    selected:font-bold selected:text-gray-900 dark:selected:text-white
                    truncate
                  ''',
                ),
                WText(
                  team.isPersonalTeam ? 'Personal' : 'Team',
                  className: 'text-xs text-gray-500 dark:text-gray-400',
                ),
              ],
            ),
          ),
          // Check mark
          if (isCurrent)
            WIcon(Icons.check_circle, className: 'text-lg text-primary'),
        ],
      ),
    );
  }
}
