import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'date_range_picker.recipe.dart';

/// A preset time range offered by the [DateRangePicker].
@immutable
class DateRangePreset {
  /// Full menu label, e.g. "Last 7 days".
  final String label;

  /// Compact label, e.g. "7d".
  final String short;

  /// Stable identifier, e.g. "7d".
  final String value;

  /// Creates a [DateRangePreset].
  const DateRangePreset({
    required this.label,
    required this.short,
    required this.value,
  });
}

/// The preset ranges the picker offers, matching the design lab `RANGE_PRESETS`.
///
/// A getter (not a `const`) so each label resolves through [trans] at the
/// current locale; `short`/`value` stay as stable technical tokens.
List<DateRangePreset> get kDateRangePresets => [
  DateRangePreset(label: trans('uptizm.ranges.last_24h'), short: '24h', value: '24h'),
  DateRangePreset(label: trans('uptizm.ranges.last_7d'), short: '7d', value: '7d'),
  DateRangePreset(label: trans('uptizm.ranges.last_30d'), short: '30d', value: '30d'),
  DateRangePreset(label: trans('uptizm.ranges.last_90d'), short: '90d', value: '90d'),
];

/// **Time-range picker for monitor charts.**
///
/// A trigger button shows the active range; tapping opens a dropdown of presets
/// with a checkmark on the selected one. Controlled via [value] + [onChanged].
/// Ported from the design lab `DateRangePicker`.
///
/// ### Example:
/// ```dart
/// DateRangePicker(value: range, onChanged: (v) => setState(() => range = v))
/// ```
@immutable
class DateRangePicker extends StatelessWidget {
  /// Currently selected preset value, e.g. "7d".
  final String value;

  /// Called with the next preset value when a menu item is chosen.
  final ValueChanged<String> onChanged;

  /// Optional extra classNames appended to the trigger slot.
  final String? className;

  /// Creates a [DateRangePicker].
  const DateRangePicker({
    super.key,
    required this.value,
    required this.onChanged,
    this.className,
  });

  static const IconData _calendarIcon = Icons.calendar_today_outlined;
  static const IconData _chevronIcon = Icons.keyboard_arrow_down;
  static const IconData _checkIcon = Icons.check;

  @override
  Widget build(BuildContext context) {
    final slots = dateRangePickerRecipe(variants: const <String, String>{});

    var activeLabel = trans('uptizm.ranges.custom');
    for (final preset in kDateRangePresets) {
      if (preset.value == value) activeLabel = preset.label;
    }

    final triggerClass = className == null
        ? slots['trigger']
        : '${slots['trigger']} $className';

    return MSDropdownMenu(
      // bottomLeft so the menu opens on-screen regardless of trigger placement
      // (the source right-aligns within a right-side toolbar; left-align is the
      // robust generic default and avoids clipping when the trigger sits left).
      alignment: PopoverAlignment.bottomLeft,
      // Bound the panel width. magic_starter's DropdownMenu default panel only
      // sets `min-w-40` (a minimum) and WPopover's overlay applies no maxWidth,
      // so the panel stretches to fill the full content-column width (the
      // observed full-width overlay bug). WSelect's overlay does NOT exhibit
      // this: it sizes its menu to a FIXED `width` (trigger width by default).
      // Mirror that here by giving the panel an explicit compact width so the
      // menu reads as a trigger-anchored dropdown like Select's, matching the
      // React DateRangePicker menu (content-sized, not full width).
      className: slots['panel'],
      items: [
        for (final preset in kDateRangePresets)
          MSDropdownMenuItem(
            label: preset.label,
            leading: preset.value == value
                ? WIcon(_checkIcon, className: 'size-4 text-primary')
                : null,
            onTap: () => onChanged(preset.value),
          ),
      ],
      // Plain styled WDiv trigger so DropdownMenu's WPopover owns the tap.
      child: WDiv(
        className: triggerClass,
        children: [
          WIcon(_calendarIcon, className: slots['icon']),
          WText(activeLabel, className: slots['label']),
          WIcon(_chevronIcon, className: slots['icon']),
        ],
      ),
    );
  }
}
