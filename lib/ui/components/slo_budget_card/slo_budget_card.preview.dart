import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'slo_budget_card.dart';

/// Static variant-matrix preview for [SloBudgetCard].
///
/// The three budget healths (comfortable headroom, nearly spent, breached) plus
/// the two evidence states the card states about itself: a window only partly
/// observed, and observed minutes nobody measured. Every card is fed real
/// minutes, so the 30-day allowance is 43 in each of them.
/// One preview class per file; discovered by `previews:refresh`.
class SloBudgetCardPreview extends StatelessWidget {
  /// Creates the SloBudgetCard variant-matrix preview.
  const SloBudgetCardPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return const WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-3 gap-4 p-6',
      children: [
        // Healthy: full headroom over a fully observed window.
        SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 43200,
          gapMinutes: 0,
        ),
        // At risk: 40 of the 43 allowed minutes spent.
        SloBudgetCard(
          target: 99.9,
          downMinutes: 40,
          observedMinutes: 43200,
          gapMinutes: 0,
        ),
        // Breached: 75 minutes down against a 43-minute allowance.
        SloBudgetCard(
          target: 99.9,
          downMinutes: 75,
          observedMinutes: 43200,
          gapMinutes: 0,
        ),
        // Partly observed: a monitor three days into a 30-day window.
        SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 4320,
          gapMinutes: 0,
        ),
        // Unmeasured gap: healthy, and honest about what it did not watch.
        SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 43200,
          gapMinutes: 155,
        ),
      ],
    );
  }
}
