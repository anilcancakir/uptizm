import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/metric_time_range.dart';

/// Segmented button group for selecting metric time ranges.
///
/// ## Usage
/// ```dart
/// TimeRangeSelector(
///   selected: MetricTimeRange.hour24,
///   onChanged: (range) => setState(() => _range = range),
/// )
/// ```
class TimeRangeSelector extends StatelessWidget {
  const TimeRangeSelector({
    super.key,
    required this.selected,
    required this.onChanged,
  });

  final MetricTimeRange selected;
  final ValueChanged<MetricTimeRange> onChanged;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-row p-1 rounded-lg
        bg-gray-100 dark:bg-gray-800
      ''',
      children: [
        for (final range in MetricTimeRange.values)
          WDiv(
            className: 'flex-1',
            child: WButton(
              onTap: () => onChanged(range),
              states: range == selected ? {'active'} : {},
              className: '''
                w-full py-3 rounded-lg no-underline
                active:bg-white dark:active:bg-gray-700 active:shadow-sm
              ''',
              child: WDiv(
                className: 'flex flex-row items-center justify-center',
                child: WText(
                  range.label,
                  states: range == selected ? {'active'} : {},
                  className: '''
                    text-xs font-medium no-underline
                    text-gray-500 dark:text-gray-400
                    active:text-gray-900 dark:active:text-white
                  ''',
                ),
              ),
            ),
          ),
      ],
    );
  }
}
