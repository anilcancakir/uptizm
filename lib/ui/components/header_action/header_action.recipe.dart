import 'package:magic/magic.dart';

/// Builds the [WindRecipe] for the HeaderAction component's mobile glyph.
///
/// Only the icon-only form has a recipe. Above `lg` the component renders the
/// starter's `MSButton`, which owns its own styling; there is nothing here to
/// duplicate.
///
/// The box matches the monitor header's overflow control (44pt, the tap target
/// floor) so a page with both a create action and a menu reads as one family.
/// The `intent` axis carries the only difference that matters at this size: a
/// primary action is the brand green, a secondary one is muted.
WindRecipe headerActionRecipe() {
  return WindRecipe(
    base: 'w-11 h-11 shrink-0 rounded-md flex items-center justify-center '
        'hover:bg-surface-container',
    variants: {
      'intent': {
        'primary': 'text-primary',
        'secondary': 'text-fg-muted hover:text-fg',
        'disabled': 'text-fg-disabled',
      },
    },
    defaultVariants: {'intent': 'primary'},
  );
}
