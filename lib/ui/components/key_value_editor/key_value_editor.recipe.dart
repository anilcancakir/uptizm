import 'package:magic/magic.dart';

/// Builds the [WindSlotRecipe] for the KeyValueEditor component.
///
/// Ported from the design lab `key-value-editor.variants.ts`: a vertical stack
/// of key/value input rows, each with a square ghost remove control, plus a
/// trailing add button (rendered by the component via magic_starter Button).
///
/// Token divergences (uptizm neutral aliases): `text-muted-foreground` ->
/// `text-fg-muted`, `hover:bg-muted` -> `hover:bg-surface-container`.
///
/// ### Slot structure
/// ```
/// root   — vertical stack of rows + the add button
/// remove — square ghost icon button that drops a row
/// ```
const WindSlotRecipe keyValueEditorRecipe = WindSlotRecipe(
  slots: {
    'root': 'flex flex-col gap-2',
    'remove':
        'flex flex-row size-10 shrink-0 items-center justify-center '
        'rounded-md text-fg-muted hover:bg-surface-container hover:text-fg',
  },
);
