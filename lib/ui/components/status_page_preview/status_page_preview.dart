import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/incident_types.dart' show IncidentSummary;
import '../../../app/support/metric_types.dart' show MonitorMetric;
import '../../../app/support/status_page_support.dart' show pageUrl, worstStatus;
import '../../../app/support/status_page_types.dart' show PublicComponent;
import '../../../app/mocks/incidents.dart';
import '../../../app/enums/metric_direction.dart' show MetricDirection;
import '../../../app/enums/status_key.dart';
import '../../../app/mocks/status_pages.dart';
import '../../../app/models/status_page.dart';
import '../component_status_row/index.dart';
import '../status_badge/index.dart';
import '../status_dot/index.dart';
import 'status_page_preview.recipe.dart';

/// **The public status page, rendered in-app.**
///
/// A faithful mockup of the (backend-rendered) public status page, driven
/// entirely by a [StatusPage]. It is embedded twice: in the editor's live
/// preview pane (a brand-framed live draft) and by the standalone full-screen
/// public route. Ported 1:1 in structure from the design source
/// `StatusPagePreview.tsx`.
///
/// Top-to-bottom it renders: a brand header, an overall-status banner, an
/// optional live-metrics grid, the component list (or a dashed empty
/// placeholder), an optional past-incidents list filtered to this page's
/// monitors, an optional subscribe box, and a footer.
///
/// Brand color and logo come from the model; all component/incident health
/// reads through the semantic status tokens so it looks right regardless of the
/// brand tint. The only raw color anywhere is [StatusPage.brandColor] (the logo
/// tile and the subscribe button), which is content data.
///
/// ### Example Usage:
///
/// ```dart
/// StatusPagePreview(config: page)
/// ```
@immutable
class StatusPagePreview extends StatelessWidget {
  /// The status page to render.
  final StatusPage config;

  /// Creates a [StatusPagePreview] for the given [config].
  const StatusPagePreview({super.key, required this.config});

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the config into its published components, metrics, overall
    //    status, and the incidents that touch its monitor set.
    final List<PublicComponent> components = componentsFor(config);
    final List<MonitorMetric> metrics = metricsFor(config);
    final StatusKey overall = worstStatus(components);
    final List<IncidentSummary> history = _historyFor(components);

