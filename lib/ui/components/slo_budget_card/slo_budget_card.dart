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

  /// More downtime happened than the window allows.
  down,
}

/// The error-budget math derived from an SLO target and real downtime minutes.
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

/// Renders a minute count as a localized duration: "45m" / "45dk",
/// "2h" / "2sa", "1h 30m" / "1sa 30dk".
///
/// Public because the monitor-detail budget-burn copy narrates the same minutes
/// this card prints, and two formatters would drift apart.
///
/// The units go through [trans] because every string that interpolates this
/// reaches an operator in their own language, and four of them are Turkish
/// sentences: "Bu pencerede 1h 30m ölçülemedi." is the same defect class as an
/// English clause used as a Turkish subject. One formatter owns the convention,
/// so there is no mixed state to reconcile.
String formatBudgetMinutes(double minutes) {
  final int total = minutes.round();
  final String m = trans('uptizm.slo.unit_minutes');
  final String h = trans('uptizm.slo.unit_hours');
  if (total < 60) return '$total$m';
  final int hours = total ~/ 60;
  final int rem = total % 60;
  return rem == 0 ? '$hours$h' : '$hours$h $rem$m';
}

/// Turns an SLO [target] plus the REAL [downMinutes] that happened into the
/// error-budget math and health tone.
///
/// Two properties are load-bearing, and the predecessor had neither:
///
/// - [downMinutes] is measured downtime, never a percentage. The predecessor
///   took an uptime percentage and multiplied its complement by the window,
///   which converted a ratio measured over whatever the monitor had actually
///   been checked back into minutes of the FULL window: a 15-hour-old monitor
///   with 2 real down minutes reported 26 minutes on 7d and 112 on 30d.
/// - `allowed` stays the full nominal window, so a 30-day 99.9% budget is 43
///   minutes whatever the monitor's age. Scaling it to observed coverage (the
///   alternative) turns that same 15-hour-old monitor's allowance into 54
///   seconds, and every young monitor breaches on its first bad minute.
///
/// The tone comparison itself is unchanged: "uptime below target" was already
/// algebraically `used > allowed`. Only the definition of `used` moved, plus
/// `degraded` no longer reaching it (the caller's payload excludes it, because
/// graceful degradation is a separate quality objective per Google's SRE
/// Workbook Table 2-1).
SloErrorBudget computeErrorBudget(
  double target, {
  required double downMinutes,
  int windowDays = 30,
}) {
  final int windowMin = windowDays * 24 * 60;
  final double allowed = (1 - target / 100) * windowMin;
  final double used = math.max(0.0, downMinutes);
  final double remaining = allowed - used;
  // A 100% target is a valid `slo_target` (`StoreMonitorRequest` allows max:100)
  // and it makes the allowance exactly zero. Reporting 100% left there while the
  // tone below reads `down` put "100% budget left" beside "Budget breached" on
  // the same card, with a full-width bar. With no allowance, any downtime at all
  // has spent all of it.
  final double remainingPct = allowed > 0
      ? (remaining / allowed) * 100
      : (used > 0 ? 0.0 : 100.0);
  final SloBudgetTone tone = used > allowed
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
/// Turns an SLO [target] plus the reliability minutes the backend measured into
/// the allowed-downtime budget, how much is left, and a health tone.
///
/// Beyond the three tones it states two things about its own evidence, because
/// a budget printed without them reads as a measurement of the whole window
/// when it is not: the observed coverage while the window is only partly
/// elapsed, and the minutes nobody measured when a gap exists. Neither ever
/// enters the budget; a gap after the monitor existed is uptizm's own blind
/// spot, so it is unknown rather than bad.
///
/// The caller owns the decision NOT to render this card at all: below a day of
/// coverage, or with nothing measured, the monitor-detail reliability section
/// shows its no-data empty state instead (see `_buildReliabilitySection`).
///
/// ### Example:
/// ```dart
/// SloBudgetCard(
///   target: 99.9,
///   downMinutes: 2,
///   observedMinutes: 900,
///   gapMinutes: 0,
/// )
/// ```
@immutable
class SloBudgetCard extends StatelessWidget {
  /// SLO target as a percentage, e.g. 99.9.
  final double target;

  /// Measured downtime minutes over the window (`slo_down_minutes_*`).
  final double downMinutes;

  /// Elapsed minutes of the window this monitor existed for
  /// (`slo_observed_minutes_*`), which is less than the window itself for a
  /// monitor younger than it.
  final double observedMinutes;

  /// Observed minutes holding no check at all (`slo_gap_minutes_*`).
  final double gapMinutes;

  /// Window length in days used for the downtime math.
  final int windowDays;

  /// Window label shown beside the target, e.g. "30-day". When null, falls back
  /// to the localized 30-day label.
  final String? windowLabel;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Observed coverage, in minutes, from which the coverage note counts days
  /// instead of hours. Two days: below that a young monitor's hours are the
  /// informative unit, above it they stop being readable.
  static const int _coverageDaysThresholdMinutes = 2 * 24 * 60;

  /// Creates a [SloBudgetCard].
  const SloBudgetCard({
    super.key,
    required this.target,
    required this.downMinutes,
    required this.observedMinutes,
    required this.gapMinutes,
    this.windowDays = 30,
    this.windowLabel,
    this.className,
  });

  /// Localized health-tone label shown in the status badge.
  String _statusLabelFor(SloBudgetTone tone) {
    switch (tone) {
      case SloBudgetTone.up:
        return trans('uptizm.slo.status_healthy');
      case SloBudgetTone.degraded:
        return trans('uptizm.slo.status_at_risk');
      case SloBudgetTone.down:
        return trans('uptizm.slo.status_breached');
    }
  }

  @override
  Widget build(BuildContext context) {
    final SloErrorBudget budget = computeErrorBudget(
      target,
      downMinutes: downMinutes,
      windowDays: windowDays,
    );
    final Map<String, String> slots = sloBudgetCardRecipe(
      variants: {kSloBudgetToneAxis: budget.tone.name},
    );
    final double fillPct = budget.remainingPct.clamp(0.0, 100.0);
    final String window = windowLabel ?? trans('uptizm.slo.window_30day');

    // Round to whole minutes BEFORE the coverage decision reads them: a
    // rounding pass placed after it can flip a fully-elapsed window into a
    // partial one on a sub-second difference between the two clock reads the
    // backend took.
    final int windowMinutes = windowDays * 24 * 60;
    final int observed = observedMinutes.round();
    final bool partlyObserved = observed < windowMinutes;

    return WDiv(
      className: className == null
          ? slots['root']
          : '${slots['root']} $className',
      children: [
        // Header: title + SLO/window meta (left) and status badge (right).
        WDiv(
          className: 'flex flex-row items-start justify-between gap-3',
          children: [
            WDiv(
              className: 'flex flex-col gap-0.5',
              children: [
                WText(
                  trans('uptizm.slo.error_budget'),
                  className: 'text-sm font-semibold text-fg',
                ),
                WText(
                  'SLO $target% · $window',
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
                WText(_statusLabelFor(budget.tone), className: slots['status']),
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
              trans('uptizm.slo.budget_left', {'pct': '${fillPct.round()}'}),
              className: 'font-mono text-xs tabular-nums font-medium text-fg',
            ),
            WText(
              // `remaining`, not `used`: this readout has always shown what is
              // LEFT of the allowance. It was called `used` while the burn copy
              // beside it also had a `:used`, meaning the opposite, which is a
              // trap for whoever edits the copy next.
              trans('uptizm.slo.budget_of', {
                'remaining': formatBudgetMinutes(math.max(0, budget.remaining)),
                'allowed': formatBudgetMinutes(budget.allowed),
              }),
              className: 'font-mono text-xs tabular-nums text-fg-muted',
            ),
          ],
        ),

        // Coverage note: what was actually watched, shown only while the window
        // is still filling up. Floored, never rounded, so it cannot claim more
        // coverage than there is; and stated in days past two of them, because
        // every monitor younger than a month hits this line on its 30-day card
        // and "Observed 600 hours of the 30-day window" is not a sentence.
        if (partlyObserved)
          WText(
            observed >= _coverageDaysThresholdMinutes
                ? trans('uptizm.slo.coverage_partial_days', {
                    'days': '${observed ~/ 1440}',
                    'window': window,
                  })
                : trans('uptizm.slo.coverage_partial', {
                    'hours': '${observed ~/ 60}',
                    'window': window,
                  }),
            className: 'text-xs text-fg-muted',
          ),

        // Unmeasured-gap note: neutral by design. A healthy monitor shows a
        // small gap until the in-progress bucket's check lands, so this must
        // never read as downtime.
        if (gapMinutes > 0)
          WText(
            trans('uptizm.slo.gap_unmeasured', {
              'amount': formatBudgetMinutes(gapMinutes),
            }),
            className: 'text-xs text-fg-muted',
          ),

        // Over-budget note, shown only once the budget is breached.
        if (budget.tone == SloBudgetTone.down)
          WText(
            trans('uptizm.slo.over_budget', {
              'amount': formatBudgetMinutes(budget.used - budget.allowed),
            }),
            className: 'text-xs text-down-soft-foreground',
          ),
      ],
    );
  }
}
