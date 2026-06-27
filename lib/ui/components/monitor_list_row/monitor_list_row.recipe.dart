import 'package:magic/magic.dart';

/// Top-level const [WindRecipe] for the monitor list row container and layout.
///
/// Covers the row shell (border, rounded, hover surface, padding). Status
/// coloring lives entirely in the composed [StatusBadge] and [UptimeBar];
/// this recipe governs structure only.
///
/// Emission order: `base ++ variant ++ compound ++ caller`.
///
/// ### Example:
/// ```dart
/// final rootClass = monitorListRowRecipe();
/// WAnchor(
///   onTap: onTap,
///   child: WDiv(className: rootClass, children: [...]),
/// );
/// ```
const WindRecipe monitorListRowRecipe = WindRecipe(
  base:
      'flex flex-col gap-2 rounded-lg border border-color-border '
      'bg-surface px-4 py-3 hover:bg-surface-container transition-colors '
      'min-h-[44px]',
  variants: {},
  defaultVariants: {},
);

/// Resolves the named slot classNames for [MonitorListRow].
///
/// Returns a `Map<String, String>` keyed by slot name so each sub-region of
/// the row can be styled consistently without inline string duplication.
///
/// Pass a [className] string to append extra classes to the `root` slot.
///
/// Slots:
/// - `root`     — full tappable row shell (border, padding, hover).
/// - `topRow`   — horizontal row: name/URL column + badge + metric.
/// - `main`     — left-side flex column: name + URL.
/// - `name`     — monitor display name (truncated, medium weight).
/// - `url`      — probed URL below the name (Geist Mono, muted, truncated).
/// - `metric`   — trailing latency figure (tabular-nums, Geist Mono).
/// - `meta`     — bottom flex row: region tags + last-check label.
/// - `metaItem` — a single meta text piece (xs, muted).
///
/// ```dart
/// final slots = monitorListRowSlots();
/// WDiv(className: slots['main'], children: [...]);
/// ```
Map<String, String> monitorListRowSlots({String? className}) {
  const recipe = WindSlotRecipe(
    slots: {
      'root':
          'flex flex-col gap-2 rounded-lg border border-color-border '
          'bg-surface px-4 py-3 hover:bg-surface-container transition-colors '
          'min-h-[44px]',
      'topRow': 'flex flex-row items-center gap-3',
      'main': 'flex flex-col gap-0.5 min-w-0 flex-1',
      'name': 'truncate text-sm font-medium text-fg',
      'url': 'truncate font-mono text-xs text-fg-muted',
      'metric': 'tabular-nums font-mono text-sm text-fg shrink-0',
      'meta': 'flex flex-row gap-2 flex-wrap',
      'metaItem': 'text-xs text-fg-muted',
    },
    variants: {},
    defaultVariants: {},
  );
  return recipe(classNames: className != null ? {'root': className} : null);
}
