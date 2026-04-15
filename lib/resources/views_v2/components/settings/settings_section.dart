import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// iOS inset grouped section: uppercase header + bordered card.
///
/// ## Usage
/// ```dart
/// SettingsSection(
///   title: 'BASIC INFO',
///   isElevated: true,
///   usePadding: true,
///   child: WDiv(
///     className: 'flex flex-col gap-3',
///     children: [WFormInput(...)],
///   ),
/// )
/// ```
class SettingsSection extends StatelessWidget {
  const SettingsSection({
    super.key,
    required this.title,
    required this.child,
    this.trailing,
    this.usePadding = false,
    this.isElevated = false,
  });

  final String title;
  final Widget child;
  final Widget? trailing;
  final bool usePadding;
  final bool isElevated;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            WText(
              title,
              className: '''
                text-xs font-bold tracking-wide
                text-gray-500 dark:text-gray-400
              ''',
            ),
            ?trailing,
          ],
        ),
        WDiv(
          states: {if (usePadding) 'padded', if (isElevated) 'elevated'},
          className: '''
            flex flex-col rounded-xl
            bg-gray-50 dark:bg-gray-800
            elevated:bg-white dark:elevated:bg-gray-800
            border border-gray-200 dark:border-gray-700
            padded:p-4
          ''',
          child: child,
        ),
      ],
    );
  }
}
