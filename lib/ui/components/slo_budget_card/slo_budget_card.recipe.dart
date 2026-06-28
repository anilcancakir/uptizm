import 'package:magic/magic.dart';

/// The tone axis key for [sloBudgetCardRecipe].
const String kSloBudgetToneAxis = 'tone';

/// Builds the [WindSlotRecipe] for the SloBudgetCard component.
///
/// Ported from the design lab `slo-budget-card.variants.ts`. The `tone` variant
/// maps the error budget's health to the status token family (up/degraded/down)
/// so the gauge bar, status dot, and status label read at a glance and restyle
/// in dark mode.
///
/// Token divergences (uptizm neutral aliases): `border-border` ->
/// `border-color-border`, `bg-muted` (track) -> `bg-surface-container-high`,
/// `text-muted-foreground` -> `text-fg-muted` (applied inline in the component).
///
/// ### Slot structure
/// ```
/// root   — flex-col card: border + surface bg + padding (rounded-xl)
/// track  — h-2 full-width rounded rail, neutral fill, clips the bar
/// bar    — tone-colored fill (width driven by a FractionallySizedBox)
/// status — text-xs font-medium, tone soft-foreground (the status label)
/// dot    — tone-colored circle (sized by a SizedBox in the component)
/// ```
const WindSlotRecipe sloBudgetCardRecipe = WindSlotRecipe(
  slots: {
    'root':
        'flex flex-col gap-3 rounded-xl border border-color-border bg-surface p-5',
    'track':
        'h-2 w-full overflow-hidden rounded-full bg-surface-container-high',
    'bar': 'rounded-full',
    'status': 'text-xs font-medium',
    'dot': 'rounded-full',
  },
  variants: {
    kSloBudgetToneAxis: {
      'up': {
        'bar': 'bg-up',
        'status': 'text-up-soft-foreground',
        'dot': 'bg-up',
      },
      'degraded': {
        'bar': 'bg-degraded',
        'status': 'text-degraded-soft-foreground',
        'dot': 'bg-degraded',
      },
      'down': {
        'bar': 'bg-down',
        'status': 'text-down-soft-foreground',
        'dot': 'bg-down',
      },
    },
  },
  defaultVariants: {kSloBudgetToneAxis: 'up'},
);
