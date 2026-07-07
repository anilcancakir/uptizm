import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/incidents.dart';
import '../../../app/mocks/status.dart';
import '../status_badge/index.dart';
import 'incident_card.recipe.dart';

/// **Incident Summary Card**
///
/// A tappable card that surfaces the essential information for a single
/// incident in a list: the customer-facing [IncidentImpact] encoded as a
/// left accent stripe and a [StatusBadge], the lifecycle stage, the headline
/// [title], the affected monitor name, the operator severity, and the
/// relative started-at timestamp rendered in tabular-nums Geist Mono.
///
/// The card shell is the reused magic_starter [Card] (`CardVariant.surface`),
/// giving consistent background, border, and corner radius without
/// re-implementing container logic.
///
/// ### Example Usage:
///
/// ```dart
/// // Active critical outage
/// IncidentCard(
///   incident: incidents.first,
///   onTap: () => context.go('/incidents/checkout-503'),
/// )
///
/// // Resolved maintenance window (no tap handler)
/// IncidentCard(incident: incidents[3])
/// ```
@immutable
class IncidentCard extends StatelessWidget {
  /// The incident data to display.
  final IncidentSummary incident;

  /// Optional tap handler. When `null` the card is non-interactive.
  final VoidCallback? onTap;

  /// Creates an [IncidentCard] for the given [incident].
  const IncidentCard({super.key, required this.incident, this.onTap});

  /// Resolves the accent-stripe className from the recipe for the current
  /// [IncidentImpact].
  String _resolveStripeClassName() {
    return incidentCardRecipe(
      variants: {kIncidentCardImpactAxis: incident.impact.statusKey.name},
    );
  }

  @override
  Widget build(BuildContext context) {
    // 1. Wrap the card content in an optional tap region.
    final Widget cardContent = _buildCardContent();

    if (onTap == null) {
      return cardContent;
    }

    return GestureDetector(onTap: onTap, child: cardContent);
  }

  Widget _buildCardContent() {
    return MSCard(
      noPadding: true,
      child: WDiv(
        className: 'relative overflow-hidden',
        children: [
          // 2. Left accent stripe encodes customer impact.
          WDiv(className: _resolveStripeClassName()),

          // 3. Card body with padding offset for the stripe.
          WDiv(
            className: 'flex flex-col gap-2 p-4 pl-5',
            children: [
              // 4. Header row: impact badge, lifecycle stage, optional AI tag.
              _buildHeader(),

              // 5. Incident headline title.
              WText(incident.title, className: 'text-sm font-semibold text-fg'),

              // 6. Meta row: monitor name, severity, and started-at timestamp.
              _buildMeta(),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildHeader() {
    return WDiv(
      className: 'wrap items-center gap-2',
      children: [
        // Impact drives the status badge color.
        StatusBadge(incident.impact.statusKey),

        // Lifecycle stage as an outline Badge (mirrors React's
        // `<Badge tone="outline">`); composes the magic_starter Badge recipe
        // rather than hand-rolling the pill className.
        MSBadge(incident.lifecycle.label, tone: BadgeTone.outline),

        // AI-owned incidents get an ai StatusBadge (mirrors React's
        // `<StatusBadge status="ai" size="sm">AI-detected</StatusBadge>`).
        if (incident.aiOwned)
          StatusBadge(StatusKey.ai, label: trans('uptizm.ai.ai_detected')),
      ],
    );
  }

  Widget _buildMeta() {
    // Single-monitor incidents read better with the concrete monitor name +
    // severity; multi-monitor ones collapse to a count (the design-lab shape).
    final bool single = incident.affectedCount <= 1;

    return WDiv(
      className:
          'wrap items-center gap-x-2 gap-y-1 font-mono text-xs tabular-nums text-fg-muted',
      children: [
        if (single) ...[
          // Affected monitor name.
          WText(incident.monitorName),

          // Separator dot.
          WText('·', className: 'text-fg-disabled'),

          // Operator severity tier label.
          WText(incident.severity.label),
        ] else
          // Count of affected monitors (mirrors the design lab).
          WText('${incident.affectedCount} monitors affected'),

        // Separator dot.
        WText('·', className: 'text-fg-disabled'),

        // Relative start / resolution timestamp.
        WText(incident.startedAt),
      ],
    );
  }
}
