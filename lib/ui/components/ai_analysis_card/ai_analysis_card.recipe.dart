import 'package:magic/magic.dart';

/// Builds the AI analysis card [WindRecipe].
///
/// The recipe encodes the outer card shell for the incident AI analysis panel.
/// The card shell is supplied by the reused magic_starter [Card] widget; this
/// recipe governs only the inner layout container that holds the sections
/// (tl;dr, insight block, suggested actions, similar incidents) and the
/// footer disclaimer row.
///
/// ### Slot structure
///
/// ```
/// AiAnalysisCard
/// └── Card (surface variant, from magic_starter)
///     └── container (flex-col gap-6)
///         ├── header row (sparkle glyph + "AI analysis" label + trigger + AiConfidenceBadge)
///         ├── AiInsight block (evidence for/against, confidence, citations)
///         ├── suggested-actions section (optional, approval-gated callbacks)
///         ├── similar-incidents section (optional)
///         └── footer disclaimer
/// ```
///
/// Emission order: `base`.
///
/// Token reference:
/// - Container: `flex flex-col gap-6`
/// - Section labels: `text-xs font-semibold text-ai`
/// - Action rows: `flex flex-row gap-3 items-start`
/// - Similar incident rows: `flex flex-row items-center justify-between gap-2`
/// - Footer: `flex flex-row items-center justify-between gap-3 pt-2 border-t border-color-border`
const WindRecipe aiAnalysisCardRecipe = WindRecipe(base: 'flex flex-col gap-6');
