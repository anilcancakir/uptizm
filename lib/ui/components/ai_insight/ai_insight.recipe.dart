import 'package:magic/magic.dart';

/// The tone axis key for [aiInsightRecipe].
const String kAiInsightToneAxis = 'tone';

/// Builds the AI insight [WindSlotRecipe].
///
/// Ported from `ai-insight.variants.ts`. Two tones:
/// - `banner` — prominent card framed by the ai border with a saturated
///   ai-soft glyph tile; the fill is neutral so the tile reads (see below).
/// - `inline` — quiet sparkle + muted line; no background or border; glyph
///   nudged to `mt-0.5` so it aligns to the first line of text.
///
/// ### Banner fill divergence
///
/// The React original tints the banner with a `from-ai-soft/50 to-surface`
/// gradient. Wind has no opacity modifier or gradient-to-surface token, and a
/// solid `bg-ai-soft` fill both over-saturates the card AND hides the equally
/// ai-soft glyph tile against it. So the banner fill is the neutral
/// `bg-surface-container`; the ai signal is carried by the border, the
/// saturated tile, and the confidence badge instead of a tinted wash.
///
/// ### Slot structure
///
/// ```
/// root      — flex items-start gap-2.5 (+ banner card overlay)
/// glyphWrap — shrink-0 (+ banner: size-8 ai-soft tile)
/// glyph     — text-ai sparkle, text-sm (inline) / text-base (banner)
/// body      — min-w-0 flex-1
/// text      — text-sm leading-relaxed (color per tone)
/// meta      — mt-2 flex flex-row flex-wrap items-center gap-2
/// ```
///
/// Emission order: `base ++ tone-variant ++ caller`.
const WindSlotRecipe aiInsightRecipe = WindSlotRecipe(
  slots: {
    'root': 'flex items-start gap-2.5',
    'glyphWrap': 'shrink-0',
    'glyph': 'text-ai',
    'body': 'min-w-0 flex-1',
    'text': '',
    'meta': 'mt-2 flex flex-row flex-wrap items-center gap-2',
  },
  variants: {
    kAiInsightToneAxis: {
      'banner': {
        'root':
            'gap-3 rounded-xl border border-ai-soft bg-surface-container p-4',
        'glyphWrap':
            'size-8 flex items-center justify-center rounded-lg bg-ai-soft',
        'glyph': 'text-base text-ai',
        'text': 'text-sm leading-relaxed text-fg',
      },
      'inline': {
        'glyphWrap': 'mt-0.5',
        'glyph': 'text-sm text-ai',
        'text': 'text-sm leading-relaxed text-fg-muted',
      },
    },
  },
  defaultVariants: {kAiInsightToneAxis: 'inline'},
);
