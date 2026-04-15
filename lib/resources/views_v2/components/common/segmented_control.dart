import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Generic segmented control with active state and optional count display.
///
/// ## Usage
/// ```dart
/// SegmentedControl<StatusFilter>(
///   items: StatusFilter.values,
///   selected: _activeFilter,
///   labelOf: (f) => f.label,
///   countOf: (f) => f == StatusFilter.all ? null : '${f.count}',
///   onChanged: (f) => setState(() => _activeFilter = f),
/// )
/// ```
class SegmentedControl<T> extends StatelessWidget {
  const SegmentedControl({
    super.key,
    required this.items,
    required this.selected,
    required this.labelOf,
    required this.onChanged,
    this.countOf,
  });

  final List<T> items;
  final T selected;
  final String Function(T) labelOf;
  final ValueChanged<T> onChanged;
  final String? Function(T)? countOf;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-row p-1 rounded-lg
        bg-gray-100 dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
      ''',
      children: [
        for (final item in items)
          WDiv(
            className: 'flex-1',
            child: WButton(
              onTap: () => onChanged(item),
              states: item == selected ? {'active'} : {},
              className: '''
                w-full py-3 rounded-lg
                active:bg-white dark:active:bg-gray-700 active:shadow-sm
              ''',
              child: WDiv(
                className: 'flex flex-row items-center justify-center gap-1',
                children: [
                  WText(
                    labelOf(item),
                    states: item == selected ? {'active'} : {},
                    className: '''
                      text-sm font-medium no-underline
                      text-gray-500 dark:text-gray-400
                      active:text-gray-900 dark:active:text-white
                    ''',
                  ),
                  if (countOf != null && countOf!(item) != null)
                    WText(
                      countOf!(item)!,
                      states: item == selected ? {'active'} : {},
                      className: '''
                        text-xs no-underline
                        text-gray-400 dark:text-gray-500
                        active:text-gray-500 dark:active:text-gray-400
                      ''',
                    ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
