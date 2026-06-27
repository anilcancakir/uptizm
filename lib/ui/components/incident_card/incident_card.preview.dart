import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import 'incident_card.dart';

/// Static variant-matrix preview for [IncidentCard].
///
/// Renders all five fixture incidents so the preview catalog shows the full
/// range: critical outage (down/AI-owned), degraded warning, resolved
/// threshold incident, maintenance window, and auto-resolved AI blip.
///
/// One preview class per file is the canonical atomic-component contract.
class IncidentCardPreview extends StatelessWidget {
  /// Creates the incident card variant-matrix preview.
  const IncidentCardPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6',
      children: [
        // Section label.
        WText(
          'IncidentCard — all severity × status combinations',
          className: 'text-sm font-semibold text-fg',
        ),

        // All five fixture incidents in a single column.
        ...incidents.map(
          (incident) => IncidentCard(incident: incident, onTap: () {}),
        ),
      ],
    );
  }
}
