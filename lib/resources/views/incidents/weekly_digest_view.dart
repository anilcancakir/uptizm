import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/incident_types.dart' show IncidentSummary;
import '../../../app/mocks/incidents.dart';
import '../../../ui/components/ai_confidence_badge/index.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/components/status_badge/index.dart';
import '../../../ui/layouts/page_container.dart';

/// A dismissed anomaly: an alert an operator waved off this week, with the
/// reason that trains the detector.
///
/// Design-lab mock fixture (mirrors the React `WeeklyDigestPage` `DISMISSED`
/// constant). These are raw fixture strings, not i18n copy, exactly like the
/// [incidents] fixtures in `lib/app/mocks/`.
@immutable
class _DismissedAnomaly {
  /// Monitor the dismissed anomaly was raised on.
  final String monitor;

  /// One-line description of what the detector flagged.
  final String summary;

  /// The operator's reason for dismissing it (the feedback that tunes detection).
  final String reason;

  /// Relative wall-clock label, e.g. `"Mon 02:14"`.
  final String time;

  const _DismissedAnomaly({
    required this.monitor,
    required this.summary,
    required this.reason,
    required this.time,
  });
}

/// Anomalies operators waved off this week (design-lab fixture).
const List<_DismissedAnomaly> _dismissed = [
  _DismissedAnomaly(
    monitor: 'Marketing site',
    summary: 'Nightly cache warm raised p95 for ~4 minutes.',
    reason: 'Expected pattern',
    time: 'Mon 02:14',
  ),
  _DismissedAnomaly(
    monitor: 'API gateway',
    summary: 'Single-region blip from a known noisy probe.',
    reason: 'Not an anomaly (noise)',
    time: 'Wed 18:40',
  ),
];

/// **The Weekly AI digest screen at `/incidents/digest`.**
///
/// A faithful Flutter port of the React `WeeklyDigestPage`: a once-a-week
/// readout of what Uptizm's AI did, closing the loop the dashboard AI inbox
/// opens. It shows a "this week" [AiInsight] banner, a KPI summary, the
/// incidents the AI caught from its own checks (each linking to its detail),
/// and the anomalies operators dismissed (the feedback that tunes detection).
///
/// Rendered INSIDE [AppLayout] (mirrors the React router placing
/// `/incidents/digest` inside the app shell). Reached from the dashboard AI
/// inbox's "Weekly digest" link. A pure design-lab readout with no state or
/// actions, so it is a [StatelessWidget] reading the [incidents] fixtures
/// directly (no controller), matching [InviteAcceptView]'s data-only shape.
///
/// The KPI row uses the app's standard responsive grid
/// (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-4`, the [DashboardView] KPI
/// convention) rather than the React source's `grid-cols-2` base, because the
/// in-repo [KpiStatCard] has a content-width floor that overflows two-up on a
/// narrow phone.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/incidents/digest` content (wrapped by the shell):
/// MagicStarter.view.makeLayout('layout.app', child: const WeeklyDigestView())
/// ```
@immutable
class WeeklyDigestView extends StatelessWidget {
  /// Creates the [WeeklyDigestView].
  const WeeklyDigestView({super.key});

