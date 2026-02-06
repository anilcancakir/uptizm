import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

class StatCard extends StatelessWidget {
  final String label;
  final String value;
  final IconData? icon;
  final bool isMono;
  final String? valueColor;
  final String? subtitle;
  final double? trendPercent;

  const StatCard({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.isMono = false,
    this.valueColor,
    this.subtitle,
    this.trendPercent,
  });

  @override
  Widget build(BuildContext context) {
    final fontClass = isMono ? 'font-mono' : '';
    final colorClass = valueColor ?? 'text-gray-900 dark:text-white';

    final trendClass = trendPercent == null
        ? ''
        : trendPercent! >= 0
        ? 'text-primary'
        : 'text-red-500';

    final trendIcon = trendPercent == null
        ? null
        : trendPercent! >= 0
        ? Icons.trending_up
        : Icons.trending_down;

    return WDiv(
      className:
          'bg-white dark:bg-gray-800 rounded-2xl shadow-soft border border-gray-100 dark:border-gray-700 p-4',
      children: [
        // Icon + Label Row
        WDiv(
          className: 'flex flex-row items-center gap-3 mb-3',
          children: [
            if (icon != null) WIcon(icon!, className: 'text-primary text-lg'),
            Expanded(
              child: WText(
                label.toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 line-clamp-1',
              ),
            ),
          ],
        ),

        // Value Row
        WDiv(
          className: 'flex flex-row items-end gap-2',
          children: [
            Flexible(
              child: WText(
                value,
                className:
                    'text-2xl md:text-3xl font-bold $colorClass $fontClass line-clamp-1',
              ),
            ),
            if (trendPercent != null && trendIcon != null)
              WDiv(
                className: 'flex flex-row items-center gap-0.5 mb-1.5 shrink-0',
                children: [
                  WIcon(trendIcon, className: 'text-sm $trendClass'),
                  WText(
                    '${trendPercent!.abs().toStringAsFixed(1)}%',
                    className: 'text-xs font-medium $trendClass',
                  ),
                ],
              ),
          ],
        ),

        // Subtitle
        if (subtitle != null) ...[
          const WSpacer(className: 'h-1'),
          WText(
            subtitle!,
            className: 'text-xs text-gray-400 dark:text-gray-500 line-clamp-1',
          ),
        ],
      ],
    );
  }
}
