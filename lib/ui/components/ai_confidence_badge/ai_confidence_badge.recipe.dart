import 'package:magic/magic.dart';

/// Builds the AI confidence badge [WindRecipe] using the AI token family
/// from `lib/config/uptizm_status_tokens.dart`.
///
/// The recipe is a top-level const because the badge uses a single AI tone;
/// all confidence levels (high, medium, low) render with the same background
/// and foreground colors. The level only changes the label text, so no
/// variant axis is needed.
///
/// Emission order: `base`.
///
/// Token pair (soft background + soft-foreground text):
/// - ai: `bg-ai-soft text-ai-soft-foreground`
const WindRecipe aiConfidenceBadgeRecipe = WindRecipe(
  base:
      'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium '
      'bg-ai-soft text-ai-soft-foreground',
);
