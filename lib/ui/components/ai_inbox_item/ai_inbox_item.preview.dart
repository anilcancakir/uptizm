import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import 'ai_inbox_item.dart';

/// Static variant-matrix preview for [AiInboxItem].
///
/// Renders the two fixture incidents that carry an AI analysis payload so the
/// preview catalog shows:
/// - A high-confidence AI-owned critical outage.
/// - A medium-confidence AI-owned latency degradation.
///
/// Both rows show no-op approve/dismiss callbacks to demonstrate the
/// graduated-trust affordance without side effects.
///
/// One preview class per file is the canonical atomic-component contract.
class AiInboxItemPreview extends StatelessWidget {
  /// Creates the AI inbox item preview.
  const AiInboxItemPreview({super.key});

  @override
  Widget build(BuildContext context) {
    // Collect only AI-owned incidents that have an AI analysis attached.
    final aiIncidents = incidents.where((i) => i.ai != null).toList();

    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        WText(
          'AiInboxItem — graduated-trust inbox rows',
          className: 'text-sm font-semibold text-fg',
        ),

        for (final incident in aiIncidents)
          AiInboxItem(incident: incident, onApprove: () {}, onDismiss: () {}),
      ],
    );
  }
}