    // 2. Column scaffold: an explicit Flutter Column bounds each leaf section to
    //    the max-w-2xl frame so rows and grids lay out cleanly (a Wind flex-col
    //    would hand descendants an unbounded-width regime).
    return WDiv(
      className: statusPagePreviewShellClassName,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _buildBrandHeader(),
          const SizedBox(height: 32),
          _buildBanner(overall),
          if (metrics.isNotEmpty) ...[
            const SizedBox(height: 32),
            _buildMetrics(metrics),
          ],
          const SizedBox(height: 32),
          _buildComponents(components),
          if (history.isNotEmpty) ...[
            const SizedBox(height: 32),
            _buildIncidents(history),
          ],
          if (config.subscriptionsEnabled) ...[
            const SizedBox(height: 32),
            _buildSubscribe(),
          ],
          const SizedBox(height: 32),
          _buildFooter(),
        ],
      ),
    );
  }

  // -- 1. Brand header -------------------------------------------------------

  Widget _buildBrandHeader() {
    // The logo tile: a brand-tinted rounded square with the fallback initials in
    // white. brandColor is content data (the design source's inline
    // `style={{ background: brandColor }}`), applied through WDiv.backgroundColor
    // (the Team.color / sidebar-avatar precedent), NOT a semantic token.
    final String logoText = config.logoText ?? '';
    final String name = config.name ?? '';
    final String initials = logoText.isNotEmpty
        ? logoText
        : (name.isNotEmpty ? name.substring(0, 1) : 'S');

    return WDiv(
      className: 'flex flex-row items-center gap-2',
      children: [
        WDiv(
          backgroundColor: config.brandColor,
          className: 'size-7 rounded-md flex items-center justify-center',
          child: WText(initials, className: 'text-sm font-bold text-white'),
        ),
        WText(
          name.isNotEmpty ? name : trans('uptizm.status.preview_default_name'),
          className: 'text-base font-semibold tracking-tight text-fg',
        ),
      ],
    );
  }

  // -- 2. Overall banner -----------------------------------------------------

  Widget _buildBanner(StatusKey overall) {
    final StatusPageBannerTone tone =
        statusPageBannerTones[overall] ?? statusPageBannerTones[StatusKey.up]!;

    // Solid banner dot bound to a 10px box (a childless WDiv collapses to zero
    // size in Wind); the label grows and pushes the mono timestamp to the right.
    return WDiv(
      className:
          'flex flex-row items-center gap-3 rounded-xl border border-color-border '
          'px-5 py-4 ${tone.box}',
      children: [
        SizedBox(
          width: 10,
          height: 10,
          child: WDiv(className: 'size-2.5 rounded-full ${tone.dot}'),
        ),
        WText(tone.label, className: 'text-sm font-semibold ${tone.text}'),
        WDiv(
          className: 'flex-1',
          child: WText(
            trans('uptizm.status.preview_updated_ago'),
            className: 'text-right font-mono text-xs ${tone.text}',
          ),
        ),
      ],
    );
  }

  // -- 3. Live metrics -------------------------------------------------------

  Widget _buildMetrics(List<MonitorMetric> metrics) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        WText(
          trans('uptizm.status.preview_live_metrics_heading'),
          className: statusPagePreviewSectionHeadingClassName,
        ),
        const SizedBox(height: 8),
        WDiv(
          className: 'grid grid-cols-2 sm:grid-cols-3 gap-3 items-stretch',
          children: [
            for (final MonitorMetric metric in metrics)
              _buildMetricCell(metric),
          ],
        ),
      ],
    );
  }

  Widget _buildMetricCell(MonitorMetric metric) {
    return WDiv(
      className: statusPagePreviewMetricCellClassName,
      children: [
        WText(metric.label, className: 'text-xs text-fg-muted'),
        const SizedBox(height: 4),
        WDiv(
          className: 'flex flex-row items-center gap-2',
          children: [
            StatusDot(_metricBand(metric)),
            WText(
              _formatMetricValue(metric),
              className: 'font-mono text-xl font-semibold tabular-nums text-fg',
            ),
          ],
        ),
      ],
    );
  }

  // -- 4. Components ---------------------------------------------------------

  Widget _buildComponents(List<PublicComponent> components) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        WText(
          trans('uptizm.status.preview_components_heading'),
          className: statusPagePreviewSectionHeadingClassName,
        ),
        const SizedBox(height: 8),
        if (components.isNotEmpty)
          WDiv(
            className: statusPagePreviewComponentsBoxClassName,
            children: [
              for (final PublicComponent component in components)
                ComponentStatusRow(
                  name: component.name,
                  status: component.status,
                  segments: component.segments,
                  uptimeLabel: component.uptime,
                ),
            ],
          )
        else
          WText(
            trans('uptizm.status.preview_components_empty'),
            className: statusPagePreviewEmptyPlaceholderClassName,
          ),
      ],
    );
  }

  // -- 5. Past incidents -----------------------------------------------------

  Widget _buildIncidents(List<IncidentSummary> history) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        WText(
          trans('uptizm.status.preview_past_incidents_heading'),
          className: statusPagePreviewSectionHeadingClassName,
        ),
        const SizedBox(height: 8),
        for (int i = 0; i < history.length; i++) ...[
          if (i > 0) const SizedBox(height: 12),
          _buildIncidentRow(history[i]),
        ],
      ],
    );
  }

  Widget _buildIncidentRow(IncidentSummary incident) {
    return WDiv(
      className: statusPagePreviewIncidentRowClassName,
      children: [
        WDiv(
          className: 'flex-1 min-w-0',
          children: [
            WText(incident.title, className: 'text-sm font-medium text-fg'),
            const SizedBox(height: 2),
            WText(
              '${incident.lifecycle.label} · ${incident.startedAt}',
              className: 'text-xs text-fg-muted',
            ),
          ],
        ),
        StatusBadge(incident.impact.statusKey, size: StatusBadgeSize.sm),
      ],
    );
  }

  // -- 6. Subscribe ----------------------------------------------------------

  Widget _buildSubscribe() {
    return WDiv(
      className: statusPagePreviewSubscribeBoxClassName,
      children: [
        WText(
          trans('uptizm.status.preview_subscribe_heading'),
          className: 'text-sm font-semibold text-fg',
        ),
        const SizedBox(height: 4),
        WText(
          trans('uptizm.status.preview_subscribe_description'),
          className: 'text-sm text-fg-muted',
        ),
        const SizedBox(height: 12),
        WDiv(
          className: 'flex flex-row items-center gap-2',
          children: [
            Expanded(
              child: MSInput(
                type: InputType.email,
                placeholder: trans('uptizm.status.preview_subscribe_placeholder'),
                className: 'max-w-xs',
              ),
            ),
            _buildSubscribeButton(),
          ],
        ),
      ],
    );
  }

  Widget _buildSubscribeButton() {
    // The design source paints this button with the raw brand color
    // (`style={{ background: brandColor }}`). Neither magic_starter's Button nor
    // WButton exposes a raw-Color background, so the brand surface is a
    // WDiv(backgroundColor: brandColor) (the sanctioned content-color path) made
    // tappable by a WButton wrapper. The tap is a no-op: this is a mockup.
    return WButton(
      onTap: () {},
      child: WDiv(
        backgroundColor: config.brandColor,
        className: 'rounded-md px-4 py-2 flex items-center justify-center',
        child: WText(
          trans('uptizm.status.preview_subscribe_button'),
          className: 'text-sm font-medium text-white',
        ),
      ),
    );
  }

  // -- 7. Footer -------------------------------------------------------------

  Widget _buildFooter() {
    return WText(
      '${pageUrl(config)} · ${trans('uptizm.status.preview_powered_by')}',
      className: 'text-center font-mono text-xs text-fg-muted',
    );
  }

  // -- Helpers ---------------------------------------------------------------

  /// Past incidents whose primary or affected monitors intersect the config's
  /// published components. Mirrors the design source's `history` filter
  /// (`names.has(monitorName) || affectedMonitors.some((m) => names.has(m.name))`).
  List<IncidentSummary> _historyFor(List<PublicComponent> components) {
    final Set<String> names = {
      for (final PublicComponent c in components) c.name,
    };
    return incidents.where((IncidentSummary incident) {
      if (names.contains(incident.monitorName)) return true;
      return incident.affectedMonitors.any((m) => names.contains(m.name));
    }).toList();
  }

  /// Health band of a metric's current value against its warn/critical bounds,
  /// mapped to a [StatusKey] for the [StatusDot]. Mirrors the design source's
  /// `metricBand` (`down`/`degraded`/`up`).
  StatusKey _metricBand(MonitorMetric metric) {
    bool worse(num bound) => metric.direction == MetricDirection.low
        ? metric.value <= bound
        : metric.value >= bound;

    if (worse(metric.critical)) return StatusKey.down;
    if (worse(metric.warn)) return StatusKey.degraded;
    return StatusKey.up;
  }

  /// Display string for a metric's current value, e.g. `"73%"` or `"842ms"`.
  /// Mirrors the design source's `formatMetricValue` + `UNIT_SUFFIX` map.
  String _formatMetricValue(MonitorMetric metric) {
    const Map<String, String> unitSuffix = <String, String>{
      '%': '%',
      'ms': 'ms',
      's': 's',
      'req_s': '/s',
      'bytes': 'B',
      'count': '',
      '': '',
    };
    final String suffix = unitSuffix[metric.unit] ?? metric.unit;
    final String value = _formatNum(metric.value);
    return suffix.isNotEmpty ? '$value$suffix' : value;
  }

  /// Render a metric value without a trailing `.0` for whole numbers (the fixture
  /// values are integers; JavaScript prints `73`, not `73.0`).
  String _formatNum(num value) {
    if (value is int) return value.toString();
    if (value == value.roundToDouble()) return value.toInt().toString();
    return value.toString();
  }
}
