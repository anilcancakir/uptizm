import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Navigation Item
///
/// Reusable navigation item component.
/// Used in sidebar, drawer, and bottom navigation.
class NavItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final String path;
  final bool isActive;
  final VoidCallback? onTap;
  final bool compact;

  const NavItem({
    super.key,
    required this.icon,
    required this.label,
    required this.path,
    this.isActive = false,
    this.onTap,
    this.compact = false,
  });

  @override
  Widget build(BuildContext context) {
    final Set<String> states = {if (isActive) 'active', if (compact) 'compact'};

    return WAnchor(
      onTap: onTap ?? () => MagicRoute.to(path),
      child: WDiv(
        states: states,
        className: '''
          mx-4 px-3 py-3 compact:py-2.5 
          rounded-lg 
          flex items-center gap-3 duration-150
          text-base compact:text-sm font-medium 
          text-gray-600 dark:text-gray-400 
          active:text-primary active:bg-primary/10
          hover:bg-gray-100 dark:hover:bg-gray-800
          w-full
        ''',
        children: [
          WIcon(icon, className: 'text-2xl'),
          WText(label),
        ],
      ),
    );
  }
}

/// Navigation Section Header
///
/// Section header for grouping navigation items.
class NavSectionHeader extends StatelessWidget {
  final String title;

  const NavSectionHeader({super.key, required this.title});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'px-4 pb-2',
      child: WText(
        title.toUpperCase(),
        className:
            'text-xs font-semibold text-gray-500 dark:text-gray-400 tracking-wider',
      ),
    );
  }
}

/// Navigation Divider
///
/// Horizontal divider for separating navigation groups.
class NavDivider extends StatelessWidget {
  const NavDivider({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'my-4 mx-4 h-[1px] bg-gray-200 dark:bg-gray-700 w-full',
      child: const SizedBox.shrink(),
    );
  }
}
