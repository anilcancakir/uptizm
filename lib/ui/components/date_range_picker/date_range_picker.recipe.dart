import 'package:magic/magic.dart';

/// Builds the [WindSlotRecipe] for the DateRangePicker component.
///
/// Ported from the design lab `DateRangePicker`: a secondary-button trigger
/// (calendar icon + active range label + chevron) that opens a preset dropdown.
/// The trigger is a styled WDiv (NOT a magic_starter Button) so the wrapping
/// DropdownMenu/WPopover owns the tap — a nested interactive Button would
/// swallow the open gesture.
///
/// ### Slot structure
/// ```
/// trigger — secondary-button look: border + container bg, rounded, sm padding
/// icon    — size-4 muted (calendar + chevron)
/// label   — text-sm fg (the active range label)
/// ```
const WindSlotRecipe dateRangePickerRecipe = WindSlotRecipe(
  slots: {
    'trigger':
        'flex flex-row items-center gap-1.5 rounded-md border border-color-border bg-surface-container px-3 py-1.5 text-sm font-medium text-fg',
    'icon': 'size-4 text-fg-muted',
    'label': 'text-sm text-fg',
  },
);
