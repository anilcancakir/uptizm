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

/// The "enable push" button's className.
///
/// QA finding: colour and weight alone read as static copy on desktop web,
/// where there is no touch affordance to fall back on. The `border-transparent`
/// base reserves the same box `focus:border-primary` fills, so the focus
/// state adds no layout shift; `hover:`/`focus:` share the tinted brand
/// surface [pushPromptTileRecipe] already uses for the `ask` tile.
const String pushPromptEnableButtonClassName =
    'rounded-md border border-transparent px-2 py-1 text-sm font-medium '
    'text-primary transition-colors hover:bg-primary-container '
    'focus:border-primary focus:bg-primary-container';

/// The density axis key for the shell notice.
///
/// Its two values are the two shells this app swaps between at `lg`, not two
/// sizes of one thing: the sidebar has a column to put a sentence in, the
/// mobile bar has a 56px-tall row already carrying three controls.
const String kPushOffNoticeDensityAxis = 'density';

/// The sidebar form: a glyph and a line, sized like a nav row.
const String kPushOffNoticeDensityFull = 'full';

/// The mobile top-bar form: the glyph alone, sized like the account avatar.
const String kPushOffNoticeDensityCompact = 'compact';

/// Builds the shell notice's [WindRecipe].
///
/// Emission order: `base ++ density-variant ++ caller`.
///
/// The base carries no colour of its own; the warning lives entirely in the
/// glyph ([pushOffNoticeIconClassName]) so the marker reads as one of the
/// shell's secondary controls rather than as an alert. Both variants reuse the
/// `hover:bg-surface-container` affordance every other shell control carries,
/// and both own their outer spacing, so a hidden notice costs no layout at all.
///
/// Density -> token mapping:
/// - full:    a full-width row inset to match the sidebar's `px-3` nav column
/// - compact: a 36px round tap target, the mobile account avatar's footprint
const WindRecipe pushOffNoticeRecipe = WindRecipe(
  base: 'flex flex-row items-center rounded-md hover:bg-surface-container',
  variants: {
    kPushOffNoticeDensityAxis: {
      kPushOffNoticeDensityFull: 'w-full gap-2 mx-3 mb-2 px-2 py-2',
      kPushOffNoticeDensityCompact:
          'w-9 h-9 shrink-0 justify-center rounded-full',
    },
  },
  defaultVariants: {kPushOffNoticeDensityAxis: kPushOffNoticeDensityFull},
);

/// The shell notice's glyph className.
///
/// `text-degraded` rather than `text-down`: the device is not fully
/// operational, and `down` is the token an actual outage owns (DESIGN.md makes
/// it equal to `destructive`). Spending the outage colour on a permission would
/// leave a red glyph sitting in the shell next to real red.
const String pushOffNoticeIconClassName = 'text-[16px] text-degraded';

/// The shell notice's label className, matching the sidebar's secondary rows.
const String pushOffNoticeLabelClassName = 'truncate text-xs text-fg-muted';

/// The decline ("not now") button's className.
///
/// Same fix as [pushPromptEnableButtonClassName] and the more pressing of the
/// two per the QA finding: with no feedback at all the decline read as easy
/// to miss as a control, not merely static.
const String pushPromptDeclineButtonClassName =
    'rounded-md border border-transparent px-2 py-1 text-sm font-medium '
    'text-fg-muted transition-colors hover:bg-surface-container '
    'hover:text-fg focus:border-color-border focus:bg-surface-container '
    'focus:text-fg';