  @override
  Widget build(BuildContext context) {
    // Design-lab derivations from the incident fixtures: what the AI caught from
    // its own checks, and how many recovered on their own (an autonomous
    // timeline entry).
    final List<IncidentSummary> aiIncidents = incidents
        .where((i) => i.aiOwned)
        .toList();
    final List<IncidentSummary> autoResolved = incidents
        .where((i) => i.timeline.any((entry) => entry.autonomous))
        .toList();

    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-8',
        children: [
          // 1. Intro block: back-aware header, the "this week" AI banner, and
          //    the KPI summary, nested at the 24px header rhythm (gap-6).
          WDiv(
            className: 'flex flex-col gap-6',
            children: [
              MSPageHeader(
                title: trans('uptizm.digest.title'),
                subtitle: trans('uptizm.digest.description'),
                backLabel: trans('uptizm.digest.back'),
                backFallback: '/',
              ),
              AiInsight(
                tone: 'banner',
                label: trans('uptizm.digest.insight_label'),
                child: WText(
                  trans('uptizm.digest.insight_body', {
                    'caught': '${aiIncidents.length}',
                    'resolved': '${autoResolved.length}',
                    'dismissed': '${_dismissed.length}',
                  }),
                  className: 'text-sm text-fg',
                ),
              ),
              _buildKpiGrid(aiIncidents.length, autoResolved.length),
            ],
          ),

          // 2. Caught by AI: incidents the AI opened from its own checks.
          _buildCaughtSection(aiIncidents),

          // 3. Dismissed anomalies: the operator feedback that tunes detection.
          _buildDismissedSection(),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // KPI grid
  // ---------------------------------------------------------------------------

  /// Builds the four-up KPI summary (detected / auto-resolved / dismissed /
  /// median confidence) on the standard responsive grid.
  Widget _buildKpiGrid(int detected, int autoResolved) {
    return WDiv(
      className:
          'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.digest.kpi_detected_label'),
          value: '$detected',
          hint: trans('uptizm.digest.kpi_detected_hint'),
        ),
        KpiStatCard(
          label: trans('uptizm.digest.kpi_resolved_label'),
          value: '$autoResolved',
          hint: trans('uptizm.digest.kpi_resolved_hint'),
        ),
        KpiStatCard(
          label: trans('uptizm.digest.kpi_dismissed_label'),
          value: '${_dismissed.length}',
          hint: trans('uptizm.digest.kpi_dismissed_hint'),
        ),
        KpiStatCard(
          label: trans('uptizm.digest.kpi_confidence_label'),
          value: trans('uptizm.digest.kpi_confidence_value'),
          hint: trans('uptizm.digest.kpi_confidence_hint'),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Caught by AI
  // ---------------------------------------------------------------------------

  /// Builds the "Caught by AI" section: a heading over a bordered [Card] whose
  /// rows each link to the incident detail.
  Widget _buildCaughtSection(List<IncidentSummary> aiIncidents) {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.digest.section_caught'),
          className: 'text-sm font-semibold text-fg',
        ),
        MSCard(
          noPadding: true,
          child: WDiv(
            className: 'flex flex-col',
            children: [
              for (int i = 0; i < aiIncidents.length; i++)
                _buildCaughtRow(
                  aiIncidents[i],
                  isLast: i == aiIncidents.length - 1,
                ),
            ],
          ),
        ),
      ],
    );
  }

  /// Builds a single "Caught by AI" row: status badge, title + meta, and the AI
  /// confidence badge, tapping through to the incident detail.
  Widget _buildCaughtRow(IncidentSummary incident, {required bool isLast}) {
    return WAnchor(
      onTap: () => MagicRoute.to('/incidents/${incident.id}'),
      child: WDiv(
        className:
            'flex flex-row items-center gap-3 px-5 py-3.5 hover:bg-surface-container-high'
            '${isLast ? '' : ' border-b border-color-border'}',
        children: [
          StatusBadge(incident.impact.statusKey, size: StatusBadgeSize.sm),
          WDiv(
            className: 'flex-1 min-w-0 flex flex-col',
            children: [
              WText(
                incident.title,
                className: 'truncate text-sm font-medium text-fg',
              ),
              WText(
                '${incident.monitorName} · ${incident.startedAt}',
                className:
                    'truncate font-mono text-xs tabular-nums text-fg-muted',
              ),
            ],
          ),
          if (incident.ai != null) AiConfidenceBadge(incident.ai!.confidence),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Dismissed anomalies
  // ---------------------------------------------------------------------------

  /// Builds the "Dismissed anomalies" section: a heading over a bordered [Card]
  /// of dismissed rows, plus the closing feedback-loop note.
  Widget _buildDismissedSection() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.digest.section_dismissed'),
          className: 'text-sm font-semibold text-fg',
        ),
        MSCard(
          noPadding: true,
          child: WDiv(
            className: 'flex flex-col',
            children: [
              for (int i = 0; i < _dismissed.length; i++)
                _buildDismissedRow(
                  _dismissed[i],
                  isLast: i == _dismissed.length - 1,
                ),
            ],
          ),
        ),
        WText(
          trans('uptizm.digest.feedback_note'),
          className: 'px-1 text-xs text-fg-muted',
        ),
      ],
    );
  }

  /// Builds a single dismissed-anomaly row: monitor + summary + time on the
  /// left, the operator's reason as an outline [Badge] on the right.
  Widget _buildDismissedRow(_DismissedAnomaly anomaly, {required bool isLast}) {
    return WDiv(
      className:
          'flex flex-row items-start gap-3 px-5 py-3.5'
          '${isLast ? '' : ' border-b border-color-border'}',
      children: [
        WDiv(
          className: 'flex-1 min-w-0 flex flex-col',
          children: [
            WText(anomaly.monitor, className: 'text-sm font-medium text-fg'),
            WText(anomaly.summary, className: 'text-sm text-fg-muted'),
            WText(
              anomaly.time,
              className: 'mt-0.5 font-mono text-xs tabular-nums text-fg-muted',
            ),
          ],
        ),
        MSBadge(anomaly.reason, tone: BadgeTone.outline),
      ],
    );
  }
}
