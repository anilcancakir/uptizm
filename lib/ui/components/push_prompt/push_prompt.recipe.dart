import 'package:magic/magic.dart';

/// The state axis key shared by the three push-prompt recipes.
///
/// Its values are the four presentations the prompt has, which are NOT the
/// four `PushReachability` values: `off` splits into `ask` (the soft prompt)
/// and the compact enable row a resolved ask leaves behind, and both of those
/// carry the same container tokens.
const String kPushPromptStateAxis = 'state';

/// The `ask` presentation: the soft prompt, and the compact enable row.
const String kPushPromptStateAsk = 'ask';

/// The `blocked` presentation: the platform will not prompt again.
const String kPushPromptStateBlocked = 'blocked';

/// The `on` presentation: this device is reachable.
const String kPushPromptStateOn = 'on';

/// The `unavailable` presentation: this build has no push at all.
const String kPushPromptStateUnavailable = 'unavailable';

/// Builds the push-prompt container [WindRecipe].
///
/// Shaped after the dashboard's locale prompt banner (rounded, bordered, a
/// tinted surface carrying a glyph tile) so the two soft prompts in the product
/// read as one family.
///
/// Emission order: `base ++ state-variant ++ caller`.
///
/// State -> token mapping:
/// - ask:         `bg-surface-container` on `border-color-border`
/// - blocked:     `bg-degraded-soft`, the designed warning tint
/// - on:          `bg-surface-container` on the hairline border
/// - unavailable: the same, quieter still
const WindRecipe pushPromptRecipe = WindRecipe(
  base: 'w-full flex flex-row items-start gap-3 rounded-xl border p-4',
  variants: {
    kPushPromptStateAxis: {
      kPushPromptStateAsk: 'border-color-border bg-surface-container',
      kPushPromptStateBlocked: 'border-color-border bg-degraded-soft',
      kPushPromptStateOn: 'border-color-border-subtle bg-surface-container',
      kPushPromptStateUnavailable:
          'border-color-border-subtle bg-surface-container',
    },
  },
  defaultVariants: {kPushPromptStateAxis: kPushPromptStateAsk},
);

/// Builds the glyph tile [WindRecipe] that leads every push-prompt row.
///
/// Emission order: `base ++ state-variant ++ caller`.
///
/// State -> token mapping:
/// - ask:         `bg-primary-container`, the brand tile the locale banner uses
/// - blocked:     `bg-surface-container`, a neutral tile ON the warning tint
/// - on:          `bg-up-soft`, the operational family
/// - unavailable: `bg-surface-container-high`
const WindRecipe pushPromptTileRecipe = WindRecipe(
  base: 'size-8 shrink-0 flex items-center justify-center rounded-lg',
  variants: {
    kPushPromptStateAxis: {
      kPushPromptStateAsk: 'bg-primary-container',
      kPushPromptStateBlocked: 'bg-surface-container',
      kPushPromptStateOn: 'bg-up-soft',
      kPushPromptStateUnavailable: 'bg-surface-container-high',
    },
  },
  defaultVariants: {kPushPromptStateAxis: kPushPromptStateAsk},
);

/// Builds the glyph [WindRecipe] for the icon inside the tile.
///
/// Separate from [pushPromptTileRecipe] because the colour rides the glyph and
/// the fill rides the tile, and Wind emits one className per widget.
///
/// Emission order: `base ++ state-variant ++ caller`.
///
/// State -> token mapping:
/// - ask:         `text-primary`
/// - blocked:     `text-degraded-soft-foreground`, the pair of the tint above
/// - on:          `text-up`
/// - unavailable: `text-fg-disabled`
const WindRecipe pushPromptIconRecipe = WindRecipe(
  base: 'text-lg',
  variants: {
    kPushPromptStateAxis: {
      kPushPromptStateAsk: 'text-primary',
      kPushPromptStateBlocked: 'text-degraded-soft-foreground',
      kPushPromptStateOn: 'text-up',
      kPushPromptStateUnavailable: 'text-fg-disabled',
    },
  },
  defaultVariants: {kPushPromptStateAxis: kPushPromptStateAsk},
);
