import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/incidents.dart';
import '../ai_confidence_badge/index.dart';

/// **AI Insight Block**
///
/// Renders the full graduated-trust AI analysis for an incident:
/// - An `ai`-toned container using the `bg-ai-soft` / `border-ai` family.
/// - A header row with a sparkle glyph, "Uptizm AI" label, and an
///   [AiConfidenceBadge].
/// - A tl;dr paragraph taken from [IncidentAi.tldr].
/// - An evidence-FOR list showing [AiEvidence.label] and
///   [AiEvidence.source] for each supporting data point.
/// - An evidence-AGAINST list with the same structure for qualifying
///   or contradicting data points.
///
/// Both evidence lists are always rendered when non-empty so the caller cannot
/// accidentally omit one and violate the graduated-trust principle.
///
/// ### Graduated-trust UX
///
/// Every AI surface in Uptizm shows evidence for AND against, a confidence
/// level, and source citations so operators can form their own judgment before
/// acting. [AiInsight] enforces this contract structurally: the three surfaces
/// (confidence badge, evidence-for, evidence-against) are always rendered when
/// data is present.
///
/// ### Example Usage:
///
/// ```dart
/// // Full AI analysis from a fixture incident
/// final ai = incidents.first.ai!;
/// AiInsight(ai: ai)
///
/// // Inside a detail page column
/// if (incident.ai != null)
///   AiInsight(ai: incident.ai!)
/// ```
@immutable
class AiInsight extends StatelessWidget {
  /// The AI analysis data to render.
  final IncidentAi ai;

  /// Creates an [AiInsight] block for the given [ai] analysis.
  const AiInsight({super.key, required this.ai});

  @override
  Widget build(BuildContext context) {
    // Visual-only container classes (the flex layout is handled by an explicit
    // Flutter Column below so leaf text wraps within a bounded width on narrow
    // columns instead of overflowing in a Wind flex regime).
    final List<Widget> sections = [
      // Header: sparkle glyph + label + confidence badge.
      _buildHeader(),
      // TL;DR summary paragraph.
      _buildTldr(),
      // Evidence-FOR list (always rendered when list is non-empty).
      if (ai.evidenceFor.isNotEmpty)
        _buildEvidenceSection(
          label: trans('uptizm.ai.evidence'),
          evidenceList: ai.evidenceFor,
        ),
      // Evidence-AGAINST list (graduated trust: show both sides).
      if (ai.evidenceAgainst.isNotEmpty)
        _buildEvidenceSection(
          label: trans('uptizm.ai.evidence_against'),
          evidenceList: ai.evidenceAgainst,
        ),
    ];

    return WDiv(
      className: 'rounded-lg border border-ai bg-ai-soft p-4',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < sections.length; i++) ...[
            if (i > 0) const SizedBox(height: 16),
            sections[i],
          ],
        ],
      ),
    );
  }

  /// Builds the header row: sparkle glyph + label on the left, confidence
  /// badge on the right. The left cluster is Expanded so a long label wraps
  /// rather than overflowing on a narrow column.
  Widget _buildHeader() {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Expanded(
          child: Row(
            children: [
              WText('✦', className: 'text-sm font-medium text-ai'),
              const SizedBox(width: 8),
              Flexible(
                child: WText(
                  trans('uptizm.ai.ai_detected'),
                  className: 'text-sm font-semibold text-ai',
                ),
              ),
            ],
          ),
        ),

        const SizedBox(width: 8),

        // Confidence badge: graduated-trust anchor (high/medium/low).
        AiConfidenceBadge(ai.confidence),
      ],
    );
  }

  /// Builds the tl;dr section: a label and the summary paragraph.
  Widget _buildTldr() {
    return WDiv(
      className: 'flex flex-col gap-1',
      children: [
        WText(
          trans('uptizm.ai.tldr'),
          className: 'text-xs font-semibold text-ai',
        ),
        WText(ai.tldr, className: 'text-sm text-fg'),
      ],
    );
  }

  /// Builds a labeled evidence section (either supporting or qualifying).
  ///
  /// [label] is the section heading displayed above the list.
  /// [evidenceList] contains the [AiEvidence] items to render.
  Widget _buildEvidenceSection({
    required String label,
    required List<AiEvidence> evidenceList,
  }) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        // Section label.
        WText(label, className: 'text-xs font-semibold text-ai'),

        // Evidence rows.
        for (final evidence in evidenceList) _buildEvidenceRow(evidence),
      ],
    );
  }

  /// Renders a single evidence row: a label headline, an expanded detail
  /// line, and an optional source citation in mono font.
  Widget _buildEvidenceRow(AiEvidence evidence) {
    return WDiv(
      className: 'flex flex-col gap-0.5 pl-2',
      children: [
        // Evidence label (the short headline).
        WText(evidence.label, className: 'text-sm text-fg'),

        // Detail line (expanded explanation, muted).
        WText(evidence.detail, className: 'text-xs text-fg-muted'),

        // Optional source citation in mono font using the ai foreground.
        if (evidence.source != null)
          WText(
            evidence.source!,
            className: 'font-mono text-xs text-ai-soft-foreground',
          ),
      ],
    );
  }
}
