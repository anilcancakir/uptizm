import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/ai_confidence.dart';
import 'ai_confidence_badge.dart';

/// Static variant-matrix preview for [AiConfidenceBadge].
///
/// Renders all three confidence levels (high, medium, low) side by side so the
/// catalog shows the full surface in light and dark. One preview class per
/// file is the canonical atomic-component contract.
class AiConfidenceBadgePreview extends StatelessWidget {
  /// Creates the AI confidence badge variant-matrix preview.
  const AiConfidenceBadgePreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-wrap items-center gap-3 p-6',
      children: [
        for (final confidence in AiConfidence.values)
          AiConfidenceBadge(confidence),
      ],
    );
  }
}
