import 'package:magic/magic.dart';

/// Which side of the argument a piece of evidence supports.
///
/// Drives the leading dot color in the evidence grid: [forSide] uses the
/// operational `up` green, [against] uses the outage `down` red.
enum AiEvidenceSide {
  /// Evidence supporting the AI's conclusion (green dot).
  forSide,

  /// Evidence qualifying or contradicting the conclusion (red dot).
  against,
}

/// The side axis key for the evidence-dot recipe (`AiEvidenceSide.<value>`).
const String kAiEvidenceSideAxis = 'side';

/// Panel shell className for [AiAnalysisCard].
///
/// A soft-`ai`-tinted card (Uptizm's signature AI surface) with a hairline
/// `ai` border, large radius, and generous padding. Wind has no gradient/opacity
/// parity with the design source's `from-ai-soft/50 to-surface`, so a solid
/// `bg-ai-soft` tint stands in for the gradient (border + tone over a shadow,
/// per the port discipline).
const String aiAnalysisCardPanelClassName =
    'w-full rounded-xl border border-ai bg-surface-container-high p-5';

/// Evidence-dot recipe: a small solid dot whose color marks the side.
const WindRecipe aiAnalysisCardDotRecipe = WindRecipe(
  base: 'size-1.5 rounded-full',
  variants: {
    kAiEvidenceSideAxis: {'forSide': 'bg-up', 'against': 'bg-down'},
  },
  defaultVariants: {kAiEvidenceSideAxis: 'forSide'},
);

/// Inner card className for a single suggested-action / similar-incident row.
///
/// A bordered white-surface card layered on the soft-`ai` panel, matching the
/// design source's `rounded-md border border-border bg-surface p-3`.
const String aiAnalysisCardRowClassName =
    'w-full rounded-md border border-color-border bg-surface-container p-3';
