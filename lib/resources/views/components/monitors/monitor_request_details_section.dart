import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../app_card.dart';
import '../key_value_editor.dart';

/// Shared "HTTP Request Details" section: headers + body.
class MonitorRequestDetailsSection extends StatelessWidget {
  final Map<String, String> headers;
  final ValueChanged<Map<String, String>> onHeadersChanged;
  final String body;
  final ValueChanged<String> onBodyChanged;

  const MonitorRequestDetailsSection({
    super.key,
    required this.headers,
    required this.onHeadersChanged,
    required this.body,
    required this.onBodyChanged,
  });

  @override
  Widget build(BuildContext context) {
    return AppCard(
      title: trans('monitor.headers'),
      body: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          KeyValueEditor(entries: headers, onChanged: onHeadersChanged),

          // Request Body
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                trans('monitor.body'),
                className:
                    'text-sm font-medium text-gray-900 dark:text-gray-200',
              ),
              WText(
                trans('monitor.body_hint'),
                className: 'text-xs text-gray-600 dark:text-gray-400',
              ),
              WInput(
                value: body,
                onChanged: onBodyChanged,
                type: InputType.multiline,
                placeholder: trans('monitor.body_placeholder'),
                className: '''
                  w-full px-3 py-3 rounded-lg
                  bg-white dark:bg-gray-800
                  border border-gray-200 dark:border-gray-700
                  text-gray-900 dark:text-white
                  font-mono text-sm
                  focus:border-primary focus:ring-2 focus:ring-primary/20
                  min-h-[120px]
                ''',
                placeholderClassName: 'text-gray-400 dark:text-gray-500',
              ),
            ],
          ),
        ],
      ),
    );
  }
}
