import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/support/incident_types.dart'
    show AiEvidence, AiSimilarIncident, AiSuggestedAction, IncidentAi;
import '../ai_confidence_badge/index.dart';
import 'ai_analysis_card.recipe.dart';

/// **Incident AI Analysis Panel**
///
/// Uptizm's signature AI surface for the incident / monitor detail view. Ported
/// from the design source 1:1 in structure: a soft-`ai`-bordered panel with a
/// glyph-tile header (heading + trigger + confidence badge), a one-paragraph
/// tl;dr, a two-column evidence grid (FOR with green dots, AGAINST with red
/// dots), bordered "recommended next steps" cards, bordered "similar incidents"
/// cards, and a footer disclaimer with Helpful / Not helpful feedback.
///
/// Graduated trust is enforced structurally: evidence for AND against is always
/// shown together, suggested actions are advisory (the [onActionTap] callback
/// never fires on its own), and the operator rates the analysis.
@immutable
class AiAnalysisCard extends StatelessWidget {
  /// The AI analysis payload to render.
  final IncidentAi ai;

  /// Called when the operator explicitly taps a suggested-action card.
  ///
  /// Never fires automatically (graduated trust). When `null`, action cards are
  /// non-interactive display items.
  final void Function(AiSuggestedAction action)? onActionTap;

  /// Called when the operator rates the analysis (`true` = helpful).
  final void Function(bool helpful)? onFeedback;

  /// Creates an [AiAnalysisCard] for the given [ai] analysis.
  const AiAnalysisCard({
    super.key,
    required this.ai,
    this.onActionTap,
    this.onFeedback,
  });

