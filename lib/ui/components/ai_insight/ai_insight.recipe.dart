import 'package:magic/magic.dart';

/// The tone axis key for [aiInsightRecipe].
const String kAiInsightToneAxis = 'tone';

/// Builds the AI insight [WindSlotRecipe].
///
/// Ported from `ai-insight.variants.ts`. Two tones:
/// - `banner` — prominent card with ai-soft gradient background and rounded-xl
///   border; glyph tile is an 8-unit rounded-lg ai-soft square.
/// - `inline` — quiet sparkle + muted line; no background or border; glyph
///   nudged to `mt-0.5` so it aligns to the first line of text.
///
/// ### Slot structure
///
/// ```
/// root      — flex items-start gap-2.5 (+ banner overlay)
/// glyphWrap — shrink-0 text-ai (+ per-tone size/shape)
/// glyph     — size-4 (inline) / size-5 (banner)
/// body      — min-w-0 flex-1
/// text      — text-sm leading-relaxed (color per tone)
/// meta      — mt-2 flex flex-row flex-wrap items-center gap-2
/// ```
///
/// Emission order: `base ++ tone-variant ++ caller`.
const WindSlotRecipe aiInsightRecipe = WindSlotRecipe(
  slots: {
    'root': 'flex items-start gap-2.5',
    'glyphWrap': 'shrink-0 text-ai',
    'glyph': 'size-4',
    'body': 'min-w-0 flex-1',
    'text': '',
    'meta': 'mt-2 flex flex-row flex-wrap items-center gap-2',
  },
  variants: {
    kAiInsightToneAxis: {
      'banner': {
        'root': 'gap-3 rounded-xl border border-ai-soft bg-ai-soft p-4',
        'glyphWrap':
            'size-8 flex items-center justify-center rounded-lg bg-ai-soft',
        'glyph': 'size-5',
        'text': 'text-sm leading-relaxed text-fg',
      },
      'inline': {
        'glyphWrap': 'mt-0.5',
        'text': 'text-sm leading-relaxed text-fg-muted',
      },
    },
  },
  defaultVariants: {kAiInsightToneAxis: 'inline'},
);
