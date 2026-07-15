import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/support/incident_types.dart' show IncidentAi;
import '../../../app/mocks/incidents.dart';
import 'ai_analysis_card.dart';

/// Static variant-matrix preview for [AiAnalysisCard].
///
/// Renders the two fixture incidents that carry an [IncidentAi] payload:
/// the high-confidence 503 outage and the medium-confidence latency
/// degradation. The preview wires a no-op [onActionTap] so action rows render
/// as interactive. One preview class per file is the canonical
/// atomic-component contract.
class AiAnalysisCardPreview extends StatelessWidget {
  /// Creates the AI analysis card variant-matrix preview.
  const AiAnalysisCardPreview({super.key});

  @override
  Widget build(BuildContext context) {
    // Collect only incidents that have an AI analysis attached.
    final aiIncidents = incidents.where((i) => i.ai != null).toList();

    return WDiv(
      className: 'flex flex-col gap-8 p-6',
      children: [
        WText(
          'AiAnalysisCard — all fixture AI analyses',
          className: 'text-sm font-semibold text-fg',
        ),

        for (final incident in aiIncidents)
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              // Incident title as context label above each card.
              WText(incident.title, className: 'text-xs text-fg-muted'),

              AiAnalysisCard(ai: incident.ai!, onActionTap: (_) {}),
            ],
          ),
      ],
    );
  }
}
