import 'package:magic/magic.dart';

/// The axis key for the check history table slot recipe.
const String kCheckHistoryTableStatusAxis = 'status';

/// Builds the slot [WindSlotRecipe] for [CheckHistoryTable].
///
/// Slots:
/// - `table` — the outer column that lays out all check rows.
/// - `row` — one check result row; separated from siblings by a hairline border.
/// - `cell` — a generic table cell with vertical rhythm padding.
/// - `cellMono` — a numeric cell (response time, status code) in Geist Mono
///   tabular figures, right-aligned.
/// - `cellMuted` — a muted mono cell (timestamp, region) for secondary identifiers.
/// - `header` — the header row; quiet uppercase labels with a bottom hairline.
/// - `th` — an individual header cell.
/// - `statusCell` — the cell that wraps the [StatusDot] + status label pair.
///
/// No top-level variants: the table is mobile-first and applies a single
/// responsive layout (column of rows, no horizontal scroll). All cells carry
/// `min-w-0` to prevent overflow from wide content.
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
      'header': 'flex flex-row border-b border-color-border',
      'th':
          'py-2.5 min-w-0 text-xs font-medium text-fg-disabled tabular-nums uppercase',
      'thRight':
          'py-2.5 min-w-0 text-xs font-medium text-fg-disabled uppercase',
      'row': 'flex flex-row items-center border-b border-color-border',
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
