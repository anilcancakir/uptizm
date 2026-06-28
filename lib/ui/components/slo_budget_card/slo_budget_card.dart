import 'dart:math' as math;

import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'slo_budget_card.recipe.dart';

/// Health tone derived from an SLO error budget.
enum SloBudgetTone {
  /// Comfortable headroom remaining.
  up,

  /// Under a quarter of the budget left.
  degraded,

  /// Uptime has dropped below the SLO target.
  down,
}

/// The error-budget math derived from an SLO target and actual uptime.
@immutable
class SloErrorBudget {
  /// Allowed downtime minutes over the window.
  final double allowed;

  /// Downtime minutes consumed so far.
  final double used;

  /// Minutes of budget left (negative once breached).
  final double remaining;

  /// Budget left as a percentage of the allowance.
  final double remainingPct;

  /// Health tone derived from the budget.
  final SloBudgetTone tone;

  /// Creates a [SloErrorBudget].
  const SloErrorBudget({
    required this.allowed,
    required this.used,
    required this.remaining,
    required this.remainingPct,
    required this.tone,
  });
}

/// Turns an SLO [target] plus actual [uptimePct] into the error-budget math and
/// health tone (healthy while there is headroom, at risk under a quarter left,
/// breached once uptime drops below target). Ported from the design lab.
SloErrorBudget computeErrorBudget(
  double target,
  double uptimePct, {
  int windowDays = 30,
}) {
  final windowMin = windowDays * 24 * 60;
  final allowed = (1 - target / 100) * windowMin;
  final used = math.max(0.0, (1 - uptimePct / 100) * windowMin);
  final remaining = allowed - used;
  final remainingPct = allowed > 0 ? (remaining / allowed) * 100 : 100.0;
  final tone = uptimePct < target
      ? SloBudgetTone.down
      : (remainingPct < 25 ? SloBudgetTone.degraded : SloBudgetTone.up);
  return SloErrorBudget(
    allowed: allowed,
    used: used,
    remaining: remaining,
    remainingPct: remainingPct,
    tone: tone,
  );
}

/// **Error-budget gauge for a monitor's SLO.**
///
/// Turns an SLO [target] plus actual [uptimePct] into the allowed-downtime
/// budget, how much is left, and a health tone. Ported 1:1 from the design lab
/// `SloBudgetCard`.
///
/// ### Example:
/// ```dart
/// SloBudgetCard(target: 99.9, uptimePct: 99.94)
/// ```
@immutable
class SloBudgetCard extends StatelessWidget {
  /// SLO target as a percentage, e.g. 99.9.
  final double target;

  /// Actual uptime over the window as a percentage, e.g. 99.94.
  final double uptimePct;

  /// Window length in days used for the downtime math.
  final int windowDays;

  /// Window label shown beside the target, e.g. "30-day".
  final String windowLabel;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Creates a [SloBudgetCard].
  const SloBudgetCard({
    super.key,
    required this.target,
    required this.uptimePct,
    this.windowDays = 30,
    this.windowLabel = '30-day',
    this.className,
  });

  static const Map<SloBudgetTone, String> _statusLabel = {
    SloBudgetTone.up: 'Healthy',
    SloBudgetTone.degraded: 'At risk',
    SloBudgetTone.down: 'Budget breached',
  };

  /// Render a minute count as "Xm", "Xh", or "Xh Ym".
  String _formatMinutes(double min) {
    final total = min.round();
    if (total < 60) return '${total}m';
    final hours = total ~/ 60;
    final rem = total % 60;
    return rem == 0 ? '${hours}h' : '${hours}h ${rem}m';
  }

  @override
  Widget build(BuildContext context) {
    final budget = computeErrorBudget(target, uptimePct, windowDays: windowDays);
    final slots = sloBudgetCardRecipe(
      variants: {kSloBudgetToneAxis: budget.tone.name},
    );
    final fillPct = budget.remainingPct.clamp(0.0, 100.0);

    return WDiv(
      className: className == null ? slots['root'] : '${slots['root']} $className',
      children: [
        // Header: title + SLO/window meta (left) and status badge (right).
        WDiv(
          className: 'flex flex-row items-start justify-between gap-3',
          children: [
            WDiv(
              className: 'flex flex-col gap-0.5',
              children: [
                WText('Error budget',
                    className: 'text-sm font-semibold text-fg'),
                WText(
                  'SLO $target% · $windowLabel',
                  className: 'font-mono text-xs tabular-nums text-fg-muted',
                ),
              ],
            ),
            WDiv(
              className: 'flex flex-row items-center gap-1.5',
              children: [
                SizedBox(
                  width: 8,
                  height: 8,
                  child: WDiv(className: slots['dot']),
                ),
                WText(_statusLabel[budget.tone]!, className: slots['status']),
              ],
            ),
          ],
        ),

        // Progress track with a tone-colored fill at fillPct width.
        WDiv(
          className: slots['track'],
          child: FractionallySizedBox(
            alignment: Alignment.centerLeft,
            widthFactor: fillPct / 100,
            child: WDiv(
              className: slots['bar'],
              child: const SizedBox.expand(),
            ),
          ),
        ),

        // Footer: budget-left percentage + minutes-of-allowance readout.
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            WText(
              '${fillPct.round()}% budget left',
              className: 'font-mono text-xs tabular-nums font-medium text-fg',
            ),
            WText(
              '${_formatMinutes(math.max(0, budget.remaining))} of ${_formatMinutes(budget.allowed)}',
              className: 'font-mono text-xs tabular-nums text-fg-muted',
            ),
          ],
        ),

        // Over-budget note, shown only once the budget is breached.
        if (budget.tone == SloBudgetTone.down)
          WText(
            'Over budget by ${_formatMinutes(budget.used - budget.allowed)} this window.',
            className: 'text-xs text-down-soft-foreground',
          ),
      ],
    );
  }
}
