/// Semantic chart color tones; map to CSS vars so series follow the theme.
///
/// These are the tones the `MetricChart` component understands. Note that
/// `down` and `paused` are absent: those status keys do not have a chart
/// tone variant in the design contract.
enum ChartTone {
  /// Brand primary color; default for single-series charts.
  primary,

  /// Operational green; used for latency series in healthy monitors.
  up,

  /// Informational blue; used for informational or AI-baseline series.
  info,

  /// Amber warning; used for elevated-latency or saturation series.
  degraded,

  /// AI purple; used for AI-learned baseline bands and anomaly series.
  ai,
}
