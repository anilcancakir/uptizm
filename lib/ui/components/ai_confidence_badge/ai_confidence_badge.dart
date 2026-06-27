import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import 'ai_confidence_badge.recipe.dart';

/// A soft pill badge visualizing the AI confidence level for an incident
/// analysis.
///
/// Uses [aiConfidenceBadgeRecipe] to emit the `ai` soft background + matching
/// foreground className so the badge always uses the AI tone. The label is
/// resolved from the `uptizm.ai.confidence_<level>` i18n key so it tracks
/// locale changes automatically.
///
/// ```dart
/// AiConfidenceBadge(AiConfidence.high)
/// AiConfidenceBadge(AiConfidence.medium)
/// AiConfidenceBadge(AiConfidence.low)
/// ```
@immutable
class AiConfidenceBadge extends StatelessWidget {
  /// The AI confidence level controlling the label text.
  final AiConfidence level;

  /// Creates an [AiConfidenceBadge] for the given confidence [level].
  const AiConfidenceBadge(this.level, {super.key});

  /// Resolves the className from the recipe for the AI tone.
  String _resolveClassName() {
    return aiConfidenceBadgeRecipe();
  }

  @override
  Widget build(BuildContext context) {
    return WBadge(
      'AI · ${trans('uptizm.ai.confidence_${level.name}')}',
      className: _resolveClassName(),
    );
  }
}
