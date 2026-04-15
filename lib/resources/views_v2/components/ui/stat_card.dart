import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Compact metric card with label, value, icon, and optional trend.
///
/// ## Usage
/// ```dart
/// V2StatCard(
///   label: 'Uptime',
///   value: '99.95%',
///   icon: Icons.arrow_upward_rounded,
///   trend: '+0.02%',
///   trendPositive: true,
/// )
/// ```
class V2StatCard extends StatelessWidget {
  const V2StatCard({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.trend,
    this.trendPositive,
  });

  final String label;
  final String value;
  final IconData? icon;
  final String? trend;
  final bool? trendPositive;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-col gap-1.5 p-4 rounded-xl
        bg-gray-50 dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
      ''',
      children: [
        WDiv(
          className: 'flex flex-row items-center justify-between gap-2',
          children: [
            WDiv(
              className: 'flex-1',
              child: WText(
                label,
                className:
                    'text-xs font-medium text-gray-500 dark:text-gray-400 truncate',
              ),
            ),
            if (icon != null)
              WIcon(
                icon!,
                className: 'text-[16px] text-gray-400 dark:text-gray-500',
              ),
          ],
        ),
        WDiv(
          className: 'flex flex-col gap-0.5',
          children: [
            WText(
              value,
              className: 'text-2xl font-bold text-gray-900 dark:text-white',
            ),
            if (trend != null)
              WDiv(
                states: {if (trendPositive == true) 'positive' else 'negative'},
                className: 'flex flex-row items-center gap-0.5',
                children: [
                  WDiv(
                    states: {
                      if (trendPositive == true) 'positive' else 'negative',
                    },
                    className: '''
                      text-[12px]
                      positive:text-green-600 dark:positive:text-green-400
                      negative:text-red-600 dark:negative:text-red-400
                    ''',
                    child: WIcon(
                      trendPositive == true
                          ? Icons.arrow_upward_rounded
                          : Icons.arrow_downward_rounded,
                    ),
                  ),
                  WText(
                    trend!,
                    states: {
                      if (trendPositive == true) 'positive' else 'negative',
                    },
                    className: '''
                      text-xs font-medium
                      positive:text-green-600 dark:positive:text-green-400
                      negative:text-red-600 dark:negative:text-red-400
                    ''',
                  ),
                ],
              ),
          ],
        ),
      ],
    );
  }
}
