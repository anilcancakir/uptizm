import 'package:magic/magic.dart';

/// The confidence axis key for the AI confidence badge recipe
/// (`AiConfidence.<value>.name`).
const String kAiConfidenceBadgeConfidenceAxis = 'confidence';

/// Builds the AI confidence badge [WindRecipe] using the monitoring status
/// token families from `lib/config/uptizm_status_tokens.dart`.
///
/// One axis:
/// - `confidence` — the confidence level controlling background and text color
///
/// Emission order: `base ++ confidence-variant`.
///
/// Confidence -> token pair mapping (mirrors `ai-confidence-badge.variants.ts`):
/// - high:   `bg-up-soft text-up-soft-foreground`
/// - medium: `bg-degraded-soft text-degraded-soft-foreground`
/// - low:    `bg-paused-soft text-paused-soft-foreground`
const WindRecipe aiConfidenceBadgeRecipe = WindRecipe(
  base:
      'flex flex-row items-center gap-1 rounded-full px-2 py-0.5 text-xs '
      'font-medium',
  variants: {
    kAiConfidenceBadgeConfidenceAxis: {
      'high': 'bg-up-soft text-up-soft-foreground',
      'medium': 'bg-degraded-soft text-degraded-soft-foreground',
      'low': 'bg-paused-soft text-paused-soft-foreground',
    },
  },
  defaultVariants: {kAiConfidenceBadgeConfidenceAxis: 'high'},
);
