import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'kpi_stat_card.dart';
import 'kpi_stat_card.recipe.dart';

/// Static variant-matrix preview for [KpiStatCard].
///
/// Renders a representative grid of KPI tiles — positive/negative/neutral
/// trends, with and without a delta, and with a hint caption — so the
/// preview catalog shows the full surface in both light and dark.
///
/// One preview class per file is the canonical atomic-component contract.
class KpiStatCardPreview extends StatelessWidget {
  /// Creates the KPI stat card variant-matrix preview.
  const KpiStatCardPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6',
      children: [
        // Row label.
        WText(
          'KpiStatCard — trend variants',
          className: 'text-sm font-semibold text-fg',
        ),

        // 2×2 grid showing each trend x hint combo.
        WDiv(
          className: 'grid grid-cols-2 gap-4',
          children: [
            // Positive trend — uptime improving.
            KpiStatCard(
              label: trans('uptizm.dashboard.kpi_uptime_24h'),
              value: '99.98%',
              delta: '+0.01%',
              hint: trans('uptizm.dashboard.kpi_hint_vs_24h'),
              trend: KpiTrend.up,
            ),

            // Negative trend — latency worsening.
            KpiStatCard(
              label: 'p95 response',
              value: '142ms',
              delta: '+18ms',
              hint: trans('uptizm.dashboard.kpi_hint_vs_24h'),
              trend: KpiTrend.down,
            ),

            // Negative trend with count delta — monitors with issues.
            KpiStatCard(
              label: trans('uptizm.dashboard.kpi_monitors_up'),
              value: '48 / 50',
              delta: trans('uptizm.dashboard.kpi_delta_down', {'count': '2'}),
              hint: trans('uptizm.dashboard.kpi_hint_ai_detected', {
                'count': '1',
              }),
              trend: KpiTrend.down,
            ),

            // Neutral trend — open incidents, no change.
            KpiStatCard(
              label: trans('uptizm.dashboard.kpi_open_incidents'),
              value: '3',
              delta: 'unchanged',
              hint: trans('uptizm.dashboard.kpi_hint_ai_detected', {
                'count': '1',
              }),
              trend: KpiTrend.neutral,
            ),

            // No delta — avg response, plain metric.
            KpiStatCard(
              label: trans('uptizm.dashboard.kpi_avg_response'),
              value: '87ms',
            ),

            // No delta, no hint — minimal card.
            KpiStatCard(
              label: trans('uptizm.dashboard.kpi_monitors_up'),
              value: '50 / 50',
              trend: KpiTrend.up,
            ),
          ],
        ),
      ],
    );
  }
}
