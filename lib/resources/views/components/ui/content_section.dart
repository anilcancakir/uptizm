import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Card wrapper with header inside: icon + uppercase title + optional trailing.
///
/// Padding rule:
/// - `noPadding: false` (default): content area gets `px-4 pb-4`
/// - `noPadding: true`: content area has no padding (caller owns it)
///
/// ## Usage
/// ```dart
/// ContentSection(
///   title: 'PERFORMANCE',
///   icon: Icons.show_chart_rounded,
///   child: chart,
/// )
/// ```
class ContentSection extends StatelessWidget {
  const ContentSection({
    super.key,
    required this.title,
    required this.icon,
    required this.child,
    this.trailing,
    this.noPadding = false,
  });

  final String title;
  final IconData icon;
  final Widget child;
  final Widget? trailing;
  final bool noPadding;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-col rounded-xl
        bg-gray-50 dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
      ''',
      children: [
        WDiv(
          className:
              'flex flex-row items-center justify-between px-4 pt-4 pb-3',
          children: [
            WDiv(
              className: 'flex flex-row items-center gap-2',
              children: [
                WIcon(
                  icon,
                  className: 'text-[16px] text-gray-400 dark:text-gray-500',
                ),
                WText(
                  title,
                  className: '''
                    text-xs font-bold tracking-wide
                    text-gray-500 dark:text-gray-400
                  ''',
                ),
              ],
            ),
            ?trailing,
          ],
        ),
        WDiv(
          states: {if (noPadding) 'no-padding'},
          className: 'px-4 pb-4 no-padding:px-0 no-padding:pb-0',
          child: child,
        ),
      ],
    );
  }
}
