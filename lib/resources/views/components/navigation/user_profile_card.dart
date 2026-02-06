import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../../../../app/models/user.dart';

/// User Profile Card
///
/// Reusable user profile component for navigation.
/// Used in both sidebar (desktop) and drawer (mobile).
class UserProfileCard extends StatelessWidget {
  final bool showLogout;
  final VoidCallback? onLogout;
  final bool compact;
  final bool onlyAvatar;

  const UserProfileCard({
    super.key,
    this.showLogout = false,
    this.onLogout,
    this.compact = false,
    this.onlyAvatar = false,
  });

  @override
  Widget build(BuildContext context) {
    return WPopover(
      alignment: onlyAvatar
          ? PopoverAlignment.bottomRight
          : PopoverAlignment.bottomLeft,
      className:
          '''
        w-72
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl
        shadow-lg
        ${onlyAvatar ? 'mt-2' : 'mb-2 ml-2'}
      ''',
      triggerBuilder: _buildTrigger,
      contentBuilder: _buildMenu,
    );
  }

  Widget _buildTrigger(BuildContext context, bool isOpen, bool isHovering) {
    if (onlyAvatar) {
      return WDiv(
        states: {if (isOpen) 'active', if (isHovering) 'hover'},
        className: '''
          w-8 h-8 
          rounded-full 
          bg-gradient-to-tr from-primary to-gray-200 
          flex items-center justify-center
          cursor-pointer
          transform transition-transform duration-150
          hover:scale-105 active:scale-95
          shadow-sm hover:shadow-md
        ''',
        child: WText(
          (User.current.name?.isNotEmpty == true)
              ? User.current.name!.substring(0, 1).toUpperCase()
              : 'U',
          className: 'text-xs font-bold text-white',
        ),
      );
    }

    return WDiv(
      className: 'p-4 border-t border-gray-200 dark:border-gray-700',
      child: WDiv(
        states: {
          if (compact) 'compact',
          if (isOpen) 'active',
          if (isHovering) 'hover',
        },
        className: '''
          p-3 compact:p-2 
          rounded-lg 
          bg-gray-100 dark:bg-gray-800 
          hover:bg-gray-200 dark:hover:bg-gray-700
          active:bg-gray-200 dark:active:bg-gray-700
          flex items-center gap-3 transition-colors
        ''',
        children: [
          // Avatar
          WDiv(
            states: compact ? {'compact'} : {},
            className: '''
              w-10 h-10 compact:w-8 compact:h-8 
              rounded-full 
              bg-gradient-to-tr from-primary to-gray-200 
              flex items-center justify-center
            ''',
            child: WText(
              (User.current.name ?? '').substring(0, 1),
              states: compact ? {'compact'} : {},
              className: 'text-sm compact:text-xs font-bold text-white',
            ),
          ),
          // User Info
          WDiv(
            className: 'flex-1 flex flex-col min-w-0',
            children: [
              WText(
                User.current.name ?? '',
                className:
                    'text-sm font-medium text-gray-900 dark:text-white truncate',
              ),
              WText(
                User.current.email ?? '',
                className: 'text-xs text-gray-500 dark:text-gray-400 truncate',
              ),
            ],
          ),
          // Unfold Icon
          if (!compact)
            WIcon(
              isOpen ? Icons.unfold_less : Icons.unfold_more,
              className: 'text-xl text-gray-400 dark:text-gray-500',
            ),
        ],
      ),
    );
  }

  Widget _buildMenu(BuildContext context, VoidCallback close) {
    return WDiv(
      className: 'py-2',
      children: [
        // Profile Header
        WDiv(
          className:
              'w-full px-4 py-3 border-b border-gray-100 dark:border-gray-700',
          children: [
            WText(
              trans('auth.signed_in_as').toUpperCase(),
              className: '''
                text-[10px] font-bold tracking-widest
                text-gray-400 dark:text-gray-500
                mb-1
              ''',
            ),
            WText(
              User.current.name ?? '',
              className: '''
                text-sm font-semibold
                text-gray-900 dark:text-white
                truncate
              ''',
            ),
            if (User.current.email?.isNotEmpty == true) ...[
              const WSpacer(className: 'h-0.5'),
              WText(
                User.current.email ?? '',
                className: '''
                  text-xs font-medium
                  text-gray-500 dark:text-gray-400
                  truncate
                ''',
              ),
            ],
          ],
        ),

        const WSpacer(className: 'h-1'),

        // Menu Items
        _buildMenuItem(
          icon: Icons.person_outline,
          label: trans('profile.my_profile'),
          onTap: () {
            close();
            MagicRoute.to('/settings/profile');
          },
        ),
        _buildMenuItem(
          icon: Icons.notifications_outlined,
          label: trans('notifications.settings'),
          onTap: () {
            close();
            MagicRoute.to('/settings/notifications');
          },
        ),

        // Divider
        WDiv(
          className: 'h-[1px] w-full bg-gray-200 dark:bg-gray-700 my-1',
          child: const SizedBox.shrink(),
        ),

        // Logout
        _buildMenuItem(
          icon: Icons.logout,
          label: trans('auth.logout'),
          onTap: () {
            close();
            onLogout?.call();
          },
          isDanger: true,
        ),

        const WSpacer(className: 'h-1'),
      ],
    );
  }

  Widget _buildMenuItem({
    required IconData icon,
    required String label,
    required VoidCallback onTap,
    bool isDanger = false,
  }) {
    return WAnchor(
      onTap: onTap,
      child: WDiv(
        states: {if (isDanger) 'danger'},
        className: '''
          mx-2 px-3 py-2.5 w-full
          rounded-lg
          hover:bg-gray-50 dark:hover:bg-gray-700/50
          active:bg-gray-100 dark:active:bg-gray-700
          flex items-center gap-3
          cursor-pointer
          transition-colors duration-150
        ''',
        children: [
          WDiv(
            states: {if (isDanger) 'danger'},
            className: '''
              w-8 h-8
              rounded-lg
              bg-gray-100 dark:bg-gray-700
              hover:bg-gray-200 dark:hover:bg-gray-600
              danger:bg-red-50 dark:danger:bg-red-900/20
              flex items-center justify-center
              transition-colors duration-150
            ''',
            child: WIcon(
              icon,
              states: {if (isDanger) 'danger'},
              className: '''
                text-lg
                text-gray-600 dark:text-gray-400
                danger:text-red-600 dark:danger:text-red-500
              ''',
            ),
          ),
          WText(
            label,
            states: {if (isDanger) 'danger'},
            className: '''
              text-sm font-medium
              text-gray-900 dark:text-gray-100
              danger:text-red-600 dark:danger:text-red-500
            ''',
          ),
        ],
      ),
    );
  }
}
