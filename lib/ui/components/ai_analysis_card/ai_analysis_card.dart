import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/incidents.dart';
import '../ai_insight/index.dart';

/// **Incident AI Analysis Card**
///
/// The full AI analysis panel for an incident detail view. Composes the
/// magic_starter [Card] shell, the in-repo [AiInsight] evidence block, a
/// suggested-actions section (approval-gated, never auto-executed), and a
/// similar-incidents section.
///
/// The panel enforces Uptizm's graduated-trust principle: evidence for and
/// against the AI's hypothesis is always shown together, and no suggested
/// action fires without an explicit operator tap.
///
/// ### Sections (all conditional on non-empty data)
///
/// - **Header**: sparkle glyph, "AI analysis" label, trigger string, and the
///   [AiConfidenceBadge] supplied via [AiInsight].
/// - **AI Insight block**: composed [AiInsight] widget (evidence for/against,
///   confidence, citations).
/// - **Suggested actions**: ordered advisory steps with rationale. Each action
///   is approval-gated via an [onActionTap] callback; the card never fires a
///   callback automatically.
/// - **Similar incidents**: past incidents the AI considers similar, with a
///   percentage similarity score.
/// - **Footer**: a disclaimer note reminding the operator that Uptizm reasons
///   only from external monitoring signals.
///
/// ### Example Usage:
///
/// ```dart
/// // Full AI analysis from a fixture incident
/// final ai = incidents.first.ai!;
/// AiAnalysisCard(
///   ai: ai,
///   onActionTap: (action) {
///     // Show confirmation dialog before acting.
///   },
/// )
///
/// // Inside an incident detail page
/// if (incident.ai != null)
///   AiAnalysisCard(
///     ai: incident.ai!,
///     onActionTap: (action) => _handleAction(context, action),
///   )
/// ```
@immutable
class AiAnalysisCard extends StatelessWidget {
  /// The AI analysis data to render.
  final IncidentAi ai;

  /// Called when the operator explicitly taps a suggested action.
  ///
  /// The callback receives the tapped [AiSuggestedAction]. It does NOT fire
  /// automatically; the operator must tap the action row to trigger it.
  ///
  /// When `null`, action rows render as non-interactive display items.
  final void Function(AiSuggestedAction action)? onActionTap;

  /// Creates an [AiAnalysisCard] for the given [ai] analysis.
  const AiAnalysisCard({super.key, required this.ai, this.onActionTap});

  @override
  Widget build(BuildContext context) {
    // An explicit Flutter Column scaffolds the body so each section (and the
    // nested Row-based action/similar rows) receives a bounded width from the
    // Card shell and wraps cleanly on a narrow (mobile) column, rather than the
    // unbounded-width regime a Wind flex-col would introduce.
    final List<Widget> sections = [
      // Header row: sparkle glyph + label + trigger + confidence badge.
      _buildHeader(),
      // AI insight block (evidence for/against, confidence, citations).
      AiInsight(ai: ai),
      // Suggested actions section (advisory, approval-gated).
      if (ai.suggestedActions.isNotEmpty) _buildSuggestedActions(),
      // Similar incidents section.
      if (ai.similarIncidents.isNotEmpty) _buildSimilarIncidents(),
      // Footer disclaimer: graduated trust note.
      _buildFooter(),
    ];

    return Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < sections.length; i++) ...[
            if (i > 0) const SizedBox(height: 24),
            sections[i],
          ],
        ],
      ),
    );
  }

  /// Builds the header row: sparkle glyph, "AI analysis" label, trigger
  /// string, and the AI confidence badge surfaced via [AiInsight].
  Widget _buildHeader() {
    return WDiv(
      className: 'wrap items-center gap-2',
      children: [
        // Sparkle glyph marking the panel as AI-generated.
        WText('✦', className: 'text-base text-ai'),

        // "AI analysis" heading label.
        WText(
          trans('uptizm.ai.ai_detected'),
          className: 'text-sm font-semibold text-ai',
        ),

        // Trigger string (e.g. "AI anomaly") shown as a muted pill.
        WBadge(
          ai.trigger,
          className:
              'inline-flex items-center rounded-full px-2 py-0.5 text-xs '
              'font-medium bg-ai-soft text-ai-soft-foreground',
        ),
      ],
    );
  }

  /// Builds the suggested-actions section.
  ///
  /// Each action is an advisory next step. Tapping an action row fires
  /// [onActionTap] with the relevant [AiSuggestedAction]; the callback is
  /// never invoked automatically (graduated-trust principle).
  Widget _buildSuggestedActions() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Section label.
        WText(
          trans('uptizm.ai.suggested_actions'),
          className: 'text-xs font-semibold text-ai',
        ),
        // One row per suggested action.
        for (final action in ai.suggestedActions) ...[
          const SizedBox(height: 12),
          _buildActionRow(action),
        ],
      ],
    );
  }

  /// Renders a single suggested-action row: an arrow marker, the action title,
  /// the rationale, and an optional tap region when [onActionTap] is provided.
  Widget _buildActionRow(AiSuggestedAction action) {
    // Use explicit Row + Expanded so the text body can wrap without overflowing.
    final Widget content = Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Arrow marker aligned to the text baseline.
        WText('→', className: 'text-sm font-medium text-ai'),

        const SizedBox(width: 12),

        // Action body: title + rationale; expands to fill remaining width.
        Expanded(
          child: WDiv(
            className: 'flex flex-col gap-0.5',
            children: [
              WText(action.title, className: 'text-sm font-medium text-fg'),
              WText(action.rationale, className: 'text-xs text-fg-muted'),
            ],
          ),
        ),
      ],
    );

    if (onActionTap == null) {
      return content;
    }

    // Approval gate: only fire the callback on explicit tap.
    return GestureDetector(onTap: () => onActionTap!(action), child: content);
  }

  /// Builds the similar-incidents section.
  ///
  /// Each row shows the past incident title and a percentage similarity score
  /// rendered in tabular-nums Geist Mono.
  Widget _buildSimilarIncidents() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Section label.
        WText(
          trans('uptizm.ai.similar_incidents'),
          className: 'text-xs font-semibold text-ai',
        ),
        // One row per similar incident.
        for (final incident in ai.similarIncidents) ...[
          const SizedBox(height: 12),
          _buildSimilarIncidentRow(incident),
        ],
      ],
    );
  }

  /// Renders a single similar-incident row: title on the left, similarity
  /// percentage on the right in tabular-nums mono.
  Widget _buildSimilarIncidentRow(AiSimilarIncident incident) {
    // Convert similarity score [0, 1] to an integer percentage string.
    final String percent = '${(incident.similarity * 100).round()}%';

    return Row(
      children: [
        // Title expands to fill available space.
        Expanded(child: WText(incident.title, className: 'text-sm text-fg')),

        const SizedBox(width: 8),

        // Similarity score: ai-toned mono chip.
        WText(
          percent,
          className:
              'font-mono text-xs tabular-nums font-medium text-ai-soft-foreground',
        ),
      ],
    );
  }

  /// Builds the footer disclaimer row.
  ///
  /// Reminds the operator that Uptizm AI reasons only from external monitoring
  /// signals and that human verification is required before acting.
  Widget _buildFooter() {
    return WDiv(
      className: 'flex flex-col gap-1 pt-2 border-t border-color-border',
      children: [
        WText(
          trans('uptizm.ai.analysis_disclaimer'),
          className: 'text-xs text-fg-disabled',
        ),
      ],
    );
  }
}
