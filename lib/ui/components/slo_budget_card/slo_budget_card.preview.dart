import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'slo_budget_card.dart';

/// Static variant-matrix preview for [SloBudgetCard].
///
/// Mirrors the design lab `SloBudgetCard.preview.tsx`: the three budget healths
/// (comfortable headroom, nearly spent, breached). One preview class per file;
/// discovered by `previews:refresh`.
class SloBudgetCardPreview extends StatelessWidget {
  /// Creates the SloBudgetCard variant-matrix preview.
  const SloBudgetCardPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return const WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-3 gap-4 p-6',
      children: [
        // Healthy: full headroom.
        SloBudgetCard(target: 99.9, uptimePct: 100),
        // At risk: nearly spent.
        SloBudgetCard(target: 99.9, uptimePct: 99.91),
        // Breached: uptime below target.
        SloBudgetCard(target: 99.95, uptimePct: 99.94),
      ],
    );
  }
}
