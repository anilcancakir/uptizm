import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// App Page Header
///
/// Reusable page header component with responsive layout.
///
/// Features:
/// - Optional leading widget (back button, menu icon, etc.)
/// - Title and optional subtitle
/// - Optional trailing actions (buttons)
/// - Responsive: vertical on mobile, horizontal on desktop
/// - Full dark mode support
///
/// Example:
/// ```dart
/// AppPageHeader(
///   leading: WButton(
///     onTap: () => MagicRoute.back(),
///     child: WIcon(Icons.arrow_back),
///   ),
///   title: trans('monitors.title'),
///   subtitle: trans('monitors.subtitle'),
///   actions: [
///     WButton(
///       onTap: () => MagicRoute.to('/monitors/create'),
///       className: 'px-4 py-2 rounded-lg bg-primary hover:bg-green-600 text-white font-medium',
///       child: WDiv(
///         className: 'flex flex-row items-center gap-2',
///         children: [
///           WIcon(Icons.add, className: 'text-lg text-white'),
///           WText(trans('monitors.add')),
///         ],
///       ),
///     ),
///   ],
/// )
/// ```
class AppPageHeader extends StatelessWidget {
  /// Title text (required)
  final String title;

  /// Optional subtitle text
  final String? subtitle;

  /// Optional leading widget (back button, menu icon, etc.)
  final Widget? leading;

  /// Optional trailing action widgets (buttons, etc.)
  final List<Widget>? actions;

  const AppPageHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.leading,
    this.actions,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        w-full
        flex flex-col sm:flex-row items-start sm:items-center sm:justify-between
        gap-4 p-4 lg:p-6
        border-b border-gray-200 dark:border-gray-700
      ''',
      children: [
        // Leading + Title/Subtitle
        WDiv(
          className: 'flex flex-row items-center gap-3 sm:flex-1 min-w-0',
          children: [
            // Leading widget (optional)
            if (leading != null) leading!,

            // Title and subtitle
            WDiv(
              className: 'flex flex-col gap-1 flex-1 min-w-0',
              children: [
                WText(
                  title,
                  className:
                      'text-2xl font-bold text-gray-900 dark:text-white truncate',
                ),
                if (subtitle != null)
                  WText(
                    subtitle!,
                    className:
                        'text-sm text-gray-600 dark:text-gray-400 truncate',
                  ),
              ],
            ),
          ],
        ),

        // Trailing actions (optional)
        if (actions != null && actions!.isNotEmpty)
          WDiv(
            className: 'flex flex-row items-center gap-2',
            children: actions!,
          ),
      ],
    );
  }
}
