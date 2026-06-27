import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import '../ai_confidence_badge/index.dart';
import 'ai_inbox_item.recipe.dart';

/// **AI Inbox Row**
///
/// A card-style row for a single AI-detected anomaly in the dashboard inbox.
/// The anomaly is a soft signal Uptizm flagged from its own monitoring data
/// that has not yet met the threshold to become a full incident.
///
/// Surfaces the monitor name, a one-sentence tl;dr, an [AiConfidenceBadge],
/// a relative timestamp, and two graduated-trust actions:
///
/// - **Approve** ([onApprove]): promote the anomaly to an incident.
/// - **Dismiss** ([onDismiss]): mark the anomaly as noise so Uptizm can refine
///   its detector. The action does NOT auto-execute; the operator must tap.
///
/// ### Graduated-trust UX
///
/// AI suggestions are never auto-executed. Both [onApprove] and [onDismiss] are
/// callbacks supplied by the caller; this component only fires them on explicit
/// user interaction.
///
/// ### Example Usage:
///
/// ```dart
/// // From fixture data
/// final incident = incidents.first;
/// AiInboxItem(
///   incident: incident,
///   onApprove: () => context.go('/incidents/${incident.id}'),
///   onDismiss: () {},
/// )
/// ```
@immutable
class AiInboxItem extends StatelessWidget {
  /// The incident summary carrying the AI analysis data.
  final IncidentSummary incident;

  /// Called when the operator taps the approve / open-incident button.
  ///
  /// This callback does NOT fire automatically; the component only calls it
  /// on explicit user interaction.
  final VoidCallback? onApprove;

  /// Called when the operator taps the dismiss button.
  ///
  /// This callback does NOT fire automatically; the component only calls it
  /// on explicit user interaction.
  final VoidCallback? onDismiss;

  /// Creates an [AiInboxItem] for the given [incident].
  const AiInboxItem({
    super.key,
    required this.incident,
    this.onApprove,
    this.onDismiss,
  });

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the outer card className from the recipe.
    final String rootClass = aiInboxItemRecipe();

    // 2. Assemble the card with stripe, header, summary, and action row.
    return WDiv(
      className: rootClass,
      children: [
        // 3. Left accent stripe marking the row as AI-owned.
        WDiv(
          className: 'absolute top-0 bottom-0 left-0 w-1.5 rounded-l-lg bg-ai',
        ),

        // 4. Header row: sparkle glyph + monitor name + confidence + time.
        _buildHeader(),

        // 5. AI summary paragraph (tldr from the IncidentAi payload).
        _buildSummary(),

        // 6. Action row: approve + dismiss (both require explicit tap).
        _buildActions(),
      ],
    );
  }

  /// Builds the header row: sparkle glyph, monitor name, [AiConfidenceBadge],
  /// and relative timestamp aligned to the trailing edge.
  Widget _buildHeader() {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        // Sparkle glyph marking the row as AI-generated.
        WText('✦', className: 'text-sm text-ai'),

        const SizedBox(width: 6),

        // Monitor name — flex-expands to push badge + time to the right.
        Expanded(
          child: WText(
            incident.monitorName,
            className: 'text-sm font-medium text-fg',
          ),
        ),

        const SizedBox(width: 6),

        // Confidence badge: graduated-trust anchor.
        AiConfidenceBadge(incident.ai!.confidence),

        const SizedBox(width: 6),

        // Relative time string in tabular mono.
        WText(
          incident.startedAt,
          className: 'font-mono text-xs tabular-nums text-fg-muted',
        ),
      ],
    );
  }

  /// Builds the AI summary paragraph from [IncidentAi.tldr].
  Widget _buildSummary() {
    return WText(incident.ai!.tldr, className: 'text-sm text-fg-muted');
  }

  /// Builds the action row with approve and dismiss buttons.
  ///
  /// Both buttons require explicit user interaction; neither fires a callback
  /// automatically (graduated-trust principle).
  Widget _buildActions() {
    return Row(
      children: [
        // Approve: promote the anomaly to a full incident.
        WButton(
          onTap: onApprove,
          className:
              'inline-flex items-center rounded-md bg-primary px-3 py-1.5 '
              'text-xs font-semibold text-on-primary',
          child: WText(
            trans('uptizm.ai.open_incident'),
            className: 'text-xs font-semibold text-on-primary',
          ),
        ),

        const SizedBox(width: 8),

        // Dismiss: mark as noise so the detector can learn.
        WButton(
          onTap: onDismiss,
          className:
              'inline-flex items-center rounded-md border border-color-border '
              'bg-surface-container px-3 py-1.5 text-xs font-medium text-fg-muted',
          child: WText(
            trans('uptizm.ai.dismiss'),
            className: 'text-xs font-medium text-fg-muted',
          ),
        ),
      ],
    );
  }
}
