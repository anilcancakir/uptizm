import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Large title page header for root tab views (iOS HIG large title pattern).
///
/// ## Usage
/// ```dart
/// PageHeader(
///   title: trans('monitors.title'),
///   leading: WButton(onTap: () {}, child: WIcon(Icons.arrow_back)),
///   trailing: WButton(onTap: () {}, child: WIcon(Icons.add)),
/// )
/// ```
class PageHeader extends StatelessWidget {
  const PageHeader({
    super.key,
    required this.title,
    this.leading,
    this.trailing,
  });

  final String title;
  final Widget? leading;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-row items-center gap-2',
      children: [
        ?leading,
        WDiv(
          className: 'flex-1',
          child: WText(
            title,
            className: '''
              text-2xl font-bold
              text-gray-900 dark:text-white
            ''',
          ),
        ),
        ?trailing,
      ],
    );
  }
}
