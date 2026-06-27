import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/metrics.dart';

/// Container-chrome recipe for [MetricChart].
///
/// The chart canvas itself is drawn by `fl_chart`, which needs real `Color`
/// values; only the surrounding card chrome (background, border, radius,
/// padding) is expressed in Wind className tokens here. The chrome carries no
/// chart geometry, only surface styling, so it stays fully token-driven.
///
/// ```dart
/// WDiv(className: metricChartRecipe());
/// ```
final WindRecipe metricChartRecipe = WindRecipe(
  base:
      'flex flex-col w-full bg-surface-container '
      'border border-color-border rounded-lg p-4 gap-3',
);

/// Resolved light/dark hex pair for a single [ChartTone].
///
/// `fl_chart` cannot consume className strings, so each tone is resolved to a
/// concrete `Color` at paint time. This is the PORTING.md §2 dynamic-color
/// exception, deliberately isolated to this file: the hexes below are the SAME
/// status values DESIGN.md defines for the monitoring families, kept in lockstep
/// with `lib/config/uptizm_status_tokens.dart`. No other file in the component
/// carries raw hex.
@immutable
class _ToneHex {
  /// Hex for the light theme.
  final int light;

  /// Hex for the dark theme.
  final int dark;

  const _ToneHex(this.light, this.dark);
}

/// The single source of truth mapping every [ChartTone] to its status hex pair.
///
/// Mirrors the `up / info / degraded / ai` status families plus brand `primary`.
/// The anomaly-dot red is resolved separately by [metricChartAnomalyColor] since
/// `down` is not a [ChartTone] (the design contract omits it from chart tones).
const Map<ChartTone, _ToneHex> _toneHexes = {
  ChartTone.primary: _ToneHex(0xFF009A6F, 0xFF00C292),
  ChartTone.up: _ToneHex(0xFF30A556, 0xFF45C06A),
  ChartTone.info: _ToneHex(0xFF207FE8, 0xFF53A0FF),
  ChartTone.degraded: _ToneHex(0xFFE69825, 0xFFF5AE39),
  ChartTone.ai: _ToneHex(0xFF6E59E2, 0xFF9E8AFA),
};

/// The `down` status red used for anomaly dots (light, dark).
const _ToneHex _anomalyHex = _ToneHex(0xFFDF202E, 0xFFFF645F);

/// Neutral chart chrome colors (axis-tick text, border lines, page surface).
///
/// These are the same `--color-*` neutrals the React source feeds Recharts
/// (`muted-foreground` for ticks, `border` for grid/cursor/tooltip edge, and
/// `--color-bg`/`surface` for the tooltip background and the anomaly-dot halo).
/// They live in this resolver, not scattered through the component, so it stays
/// the single home for every chart `Color`. Kept in lockstep with the
/// `border-color-border` and `surface` tokens.
const _ToneHex _axisHex = _ToneHex(0xFF79828A, 0xFF999FA6);
const _ToneHex _borderHex = _ToneHex(0xFFDEE2E5, 0xFF2A2E33);
const _ToneHex _surfaceHex = _ToneHex(0xFFF9FAFB, 0xFF07090C);

/// Resolves a [ChartTone] to a concrete series `Color` for the given
/// [brightness].
///
/// This is the only sanctioned place a [ChartTone] becomes a raw `Color`; the
/// rest of the component (and every other component) stays on className tokens.
Color metricChartToneColor(ChartTone tone, Brightness brightness) {
  final hex = _toneHexes[tone] ?? _toneHexes[ChartTone.primary]!;
  return Color(brightness == Brightness.dark ? hex.dark : hex.light);
}

/// Resolves the `down`-toned anomaly-dot `Color` for the given [brightness].
Color metricChartAnomalyColor(Brightness brightness) {
  return Color(
    brightness == Brightness.dark ? _anomalyHex.dark : _anomalyHex.light,
  );
}

/// Resolves the muted axis-tick text `Color` for the given [brightness].
Color metricChartAxisColor(Brightness brightness) {
  return Color(brightness == Brightness.dark ? _axisHex.dark : _axisHex.light);
}

/// Resolves the border-tone `Color` (grid lines, touch cursor, tooltip edge).
Color metricChartBorderColor(Brightness brightness) {
  return Color(
    brightness == Brightness.dark ? _borderHex.dark : _borderHex.light,
  );
}

/// Resolves the page-surface `Color` (tooltip background, anomaly-dot halo).
Color metricChartSurfaceColor(Brightness brightness) {
  return Color(
    brightness == Brightness.dark ? _surfaceHex.dark : _surfaceHex.light,
  );
}