  @override
  Widget build(BuildContext context) {
    final hasEvidence =
        ai.evidenceFor.isNotEmpty || ai.evidenceAgainst.isNotEmpty;

    // Explicit Flutter Column scaffolds the panel so leaf rows get a bounded
    // width and wrap cleanly on a narrow column (a Wind flex-col would hand
    // descendants an unbounded-width regime).
    return WDiv(
      className: aiAnalysisCardPanelClassName,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _buildHeader(),
          const SizedBox(height: 16),
          WText(ai.tldr, className: 'text-base leading-relaxed text-fg'),
          if (hasEvidence) ...[
            const SizedBox(height: 16),
            _buildEvidenceGrid(context),
          ],
          if (ai.suggestedActions.isNotEmpty) ...[
            const SizedBox(height: 16),
            _buildSection(trans('uptizm.ai.recommended_next_steps'), [
              for (final a in ai.suggestedActions) _buildActionCard(a),
            ]),
          ],
          if (ai.similarIncidents.isNotEmpty) ...[
            const SizedBox(height: 16),
            _buildSection(trans('uptizm.ai.similar_incidents'), [
              for (final s in ai.similarIncidents) _buildSimilarCard(s),
            ]),
          ],
          const SizedBox(height: 16),
          _buildFooter(),
        ],
      ),
    );
  }

  // -- Header ----------------------------------------------------------------

  Widget _buildHeader() {
    // Wind flex-row: the size-8 glyph tile is width-fixed (not greedy), a
    // `flex-1` spacer absorbs the slack so the confidence badge is pushed to the
    // far right (the design source's `badgeSlot: ml-auto`). Wind sizes the badge
    // pill to content within its own flex layout (a Flutter Row would treat the
    // Wind pill as an infinite-width child and overflow).
    return WDiv(
      className: 'flex flex-row items-center gap-2.5',
      children: [
        // Glyph tile: a rounded ai-soft square with the sparkle centered.
        WDiv(
          className: 'size-8 rounded-lg bg-ai-soft',
          child: const Center(
            child: WText('✦', className: 'text-ai text-base'),
          ),
        ),
        WText(
          trans('uptizm.ai.analysis_title'),
          className: 'text-base font-semibold text-fg',
        ),
        // Trigger grows on desktop (pushing the badge right) and shrinks +
        // truncates on a narrow column (min-w-0) so the header never overflows.
        WText(
          ai.trigger,
          className: 'flex-1 min-w-0 truncate text-sm text-fg-muted',
        ),
        AiConfidenceBadge(ai.confidence),
      ],
    );
  }

  // -- Evidence grid ---------------------------------------------------------

  Widget _buildEvidenceGrid(BuildContext context) {
    final forColumn = ai.evidenceFor.isEmpty
        ? null
        : _buildEvidenceColumn(
            trans('uptizm.ai.evidence'),
            AiEvidenceSide.forSide,
            ai.evidenceFor,
          );
    final againstColumn = ai.evidenceAgainst.isEmpty
        ? null
        : _buildEvidenceColumn(
            trans('uptizm.ai.evidence_against'),
            AiEvidenceSide.against,
            ai.evidenceAgainst,
          );
    final columns = [?forColumn, ?againstColumn];

    // Two columns side by side on wide screens, stacked on mobile.
    if (wScreenIs(context, 'sm') && columns.length == 2) {
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: columns[0]),
          const SizedBox(width: 16),
          Expanded(child: columns[1]),
        ],
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < columns.length; i++) ...[
          if (i > 0) const SizedBox(height: 16),
          columns[i],
        ],
      ],
    );
  }

  Widget _buildEvidenceColumn(
    String label,
    AiEvidenceSide side,
    List<AiEvidence> items,
  ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        WText(
          label,
          className:
              'text-xs font-medium uppercase tracking-wide text-fg-muted',
        ),
        const SizedBox(height: 8),
        for (final item in items) ...[
          _buildEvidenceItem(item, side),
          const SizedBox(height: 8),
        ],
      ],
    );
  }

  Widget _buildEvidenceItem(AiEvidence item, AiEvidenceSide side) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Leading dot (green for-side / red against-side); nudged to the text
        // baseline. Bounded by a SizedBox so the childless WDiv keeps its 6px
        // box (an empty Wind div otherwise collapses to zero size).
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: SizedBox(
            width: 6,
            height: 6,
            child: WDiv(
              className: aiAnalysisCardDotRecipe(
                variants: {kAiEvidenceSideAxis: side.name},
              ),
            ),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              WText(item.label, className: 'text-sm text-fg'),
              if (item.detail.isNotEmpty)
                WText(item.detail, className: 'text-xs text-fg-muted'),
              if (item.source case final source?)
                WText(
                  source,
                  className: 'font-mono text-xs text-info-soft-foreground',
                ),
            ],
          ),
        ),
      ],
    );
  }

  // -- Sections (actions / similar) ------------------------------------------

  Widget _buildSection(String label, List<Widget> cards) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        WText(
          label,
          className:
              'text-xs font-medium uppercase tracking-wide text-fg-muted',
        ),
        const SizedBox(height: 8),
        for (var i = 0; i < cards.length; i++) ...[
          if (i > 0) const SizedBox(height: 8),
          cards[i],
        ],
      ],
    );
  }

  Widget _buildActionCard(AiSuggestedAction action) {
    final card = WDiv(
      className: aiAnalysisCardRowClassName,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 1),
            child: WIcon(Icons.arrow_forward, className: 'text-ai text-base'),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                WText(action.title, className: 'text-sm text-fg'),
                if (action.rationale.isNotEmpty)
                  WText(action.rationale, className: 'text-xs text-fg-muted'),
              ],
            ),
          ),
        ],
      ),
    );

    if (onActionTap == null) return card;
    return WButton(onTap: () => onActionTap!(action), child: card);
  }

  Widget _buildSimilarCard(AiSimilarIncident incident) {
    final percent = '${(incident.similarity * 100).round()}%';
    return WDiv(
      className: aiAnalysisCardRowClassName,
      child: Row(
        children: [
          Expanded(child: WText(incident.title, className: 'text-sm text-fg')),
          const SizedBox(width: 12),
          WText(
            percent,
            className: 'font-mono text-xs tabular-nums text-fg-muted',
          ),
        ],
      ),
    );
  }

  // -- Footer ----------------------------------------------------------------

  Widget _buildFooter() {
    // Disclaimer note on the left (grows), feedback buttons on the right,
    // matching the design source's `footer: sm:flex-row ... footerNote: flex-1`.
    return WDiv(
      className: 'pt-3 border-t border-ai',
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Expanded(
            child: WText(
              trans('uptizm.ai.analysis_disclaimer'),
              className: 'text-xs leading-relaxed text-fg-muted',
            ),
          ),
          const SizedBox(width: 12),
          _feedbackButton(trans('uptizm.ai.helpful'), true),
          const SizedBox(width: 4),
          _feedbackButton(trans('uptizm.ai.not_helpful'), false),
        ],
      ),
    );
  }

  Widget _feedbackButton(String label, bool helpful) {
    return WButton(
      onTap: onFeedback == null ? null : () => onFeedback!(helpful),
      className:
          'px-3 py-1.5 rounded-md text-sm font-medium text-fg-muted '
          'hover:bg-surface-container',
      child: WText(label, className: 'text-sm font-medium text-fg-muted'),
    );
  }
}
