import 'package:magic/magic.dart';

/// Builds the slot [WindSlotRecipe] for [CheckHistoryTable].
///
/// Column layout (matches `CheckHistoryTable.tsx`):
///   Time | Region | Status | Response | Code
///
/// The whole table is expressed in Wind layout (no raw Flutter
/// `Row`/`Expanded`/`SizedBox`): the table is a `flex flex-col` column, each
/// header / data row is a `flex flex-row items-center`, and every column takes a
/// FIXED `w-*` track that never shrinks (`shrink-0`). Fixed tracks give the row
/// a definite intrinsic width (the sum of the columns, ~560px), so the whole
/// table scrolls horizontally as one unit on a narrow phone (the component wraps
/// it in `overflow-x-auto`) instead of squeezing the cells until their text
/// wraps or the status pill overflows. Header and data rows share the same track
/// widths so the columns stay vertically aligned.
///
/// A `LayoutBuilder`-based "stretch to fill, scroll when narrow" wrapper is
/// deliberately avoided: this table renders inside an intrinsic-height measuring
/// ancestor (the detail page equalizes heights with `items-stretch`), and
/// `LayoutBuilder` throws under intrinsic measurement. Fixed tracks + a plain
/// `overflow-x-auto` scroll compose cleanly with intrinsic ancestors.
///
/// Slots:
/// - `table`  — outer column: a vertical stack of rows sized to the widest row.
/// - `header` — header row: a flex row with the bottom hairline divider.
/// - `row`    — data row: a flex row with the bottom hairline divider.
/// - `th`     — Time / Region header label; quiet uppercase, muted, `w-28`.
/// - `thStatus`   — Status header label; wider `w-48` track for the pill.
/// - `thResponse` — Response header label; `w-22` track, right-aligned.
/// - `thCode`     — Code header label; narrower `w-14` track, right-aligned.
/// - `cellId`     — Time / Region identifier cell; muted Geist Mono, `w-28`.
/// - `statusCell` — Status cell; a `w-48` flex row holding the [StatusBadge],
///   left-aligned so the pill hugs its content.
/// - `cellResponse` — Response cell; Geist Mono tabular figures, `w-22`, right.
/// - `cellCode`     — narrower Code cell; same numeric style, `w-14`.
///
/// ```dart
/// final classes = checkHistoryTableRecipe();
/// WDiv(className: classes['table']);
/// ```
Map<String, String> checkHistoryTableRecipe({
  Map<String, String?>? variants,
  Map<String, String>? classNames,
}) {
  const recipe = WindSlotRecipe(
    slots: {
      // `w-full` + `min-w-[680px]`: inside the component's `overflow-x-auto`
      // wrapper this sizes to max(viewport, 680px), so the table FILLS the width
      // on desktop and scrolls as one unit on a narrow phone (< 680px). Time /
      // Region / Status are `flex-1` (equal share) so they distribute the width
      // evenly; the numeric columns stay a fixed right-aligned track so they hug
      // the right edge. No per-column `min-w`: a flex-1 column is a Flutter
      // `Expanded`, which tightly constrains its child and IGNORES `min-w`, so
      // the floor lives on the table instead. 680px keeps each of the 3 flex
      // columns >= ~179px at the floor (680 - 144 fixed, / 3), wide enough for
      // the widest status badge so no cell overflows on a narrow phone.
      'table': 'flex flex-col w-full min-w-[680px]',
      'header': 'flex flex-row items-center border-b border-color-border',
      'row': 'flex flex-row items-center border-b border-color-border',
      'th':
          'flex-1 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase',
      'thStatus':
          'flex-1 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase',
      'thResponse':
          'w-22 shrink-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase text-right',
      'thCode':
          'w-14 shrink-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase text-right',
      'cellId': 'flex-1 py-2.5 font-mono text-sm tabular-nums text-fg-muted',
      'statusCell': 'flex-1 py-2.5 flex flex-row items-center',
      'cellResponse':
          'w-22 shrink-0 py-2.5 font-mono text-sm tabular-nums text-fg text-right',
      'cellCode':
          'w-14 shrink-0 py-2.5 font-mono text-sm tabular-nums text-fg text-right',
    },
    variants: {},
    defaultVariants: {},
  );
  return recipe(variants: variants, classNames: classNames);
}
