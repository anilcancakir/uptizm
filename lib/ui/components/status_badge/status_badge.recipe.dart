import 'package:magic/magic.dart';

/// The status axis key for the status badge recipe (`StatusKey.<value>.name`).
const String kStatusBadgeStatusAxis = 'status';

/// Builds the status badge [WindRecipe] using the monitoring status token
/// families from `lib/config/uptizm_status_tokens.dart`.
///
/// The recipe is a top-level const because the badge has no theme-override
/// hook; statuses read straight from the supplement alias map merged in
/// `lib/main.dart`.
///
/// Emission order: `base ++ status-variant`.
///
/// Status -> token pair mapping (soft background + soft-foreground text):
/// - up:       `bg-up-soft text-up-soft-foreground`
/// - down:     `bg-down-soft text-down-soft-foreground`
/// - degraded: `bg-degraded-soft text-degraded-soft-foreground`
/// - paused:   `bg-paused-soft text-paused-soft-foreground`
/// - info:     `bg-info-soft text-info-soft-foreground`
/// - ai:       `bg-ai-soft text-ai-soft-foreground`
const WindRecipe statusBadgeRecipe = WindRecipe(
  base: 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
  variants: {
    kStatusBadgeStatusAxis: {
      'up': 'bg-up-soft text-up-soft-foreground',
      'down': 'bg-down-soft text-down-soft-foreground',
      'degraded': 'bg-degraded-soft text-degraded-soft-foreground',
      'paused': 'bg-paused-soft text-paused-soft-foreground',
      'info': 'bg-info-soft text-info-soft-foreground',
      'ai': 'bg-ai-soft text-ai-soft-foreground',
    },
  },
  defaultVariants: {kStatusBadgeStatusAxis: 'up'},
);
