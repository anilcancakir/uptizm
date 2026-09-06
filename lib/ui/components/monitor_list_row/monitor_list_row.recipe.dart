import 'package:magic/magic.dart';

/// Resolves the named slot classNames for [MonitorListRow].
///
/// Returns a `Map<String, String>` keyed by slot name so each sub-region of
/// the row can be styled consistently without inline string duplication.
///
/// Pass a [className] string to append extra classes to the `root` slot.
///
/// Slots:
/// - `root`   — full tappable row shell + horizontal layout (border, padding,
///   hover): name/URL column, latency metric, status badge.
/// - `main`   — left-side flex column: name + URL.
/// - `name`   — monitor display name (truncated, medium weight).
/// - `url`    — probed URL below the name (Geist Mono, muted, truncated).
/// - `metric` — trailing latency figure (tabular-nums, Geist Mono).
///
/// ```dart
/// final slots = monitorListRowSlots();
/// WDiv(className: slots['main'], children: [...]);
/// ```
Map<String, String> monitorListRowSlots({String? className}) {
  const recipe = WindSlotRecipe(
    slots: {
      'root':
          'flex flex-row items-center gap-3 rounded-lg border '
          'border-color-border bg-surface px-4 py-3 '
          'hover:bg-surface-container transition-colors min-h-[44px]',
      'main': 'flex flex-col gap-0.5 min-w-0 flex-1',
      'name': 'truncate text-sm font-medium text-fg',
      'url': 'truncate font-mono text-xs text-fg-muted',
      'metric':
          'w-16 shrink-0 text-right tabular-nums font-mono text-sm text-fg',
    },
    variants: {},
    defaultVariants: {},
  );
  return recipe(classNames: className != null ? {'root': className} : null);
}
