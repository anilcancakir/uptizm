import 'package:magic/magic.dart';

/// Directional trend for the KPI stat card delta chip.
///
/// - [up] — metric improved: renders in the `text-up` operational green.
/// - [down] — metric worsened: renders in the `text-down` outage red.
/// - [neutral] — no directional meaning: renders in `text-fg-muted` gray.
enum KpiTrend {
  /// Metric improved (increase is good, e.g. uptime rose).
  up,

  /// Metric worsened (increase is bad, or a raw decline, e.g. latency rose).
  down,

  /// No directional meaning (e.g. unchanged, or direction depends on context).
  neutral,
}

/// The trend axis key for the KPI stat card recipe (`KpiTrend.<value>.name`).
const String kKpiStatCardTrendAxis = 'trend';

/// Builds the KPI stat card delta [WindRecipe] using the monitoring status
/// token families.
///
/// The recipe covers only the delta row (the chip that shows the change vs.
/// the previous period). The card shell and label/value text are styled inline
/// in [KpiStatCard] using fixed semantic tokens.
///
/// Trend -> semantic token mapping for the delta text:
/// - up:      `text-up`       (operational green — increase is good)
/// - down:    `text-down`     (outage red — increase is bad, or a raw decline)
/// - neutral: `text-fg-muted` (no directional meaning)
const WindRecipe kpiStatCardRecipe = WindRecipe(
  // Text-only tokens: the glyph + delta are laid out by an explicit Flutter Row
  // in the widget, so this carries just the type + tone (applied to each WText).
  base: 'font-mono text-xs font-medium tabular-nums',
  variants: {
    kKpiStatCardTrendAxis: {
      // Soft-foreground tones (matching the design source) read calmer than the
      // solid status colors against the card surface.
      'up': 'text-up-soft-foreground',
      'down': 'text-down-soft-foreground',
      'neutral': 'text-fg-muted',
    },
  },
  defaultVariants: {kKpiStatCardTrendAxis: 'neutral'},
);
