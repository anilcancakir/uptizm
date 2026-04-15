import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Large title page header for root tab views (iOS HIG large title pattern).
///
/// ## Usage
/// ```dart
/// PageHeader(
///   title: trans('monitors.title'),
///   trailing: WButton(onTap: () {}, child: WIcon(Icons.add)),
/// )
/// ```
class PageHeader extends StatelessWidget {
  const PageHeader({super.key, required this.title, this.trailing});

  final String title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-row items-center justify-between',
      children: [
        WText(
          title,
          className: '''
            text-2xl font-bold
            text-gray-900 dark:text-white
          ''',
        ),
        ?trailing,
      ],
    );
  }
}
