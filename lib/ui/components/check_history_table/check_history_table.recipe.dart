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
      // No `w-full`: the table sizes to its widest row so the component's
      // `overflow-x-auto` wrapper can scroll the whole grid on a narrow phone.
      'table': 'flex flex-col',
      'header': 'flex flex-row items-center border-b border-color-border',
      'row': 'flex flex-row items-center border-b border-color-border',
      'th':
          'w-28 shrink-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase',
      'thStatus':
          'w-48 shrink-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase',
      'thResponse':
          'w-22 shrink-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase text-right',
      'thCode':
          'w-14 shrink-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase text-right',
      'cellId':
          'w-28 shrink-0 py-2.5 font-mono text-sm tabular-nums text-fg-muted',
      'statusCell': 'w-48 shrink-0 py-2.5 flex flex-row items-center',
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
