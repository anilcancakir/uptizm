import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Key-value configuration row with border separator.
///
/// ## Usage
/// ```dart
/// ConfigRow(label: 'Method', value: 'GET')
/// ```
class ConfigRow extends StatelessWidget {
  const ConfigRow({super.key, required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-row items-center justify-between py-3.5 px-4
        border-b border-gray-200 dark:border-gray-700
      ''',
      children: [
        WText(label, className: 'text-sm text-gray-500 dark:text-gray-400'),
        WText(
          value,
          className:
              'text-sm font-mono font-medium text-gray-900 dark:text-white',
        ),
      ],
    );
  }
}
