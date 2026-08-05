import 'package:magic/magic.dart';

/// The tone axis key for the StringValueList recipe
/// (`StringValueListTone.<value>.name`).
const String kStringValueListToneAxis = 'tone';

/// Builds the [WindSlotRecipe] for the StringValueList component.
///
/// Ported from `KeyValueEditor`'s slot shape, with a `tone` axis added so the
/// same chip list renders neutral (healthy values), warn, or critical, using
/// the monitoring status token families from `lib/config/uptizm_status_tokens.dart`
/// (see `DESIGN.md`). No new token: the tone -> token pairs are the ones
/// already used by [statusDotRecipe] and `AiConfidenceBadge`.
///
/// Tone -> token pair mapping:
/// - neutral:  `bg-surface-container-high` (a plain semantic surface, no
///   status family; healthy values are not "up", they are just unremarkable)
/// - warn:     `bg-degraded-soft text-degraded-soft-foreground`
/// - critical: `bg-down-soft text-down-soft-foreground`
///
/// ### Slot structure
/// ```
/// root   — vertical stack of the chip row + the entry row
/// chips  — wrapping row of committed value chips
/// chip   — appended to WBadge's own base classes; tone controls this
/// remove — small inline ghost icon button that drops a chip
/// ```
const WindSlotRecipe stringValueListRecipe = WindSlotRecipe(
  slots: {
    'root': 'flex flex-col gap-2',
    'chips': 'flex wrap gap-2',
    'chip': '',
    'remove':
        'flex flex-row items-center justify-center size-4 shrink-0 rounded-full',
  },
  variants: {
    kStringValueListToneAxis: {
      'neutral': {'chip': 'bg-surface-container-high'},
      'warn': {'chip': 'bg-degraded-soft text-degraded-soft-foreground'},
      'critical': {'chip': 'bg-down-soft text-down-soft-foreground'},
    },
  },
  defaultVariants: {kStringValueListToneAxis: 'neutral'},
);
