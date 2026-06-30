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

/// A non-breaking space used to reserve a footer line's vertical space when a
/// delta or hint is absent, so every KPI card keeps an identical four-row
/// intrinsic height (a plain empty string collapses to zero height in Wind).
const String _kReservedLine = ' ';

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
    // cell. The delta is a `flex flex-row` row (NOT `inline-flex`, which Wind
    // renders as a centered vertical column) with `self-start` so it hugs its
    // content and stays left-aligned instead of stretching the cell width.
    //
    // Equal-height across a KPI row: Wind's `grid` lays cells out through a
    // `Wrap`, which sizes each cell to its own content and (unlike CSS grid's
    // `align-items: stretch`) does NOT stretch shorter cells to the run height.
    // Flex-based stretch is unavailable too: Wind content embeds a LayoutBuilder
    // internally, and `IntrinsicHeight` (which `Row`/`Column` stretch needs for
    // an unbounded cross-axis) cannot measure through a LayoutBuilder. So the
    // card equalizes itself: the delta and hint footer rows are ALWAYS reserved
    // (a non-breaking-space placeholder at the same type token holds the line
    // when a field is absent), giving every card an identical four-row intrinsic
    // height regardless of which optional fields the caller supplies. No
    // hardcoded pixels: the reserved height is the token line-height of the
    // placeholder text.
    final deltaClass = _resolveDeltaClassName();
    return Card(
      noPadding: false,
      child: WDiv(
        className: 'flex flex-col gap-1',
        children: [
          WText(
            label,
            className:
                'text-xs font-medium uppercase tracking-wide text-fg-muted',
          ),
          WText(
            value,
            className: 'font-mono text-2xl font-semibold tabular-nums text-fg',
          ),
          WText(
            delta != null
                ? (_kDeltaGlyph[trend]!.isEmpty
                    ? delta!
                    : '${_kDeltaGlyph[trend]} $delta')
                : _kReservedLine,
            className: deltaClass,
          ),
          WText(
            hint ?? _kReservedLine,
            className: 'text-xs text-fg-muted',
          ),
        ],
      ),
    );
  }
}
