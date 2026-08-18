import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/ai_confidence.dart';
import 'ai_confidence_badge.recipe.dart';

/// A soft pill badge visualizing the AI confidence level for an incident
/// analysis.
///
/// Maps the model's self-reported certainty to a status soft-token pair:
/// high reads as healthy (up), medium as cautionary (degraded), low as
/// inactive (paused). Part of the graduated-trust UX, so a glance tells an
/// operator how much weight to give the suggestion.
///
/// The label is the bare adjective ("Yüksek", "High") rather than the full
/// phrase. It sits in a wrapping header row beside a monitor name and a
/// timestamp, and the AI sparkle next to it already supplies the noun.
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

    // 2. Resolve the display label from the catalogue. The key is built from
    //    the enum's own name, so a new confidence level is a missing key rather
    //    than a silently English label; the three that exist are pinned by a
    //    test that reads the shipped catalogue.
    final String displayLabel = trans('uptizm.ai.confidence_${level.name}');

    // 3. Build: pill row with text.
    //    NOTE: `flex flex-row`, NOT `inline-flex`. Wind lists `inline-flex`
    //    among its deliberately inert compat tokens, so it sets no layout axis
    //    (and never warns), leaving the element as a centered column.
    return WDiv(className: className, children: [WText(displayLabel)]);
  }
}
