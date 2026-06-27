import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import 'ai_confidence_badge.recipe.dart';

/// A soft pill badge visualizing the AI confidence level for an incident
/// analysis.
///
/// Maps the model's self-reported certainty to a status soft-token pair:
/// high reads as healthy (up), medium as cautionary (degraded), low as
/// inactive (paused). Part of the graduated-trust UX, so a glance tells an
/// operator how much weight to give the suggestion.
///
/// Uses [aiConfidenceBadgeRecipe] (a [WindSlotRecipe]) to emit the correct
/// soft background, text color, and geometry for each confidence level.
///
/// ### Example Usage:
///
/// ```dart
/// AiConfidenceBadge(AiConfidence.high)
/// AiConfidenceBadge(AiConfidence.medium)
/// AiConfidenceBadge(AiConfidence.low)
/// ```
@immutable
class AiConfidenceBadge extends StatelessWidget {
  /// The AI confidence level controlling background, text color, and label.
  final AiConfidence level;

  /// Creates an [AiConfidenceBadge] for the given confidence [level].
  const AiConfidenceBadge(this.level, {super.key});

  /// Resolves the slot classNames from the recipe.
  String _resolveClassName() {
    return aiConfidenceBadgeRecipe(
      variants: {kAiConfidenceBadgeConfidenceAxis: level.name},
    );
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the className from the recipe once.
    final className = _resolveClassName();

    // 2. Resolve the display label: "{Level} confidence".
    final displayLabel =
        '${level.name[0].toUpperCase()}${level.name.substring(1)} confidence';

    // 3. Build: pill row with text.
    //    NOTE: `flex flex-row` (NOT `inline-flex`) — Wind renders inline-flex
    //    as a centered vertical column; flex flex-row is correct for a row.
    return WDiv(className: className, children: [WText(displayLabel)]);
  }
}
