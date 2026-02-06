import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Settings Card Component
///
/// A styled card container with header section for settings pages.
/// Uses Wind UI widgets matching the New Monitor mockup design.
class SettingsCard extends StatelessWidget {
  final String title;
  final IconData? icon;
  final Widget child;
  final Widget? trailing;

  const SettingsCard({
    super.key,
    required this.title,
    required this.child,
    this.icon,
    this.trailing,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        rounded-xl shadow-sm
        border border-gray-200 dark:border-gray-700
        w-full
      ''',
      child: WDiv(
        className: 'flex flex-col',
        children: [
          // Header
          WDiv(
            className: '''
              px-6 py-4
              border-b border-gray-100 dark:border-gray-700
              bg-gray-50/50 dark:bg-gray-900/30
              flex items-center justify-between
            ''',
            children: [
              WDiv(
                className: 'flex items-center gap-2',
                children: [
                  if (icon != null)
                    WIcon(icon!, className: 'text-gray-400 dark:text-gray-500'),
                  WText(
                    title.toUpperCase(),
                    className: '''
                      text-xs font-semibold
                      text-slate-900 dark:text-gray-200
                      uppercase tracking-wide
                    ''',
                  ),
                ],
              ),
              ?trailing,
            ],
          ),
          // Content
          WDiv(className: 'p-6', child: child),
        ],
      ),
    );
  }
}
