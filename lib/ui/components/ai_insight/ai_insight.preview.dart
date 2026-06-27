import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import 'ai_insight.dart';

/// Static variant-matrix preview for [AiInsight].
///
/// Renders the two fixture incidents that carry an [IncidentAi] payload:
/// the high-confidence 503 outage and the medium-confidence latency
/// degradation. One preview class per file is the canonical atomic-component
/// contract.
class AiInsightPreview extends StatelessWidget {
  /// Creates the AI insight block preview.
  const AiInsightPreview({super.key});

  @override
  Widget build(BuildContext context) {
    // Collect only incidents that have an AI analysis attached.
    final aiIncidents = incidents.where((i) => i.ai != null).toList();

    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        WText(
          'AiInsight — all fixture AI analyses',
          className: 'text-sm font-semibold text-fg',
        ),

        for (final incident in aiIncidents)
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              // Incident title as context label above each block.
              WText(incident.title, className: 'text-xs text-fg-muted'),
              AiInsight(ai: incident.ai!),
            ],
          ),
      ],
    );
  }
}
