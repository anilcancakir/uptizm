import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// A labeled segmented control for selecting a preset integer value.
///
/// ## Usage
/// ```dart
/// PresetSelector(
///   label: trans('monitors.interval'),
///   presets: [
///     (value: 60, label: '1m'),
///     (value: 120, label: '2m'),
///   ],
///   selected: _selectedInterval,
///   onChanged: (v) => setState(() => _selectedInterval = v),
/// )
/// ```
class PresetSelector extends StatelessWidget {
  const PresetSelector({
    super.key,
    required this.label,
    required this.presets,
    required this.selected,
    required this.onChanged,
  });

  final String label;
  final List<({int value, String label})> presets;
  final int selected;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(
          label,
          className: '''
            text-sm font-medium
            text-gray-700 dark:text-gray-300
          ''',
        ),
        WDiv(
          className: '''
            flex flex-row p-1 rounded-lg
            bg-gray-100 dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
          ''',
          children: [
            for (final preset in presets)
              WDiv(
                className: 'flex-1',
                child: WButton(
                  onTap: () => onChanged(preset.value),
                  states: preset.value == selected ? {'active'} : {},
                  className: '''
                    w-full py-3 rounded-lg
                    active:bg-white dark:active:bg-gray-700 active:shadow-sm
                  ''',
                  child: WDiv(
                    className: 'flex flex-row items-center justify-center',
                    child: WText(
                      preset.label,
                      states: preset.value == selected ? {'active'} : {},
                      className: '''
                        text-sm font-medium no-underline
                        text-gray-500 dark:text-gray-400
                        active:text-gray-900 dark:active:text-white
                      ''',
                    ),
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }
}
