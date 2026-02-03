import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../../app/controllers/team_controller.dart';
import 'team_selector.dart';
import 'navigation_list.dart';

/// App Sidebar
///
/// Fixed sidebar for web/desktop layout.
/// Contains organization selector, navigation, and user profile.
/// Supports light/dark mode with new Uptizm design system.
class AppSidebar extends StatelessWidget {
  final String currentPath;

  const AppSidebar({super.key, required this.currentPath});

  @override
  Widget build(BuildContext context) {

    return WDiv(
      className: '''
        w-64 h-full flex flex-col 
        bg-white dark:bg-gray-900 
        border-r border-gray-200 dark:border-gray-700
      ''',
      children: [
        // Organization Selector
        WDiv(
          className: 'px-4 py-2 border-b border-gray-200 dark:border-gray-700',
          child: TeamSelector(
            compact: true,
            onTeamSelect: (team) => TeamController.instance.switchTeam(team),
            onTeamSettings: () => MagicRoute.to('/teams/settings'),
            onCreateTeam: () => MagicRoute.to('/teams/create'),
          ),
        ),

        // Navigation
        WDiv(
          className: 'flex-1 w-64 overflow-y-auto',
          child: NavigationList(currentPath: currentPath, compact: true),
        ),
      ],
    );
  }
}
