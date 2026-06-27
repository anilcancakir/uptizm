import 'package:magic/magic.dart';

/// Builds the slot [WindSlotRecipe] for [CheckHistoryTable].
///
/// Column layout (matches `CheckHistoryTable.tsx`):
///   Time | Region | Status | Response | Code
///
/// Slots:
/// - `table` — outer column; full-width, no horizontal overflow.
/// - `header` — decoration wrapper (bottom hairline divider) for the header
///   row. The caller composes an explicit Flutter [Row] as the child so
///   [Expanded]/[SizedBox] cells are bounded correctly.
/// - `th` — left-aligned header label; quiet uppercase, muted, tracking-wide.
/// - `thRight` — right-aligned header label; same quiet style for numeric columns.
/// - `row` — decoration wrapper (bottom hairline divider) for a data row. The
///   caller composes an explicit Flutter [Row] as the child.
/// - `cell` — generic table cell with vertical rhythm padding.
/// - `cellMuted` — muted mono cell for secondary identifiers (timestamp, region).
/// - `cellMono` — numeric cell (response time, status code) in Geist Mono,
///   tabular figures, right-aligned.
/// - `statusCell` — [WDiv] wrapping [StatusDot] + status label in a row.
///
/// No top-level variants: single responsive layout, column of rows, no
/// horizontal scroll. All variable-width cells carry `min-w-0` to prevent
/// overflow on narrow viewports.
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
      'header': 'border-b border-color-border',
      'th':
          'py-2.5 min-w-0 text-xs font-medium tracking-wide text-fg-muted uppercase',
      'thRight':
          'py-2.5 min-w-0 text-xs font-medium tracking-wide text-fg-muted uppercase text-right',
      'row': 'border-b border-color-border',
      'cell': 'py-2.5 min-w-0 text-sm text-fg',
      'cellMuted':
          'py-2.5 min-w-0 font-mono text-sm tabular-nums text-fg-muted',
      'cellMono':
          'py-2.5 min-w-0 font-mono text-sm tabular-nums text-fg text-right',
      'statusCell': 'py-2.5 min-w-0 flex flex-row items-center gap-2',
    },
    variants: {},
    defaultVariants: {},
  );
  return recipe(variants: variants, classNames: classNames);
}
