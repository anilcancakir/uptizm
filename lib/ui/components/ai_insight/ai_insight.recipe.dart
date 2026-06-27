import 'package:magic/magic.dart';

/// Builds the AI insight block [WindRecipe].
///
/// The recipe encodes the `ai`-toned container shell (banner card with the
/// `ai-soft` background and `ai` border). All internal layout tokens
/// (section labels, evidence rows, citation chips) are applied inline in
/// [AiInsight] using fixed semantic strings; only the outer container shape
/// varies through the recipe.
///
/// ### Slot structure
///
/// ```
/// AiInsight
/// └── container (ai-soft bg, ai border, md rounded, p-4, flex-col gap-4)
///     ├── header row (sparkle icon + "Uptizm AI" label + AiConfidenceBadge)
///     ├── tl;dr text (body-md)
///     ├── evidence-for list (each: label + optional source citation)
///     ├── evidence-against list (each: label + optional source citation)
///     └── (optional) suggested actions
/// ```
///
/// Emission order: `base`.
///
/// Token reference:
/// - Container: `bg-ai-soft border border-ai rounded-lg p-4`
/// - Section labels: `text-xs font-semibold text-ai`
/// - Row evidence: `text-sm text-fg`, `text-xs text-fg-muted`
/// - Citations: `text-xs font-mono text-ai-soft-foreground`
const WindRecipe aiInsightRecipe = WindRecipe(
  base: 'flex flex-col gap-4 rounded-lg border border-ai bg-ai-soft p-4',
);
