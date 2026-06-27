import 'package:magic/magic.dart';

/// Builds the slot [WindSlotRecipe] for [CheckHistoryTable].
///
/// Column layout (matches `CheckHistoryTable.tsx`):
///   Time | Region | Status | Response | Code
///
/// The whole table is expressed in Wind layout (no raw Flutter
/// `Row`/`Expanded`/`SizedBox`): the table is a `flex flex-col w-full` column,
/// each header / data row is a `flex flex-row items-center`, and the columns
/// carry their own track sizing in the className: the text columns grow with
/// `flex-1` (status with `flex-2`), while the numeric columns take a fixed
/// `w-*` track and never shrink (`shrink-0`). A child WText/WDiv that carries
/// `flex-1`/`flex-2` is wrapped in an `Expanded` by the parent flex row.
///
/// Slots:
/// - `table`  — outer column; full-width, vertical stack of rows.
/// - `header` — header row: a flex row with the bottom hairline divider.
/// - `row`    — data row: a flex row with the bottom hairline divider.
/// - `th`     — flexible header label (Time, Region); quiet uppercase, muted.
/// - `thStatus`   — Status header label; same style, wider `flex-2` track.
/// - `thResponse` — Response header label; fixed track, right-aligned.
/// - `thCode`     — Code header label; narrower fixed track, right-aligned.
/// - `cellId`     — flexible identifier cell (Time, Region); muted Geist Mono.
/// - `statusCell` — flexible Status cell; a flex row holding the [StatusBadge],
///   left-aligned so the pill hugs its content.
/// - `cellResponse` — fixed Response cell; Geist Mono tabular figures, right.
/// - `cellCode`     — narrower fixed Code cell; same numeric style.
///
/// All flexible cells carry `min-w-0` to prevent overflow on narrow viewports.
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
      'table': 'flex flex-col w-full',
      'header': 'flex flex-row items-center border-b border-color-border',
      'row': 'flex flex-row items-center border-b border-color-border',
      'th':
          'flex-1 min-w-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase',
      'thStatus':
          'flex-2 min-w-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase',
      'thResponse':
          'w-22 shrink-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase text-right',
      'thCode':
          'w-14 shrink-0 py-2.5 text-xs font-medium tracking-wide text-fg-muted uppercase text-right',
      'cellId':
          'flex-1 min-w-0 py-2.5 font-mono text-sm tabular-nums text-fg-muted',
      'statusCell': 'flex-2 min-w-0 py-2.5 flex flex-row items-center',
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
