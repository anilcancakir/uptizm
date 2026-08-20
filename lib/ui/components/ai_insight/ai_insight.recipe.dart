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
/// ### Banner fill
///
/// The React original tints the banner with a `from-ai-soft/50 to-surface`
/// gradient. Wind cannot apply an opacity modifier to an alias token, so the
/// banner uses the dedicated `bg-ai-wash` token (ai-soft pre-blended at 50%
/// over the surface): paler than the full `bg-ai-soft` glyph tile, so the tile
/// still pops, and framed by the `border-ai-soft` border.
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
      // The banner spends its whole width on one or two sentences, so a phone
      // pays for the framing twice: 16pt of padding plus a 32pt tile around a
      // sparkle. Both step down below `lg` and back up above it, which keeps
      // the identity (the wash, the border, the tile) without giving a single
      // sentence 90pt of a 874pt screen.
      'banner': {
        'root': 'gap-2.5 rounded-xl border border-ai-soft bg-ai-wash p-3 '
            'lg:gap-3 lg:p-4',
        'glyphWrap': 'size-7 flex items-center justify-center rounded-lg '
            'bg-ai-soft lg:size-8',
        'glyph': 'text-base text-ai lg:text-lg',
        'text': 'text-sm leading-relaxed text-fg text-left',
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
