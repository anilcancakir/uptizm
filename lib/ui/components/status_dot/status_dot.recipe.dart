import 'package:magic/magic.dart';

/// The status axis key for the status dot recipe (`StatusKey.<value>.name`).
const String kStatusDotStatusAxis = 'status';

/// The size axis key for the status dot recipe (`StatusDotSize.<value>.name`).
const String kStatusDotSizeAxis = 'size';

/// Builds the status dot [WindRecipe] using the monitoring status token
/// families from `lib/config/uptizm_status_tokens.dart`.
///
/// The recipe emits a solid status-color circle. It is the compact companion
/// to [StatusBadge] for tight rows where a full badge is too heavy.
///
/// Emission order: `base ++ size-variant ++ status-variant`.
///
/// Status -> solid token mapping:
/// - up:       `bg-up`
/// - down:     `bg-down`
/// - degraded: `bg-degraded`
/// - paused:   `bg-paused`
/// - info:     `bg-info`
/// - ai:       `bg-ai`
///
/// Size -> geometry mapping (mirrors `status-dot.variants.ts`):
/// - sm: `size-2`
/// - md: `size-2.5`
/// - lg: `size-3`
const WindRecipe statusDotRecipe = WindRecipe(
  base: 'shrink-0 rounded-full',
  variants: {
    kStatusDotSizeAxis: {'sm': 'size-2', 'md': 'size-2.5', 'lg': 'size-3'},
    kStatusDotStatusAxis: {
      'up': 'bg-up',
      'down': 'bg-down',
      'degraded': 'bg-degraded',
      'paused': 'bg-paused',
      'info': 'bg-info',
      'ai': 'bg-ai',
    },
  },
  defaultVariants: {kStatusDotStatusAxis: 'up', kStatusDotSizeAxis: 'md'},
);
