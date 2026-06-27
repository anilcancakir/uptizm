import 'package:magic/magic.dart';

/// The status axis key for the status dot recipe (`StatusKey.<value>.name`).
const String kStatusDotStatusAxis = 'status';

/// Builds the status dot [WindRecipe] using the monitoring status token
/// families from `lib/config/uptizm_status_tokens.dart`.
///
/// The recipe is a top-level const because the dot has no theme-override
/// hook; statuses read straight from the supplement alias map merged in
/// `lib/main.dart`.
///
/// Emission order: `base ++ status-variant`.
///
/// Status -> solid token mapping (the dot is always the solid status color):
/// - up:       `bg-up`
/// - down:     `bg-down`
/// - degraded: `bg-degraded`
/// - paused:   `bg-paused`
/// - info:     `bg-info`
/// - ai:       `bg-ai`
const WindRecipe statusDotRecipe = WindRecipe(
  base: 'size-2 rounded-full',
  variants: {
    kStatusDotStatusAxis: {
      'up': 'bg-up',
      'down': 'bg-down',
      'degraded': 'bg-degraded',
      'paused': 'bg-paused',
      'info': 'bg-info',
      'ai': 'bg-ai',
    },
  },
  defaultVariants: {kStatusDotStatusAxis: 'up'},
);
