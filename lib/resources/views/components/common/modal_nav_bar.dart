import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// iOS HIG modal nav bar: Cancel (left) | Title (center) | Action verb (right).
///
/// ## Usage
/// ```dart
/// ModalNavBar(
///   title: trans('monitors.create_title'),
///   actionLabel: trans('common.add'),
///   onAction: _handleCreate,
/// )
/// ```
class ModalNavBar extends StatelessWidget {
  const ModalNavBar({
    super.key,
    required this.title,
    required this.actionLabel,
    required this.onAction,
  });

  final String title;
  final String actionLabel;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-row items-center justify-between px-4 py-3
        border-b border-gray-200 dark:border-gray-700
        bg-white dark:bg-gray-900
      ''',
      children: [
        WButton(
          onTap: () => MagicRoute.back(),
          className: 'py-3.5 px-3 rounded-lg',
          child: WText(
            trans('common.cancel'),
            className:
                'text-base no-underline text-primary dark:text-primary-400',
          ),
        ),
        WText(
          title,
          className: '''
            text-base font-semibold
            text-gray-900 dark:text-white
          ''',
        ),
        WButton(
          onTap: onAction,
          className: 'py-3.5 px-3 rounded-lg',
          child: WText(
            actionLabel,
            className: '''
              text-base font-semibold no-underline
              text-primary dark:text-primary-400
            ''',
          ),
        ),
      ],
    );
  }
}
