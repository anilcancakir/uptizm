import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Stat Card
///
/// Dashboard stat card showing a metric label, value, and optional icon.
/// Used in the 2x2 (mobile) or 4-column (desktop) stat grid.
class StatCard extends StatelessWidget {
  final String label;
  final String value;
  final IconData? icon;
  final Color? valueColor;
  final String? subtitle;
  final VoidCallback? onTap;

  const StatCard({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.valueColor,
    this.subtitle,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final content = WDiv(
      className:
          '''
        flex flex-col p-5
        bg-white dark:bg-gray-800
        rounded-2xl
        border border-gray-100 dark:border-gray-700
        w-full
        ${onTap != null ? 'hover:border-primary/30 cursor-pointer' : ''}
      ''',
      children: [
        // Header row: label + icon
        WDiv(
          className: 'flex flex-row items-center justify-between w-full mb-3',
          children: [
            WText(
              label.toUpperCase(),
              className:
                  'text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400',
            ),
            if (icon != null)
              WDiv(
                className:
                    'w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center',
                child: WIcon(icon!, className: 'text-lg text-primary'),
              ),
          ],
        ),

        // Value
        WText(
          value,
          className: 'text-3xl font-bold text-gray-900 dark:text-white',
        ),

        // Subtitle (optional)
        if (subtitle != null) ...[
          const WSpacer(className: 'h-1'),
          WText(
            subtitle!,
            className: 'text-xs text-gray-500 dark:text-gray-400',
          ),
        ],
      ],
    );

    if (onTap != null) {
      return WAnchor(onTap: onTap, child: content);
    }
    return content;
  }
}
