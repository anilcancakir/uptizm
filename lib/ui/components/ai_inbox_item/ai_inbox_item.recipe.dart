import 'package:magic/magic.dart';

/// Builds the AI inbox row [WindRecipe].
///
/// The recipe encodes the full card shell for an AI-detected anomaly awaiting
/// a human decision. The left stripe uses the `ai` solid token (a positioned
/// element inside the card, clipped by the root `overflow-hidden`) to mark the
/// row as AI-owned without colliding with the card border. Part of the
/// graduated-trust UX: every action is explicit.
///
/// ### Slot structure
///
/// ```
/// AiInboxItem
/// └── root (relative, ai-soft bg, ai border, rounded-lg, p-4 pl-5, flex-col gap-2)
///     ├── stripe (absolute left-0 inset-y-0, w-1, bg-ai; clipped by overflow-hidden)
///     ├── header row (glyph + monitor name + AiConfidenceBadge + time ml-auto)
///     ├── summary paragraph (tldr text)
///     └── actions row (open-incident + dismiss buttons, Wrap for mobile reflow)
/// ```
///
/// Emission order: `base`.
///
/// Token reference:
/// - Root: `relative overflow-hidden rounded-lg border border-ai bg-ai-soft`
/// - Stripe: `absolute top-0 bottom-0 left-0 w-1 bg-ai` (overflow-hidden clips)
/// - Text: `text-sm text-fg`, `text-xs text-fg-muted`, `font-mono text-xs`
const WindRecipe aiInboxItemRecipe = WindRecipe(
  base:
      'relative flex flex-col gap-2 overflow-hidden rounded-lg border border-ai '
      'bg-ai-soft pt-4 pr-4 pb-4 pl-5',
);
