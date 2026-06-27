import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import 'ai_confidence_badge.dart';

/// Static variant-matrix preview for [AiConfidenceBadge].
///
/// Renders every [AiConfidence] level in a row so the catalog shows the full
/// surface in light and dark. One preview class per file is the canonical
/// atomic-component contract.
class AiConfidenceBadgePreview extends StatelessWidget {
  /// Creates the AI confidence badge variant-matrix preview.
  const AiConfidenceBadgePreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-3 p-6',
      children: [
        for (final level in AiConfidence.values)
          WDiv(
            className: 'flex flex-row items-center gap-3',
            children: [
              AiConfidenceBadge(level),
              WText(level.name, className: 'text-sm text-fg-muted'),
            ],
          ),
      ],
    );
  }
}
