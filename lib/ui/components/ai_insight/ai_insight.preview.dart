import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/incidents.dart';
import 'ai_insight.dart';

/// Static variant-matrix preview for [AiInsight].
///
/// Three cases mirroring the React `AiInsight.preview.tsx`:
/// 1. Banner with label + medium confidence.
/// 2. Inline with no label + an action slot.
/// 3. Inline with no label, no confidence, no action (minimal).
class AiInsightPreview extends StatelessWidget {
  /// Creates the AI insight preview.
  const AiInsightPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-5 p-6',
      children: [
        // 1. Banner tone: "This week" label + medium confidence + action.
        AiInsight(
          tone: 'banner',
          label: 'This week',
          confidence: AiConfidence.medium,
          action: MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: () {},
            child: const WText('View report'),
          ),
          child: const WText(
            '99.97% uptime across 50 monitors, 3 incidents (2 origin-side, '
            '1 regional). Top risk: checkout has seen recurring 503s on '
            'Monday mornings.',
            className: 'text-sm leading-relaxed text-fg',
          ),
        ),

        // 2. Inline tone: no label, with a secondary-button action control.
        AiInsight(
          action: MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: () {},
            child: const WText('Use'),
          ),
          child: const WText(
            'Based on 7 days of checks, normal p95 sits near 120 ms. '
            'Suggested bounds: warn at 400 ms, critical at 900 ms.',
            className: 'text-sm leading-relaxed text-fg-muted',
          ),
        ),

        // 3. Inline tone: minimal (no label, no confidence, no action).
        const AiInsight(
          child: WText(
            'Flagged a p99 spike at 13:00, roughly 3x baseline and isolated '
            'to ap-southeast with no errors. Reads as regional latency, not '
            'an outage.',
            className: 'text-sm leading-relaxed text-fg-muted',
          ),
        ),
      ],
    );
  }
}
