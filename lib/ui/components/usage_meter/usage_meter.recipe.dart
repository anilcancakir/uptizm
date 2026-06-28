import 'package:magic/magic.dart';

/// The tone axis key for [usageMeterRecipe].
const String kUsageMeterToneAxis = 'tone';

/// Builds the [WindSlotRecipe] for the UsageMeter component.
///
/// Ported from the design lab `usage-meter.variants.ts`: a label, a used/limit
/// readout, and a thin bar whose tone tracks how close the resource is to its
/// plan limit (up -> degraded -> down), reusing the status token family so it
/// reads at a glance in light and dark.
///
/// Token divergences (uptizm neutral aliases): `bg-muted` (track) ->
/// `bg-surface-container-high`, `text-muted-foreground` -> `text-fg-muted`.
///
/// ### Slot structure
/// ```
/// root    — flex-col, tight gap
/// head    — row: label (left) + used/limit readout (right), space-between
/// label   — text-sm font-medium fg
/// readout — font-mono text-xs tabular-nums muted
/// track   — h-1.5 full-width rounded rail, neutral fill, clips the bar
/// bar     — tone-colored fill (width driven by a FractionallySizedBox)
/// ```
const WindSlotRecipe usageMeterRecipe = WindSlotRecipe(
  slots: {
    'root': 'flex flex-col gap-1.5',
    'head': 'flex flex-row items-center justify-between gap-2',
    'label': 'text-sm font-medium text-fg',
    'readout': 'font-mono text-xs tabular-nums text-fg-muted',
    'track':
        'h-1.5 w-full overflow-hidden rounded-full bg-surface-container-high',
    'bar': 'rounded-full',
  },
  variants: {
    kUsageMeterToneAxis: {
      'up': {'bar': 'bg-up'},
      'degraded': {'bar': 'bg-degraded'},
      'down': {'bar': 'bg-down'},
    },
  },
  defaultVariants: {kUsageMeterToneAxis: 'up'},
);
