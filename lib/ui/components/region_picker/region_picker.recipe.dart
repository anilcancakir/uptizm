import 'package:magic/magic.dart';

/// Builds the [WindSlotRecipe] for the RegionPicker component.
///
/// Ported from the design lab `region-picker.variants.ts`: a responsive grid of
/// selectable region tiles. The selected look is composed in the component via
/// a conditional class (per-option runtime state), not a tv variant.
///
/// Token divergences (uptizm neutral aliases): `border-border` ->
/// `border-color-border`. Source selected look `bg-accent text-accent-foreground`
/// is a SOFT mint tint + green text; uptizm `bg-accent` is the solid brand green
/// (too saturated), so the selected tile uses `bg-primary-container` (the tinted
/// brand surface) + `text-primary` to match the source's soft selected look.
///
/// ### Slot structure
/// ```
/// root           — responsive grid (2 cols, 3 at sm)
/// option         — one clickable region tile (base, unselected appearance)
/// optionSelected — extra classes appended when the tile is selected
/// optionLocked   — extra classes appended when the tile is capped by the
///                  team's plan (unselected and the selection is at its cap)
/// ```
const WindSlotRecipe regionPickerRecipe = WindSlotRecipe(
  slots: {
    'root': 'grid grid-cols-2 sm:grid-cols-3 gap-2',
    'option':
        'flex flex-row items-center gap-2 min-h-11 min-w-0 rounded-md border border-color-border px-3 text-sm text-fg',
    'optionSelected': 'bg-primary-container text-primary border-primary',
    'optionLocked': 'opacity-60 border-color-border-subtle',
  },
);
