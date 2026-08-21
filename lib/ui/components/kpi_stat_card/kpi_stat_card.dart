import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'kpi_stat_card.recipe.dart';

/// Arrow glyph per delta trend; neutral shows none.
const Map<KpiTrend, String> _kDeltaGlyph = {
  KpiTrend.up: '▲',
  KpiTrend.down: '▼',
  KpiTrend.neutral: '',
};

/// **Dashboard KPI Stat Card**
///
/// A metric tile with a short [label], a prominent tabular-nums [value], and
/// an optional [delta] change vs. the previous period. The [trend] controls
/// the color of the delta chip: [KpiTrend.up] for operational green,
/// [KpiTrend.down] for outage red, and [KpiTrend.neutral] for muted gray.
///
/// The card shell is the reused magic_starter [Card] (`CardVariant.surface`),
/// giving consistent background, border, and corner radius without
/// re-implementing any container logic.
///
/// ### Example Usage:
///
/// ```dart
/// // Positive metric (uptime)
/// KpiStatCard(
///   label: 'Uptime (24h)',
///   value: '99.98%',
///   delta: '0.01%',
///   hint: 'vs. last 24h',
///   trend: KpiTrend.up,
/// )
///
/// // Negative metric (latency increase)
/// KpiStatCard(
///   label: 'p95 response',
///   value: '142ms',
///   delta: '18ms',
///   hint: 'vs. last 24h',
///   trend: KpiTrend.down,
/// )
///
/// // No delta
/// KpiStatCard(label: 'Open incidents', value: '3')
/// ```
@immutable
class KpiStatCard extends StatelessWidget {
  /// Short descriptive label for the metric (e.g. "Monitors up").
  final String label;

  /// Pre-formatted metric value (e.g. "99.98%", "48 / 50", "142ms").
  ///
  /// Rendered in Geist Mono tabular-nums so digits align across cards.
  final String value;

  /// Optional change vs. the previous period (e.g. "0.01%", "2 down").
  ///
  /// When `null` the delta row is omitted entirely.
  final String? delta;

  /// Optional small caption rendered below the delta (e.g. "vs. last 24h").
  ///
  /// When `null` the hint row is omitted.
  final String? hint;

  /// Directional tone for the [delta] chip.
  ///
  /// Defaults to [KpiTrend.neutral]. The caller picks the tone so the same
  /// direction (e.g. a metric rising) can mean good or bad depending on context.
  final KpiTrend trend;

  /// Creates a [KpiStatCard].
  const KpiStatCard({
    super.key,
    required this.label,
    required this.value,
    this.delta,
    this.hint,
    this.trend = KpiTrend.neutral,
  });

  /// Resolves the delta-row className from the recipe for the current [trend].
  String _resolveDeltaClassName() {
    return kpiStatCardRecipe(variants: {kKpiStatCardTrendAxis: trend.name});
  }

  @override
  Widget build(BuildContext context) {
    // Wind layout throughout so the card sizes correctly inside a Wind grid
    // cell. The delta is a `flex flex-row` row (NOT `inline-flex`, which wind
    // treats as a deliberately inert token: no layout axis, no warning, so the
    // element falls back to a centered column) with `self-start` so it hugs its
    // content and stays left-aligned instead of stretching the cell width.
    //
    // Equal-height across a KPI row comes from the caller's `grid ...
    // items-stretch` (wind #126/#139), which stretches every cell in a row to
    // the tallest. So the card renders only the rows it has: the delta and hint
    // appear ONLY when supplied, with no reserved placeholder line.
    final deltaClass = _resolveDeltaClassName();
    return MSCard(
      noPadding: false,
      child: WDiv(
        className: 'flex flex-col gap-1',
        children: [
          WText(
            label,
            className:
                'text-xs font-medium uppercase tracking-wide text-fg-muted',
          ),
          // Clamped, because two of these sit side by side on a phone and the
          // value gets about 178pt minus padding. At an iOS accessibility text
          // scale the 24px value grew past that and wrapped MID-NUMBER: an
          // iPhone read "98.90%" as "98." over "90". A number split across two
          // lines is not a smaller number, it is a different one.
          //
          // 1.4 is measured against the 24px desktop step: the widest realistic
          // value is seven monospace characters ("100.00%"), Geist Mono advances
          // at about 0.6em, and 7 x 0.6 x (24 x 1.4) = 141pt against the ~146pt
          // a cell leaves. A phone now renders 20px, so the headroom only grows
          // and the same cap holds. The label, the delta and the hint all keep
          // scaling without a cap.
          MediaQuery.withClampedTextScaling(
            maxScaleFactor: 1.4,
            child: WText(
              value,
              className: 'font-mono text-xl font-semibold tabular-nums text-fg '
                  'lg:text-2xl',
            ),
          ),
          if (delta != null)
            WText(
              _kDeltaGlyph[trend]!.isEmpty
                  ? delta!
                  : '${_kDeltaGlyph[trend]} $delta',
              className: deltaClass,
            ),
          if (hint != null) WText(hint!, className: 'text-xs text-fg-muted'),
        ],
      ),
    );
  }
}
