import 'package:flutter/material.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';

class StatCard extends StatelessWidget {
  final String label;
  final String value;
  final IconData? icon;
  final bool isMono;
  final String? valueColor;

  const StatCard({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.isMono = false,
    this.valueColor,
  });

  @override
  Widget build(BuildContext context) {
    final fontClass = isMono ? 'font-mono' : '';
    final colorClass = valueColor ?? 'text-gray-900 dark:text-white';

    return WDiv(
      className:
          'bg-white dark:bg-gray-800 rounded-2xl shadow-soft border border-gray-100 dark:border-gray-700 p-4',
      children: [
        // Icon + Label Row
        WDiv(
          className: 'flex flex-row items-center gap-2 mb-2',
          children: [
            if (icon != null)
              WIcon(
                icon!,
                className: 'text-gray-400 dark:text-gray-500 text-lg',
              ),
            Expanded(
              child: WText(
                label.toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400 line-clamp-1',
              ),
            ),
          ],
        ),

        // Value (2xl bold on mobile, 3xl on desktop)
        WText(
          value,
          className:
              'text-2xl md:text-3xl font-bold $colorClass $fontClass line-clamp-1',
        ),
      ],
    );
  }
}
