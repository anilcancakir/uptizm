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
/// panel   — the dropdown popover container: a COMPACT, fixed-width menu
/// ```
///
/// The `panel` slot carries an explicit `w-64` width. magic_starter's
/// `DropdownMenu` default panel only sets `min-w-40` (a minimum) and the
/// underlying `WPopover` applies no max width, so an unbounded panel stretches
/// to the full content-column width. A fixed `w-64` makes the menu a compact,
/// trigger-anchored dropdown (mirroring `WSelect`, whose overlay sizes its menu
/// to a fixed width, and the React DateRangePicker menu) while staying wide
/// enough for the longest preset row ("Last 24 hours" + check icon) under the
/// item's `px-4` padding, so the item row does not overflow. The rest of the
/// panel style mirrors the magic_starter default (surface bg, border, rounded,
/// soft shadow, vertical padding, clipped corners).
const WindSlotRecipe dateRangePickerRecipe = WindSlotRecipe(
  slots: {
    'trigger':
        'flex flex-row items-center gap-1.5 rounded-md border '
        'border-color-border bg-surface-container px-3 py-1.5 text-sm '
        'font-medium text-fg',
    'icon': 'size-4 text-fg-muted',
    'label': 'text-sm text-fg',
    'panel':
        'w-64 bg-surface border border-color-border rounded-lg shadow-lg '
        'py-1 overflow-hidden',
  },
);
