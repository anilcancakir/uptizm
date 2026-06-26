---
paths:
  - "lib/**"
---

# Design Rules (UI surface)

These rules apply whenever you touch any file under `lib/`. They complement `CLAUDE.md` with the implementation-level specifics.

## Atomic Component Folder Contract

Every component in the `magic_starter` generic library lives in a 4-file atomic folder:

```
magic_starter/lib/src/ui/components/<name>/
  <name>.dart           # class <Name> extends StatelessWidget, @immutable
  <name>.recipe.dart    # WindRecipe or WindSlotRecipe
  <name>.preview.dart   # ONE preview widget rendering all variant x state combos
  index.dart            # exports <name>.dart + <name>.recipe.dart (NOT preview)
```

- Folder and file names are `lower_snake_case` (dotted suffixes `.recipe.dart`/`.preview.dart` are valid).
- Component class name is unprefixed `UpperCamelCase` (`card.dart` -> `class Card`).
- `index.dart` exports the component class, variant enums, and the recipe. Never export the preview file (it is dev-only).
- `previews:refresh` discovers components by scanning `*.preview.dart` files. One preview class per file, no exceptions.

To scaffold: `dart run bin/dispatcher.dart make:component <Name> [--variants=intent,size] [--slots]`

## WindRecipe Usage

All variant logic lives in a `WindRecipe` (or `WindSlotRecipe` for slot-based components):

```dart
final myRecipe = WindRecipe(
  base: 'flex items-center gap-2',
  variants: {
    'intent': {
      'primary': 'bg-primary text-on-primary',
      'secondary': 'bg-surface-container text-fg border border-color-border',
      'ghost': 'text-fg',
    },
    'size': {
      'sm': 'px-3 py-1.5 text-xs',
      'md': 'px-4 py-2 text-sm',
      'lg': 'px-5 py-2.5 text-base',
    },
  },
  defaultVariants: {'intent': 'primary', 'size': 'md'},
);
```

- Emission order is always: `base ++ variant (definition order) ++ compound ++ caller`. Never sort or deduplicate.
- Pass variant values as strings matching the map keys. Pass `null` to clear a default.
- The caller `className` argument appends last; it can override variant output at the same granularity.
- Import `WindRecipe` via `package:magic/magic.dart` inside `magic_starter` files (it re-exports the wind barrel). Direct `package:fluttersdk_wind/...` imports trip `depend_on_referenced_packages`.

## Token-Only Rule

All colors go through semantic alias keys defined in `DESIGN.md`. Never use raw hex, `Color(0xFF...)`, or `Colors.*` in component or view code.

The 17 semantic alias keys:

| Key | Role |
|-----|------|
| `bg-surface` | Page background |
| `bg-surface-container` | Card, panel background |
| `bg-surface-container-high` | Input background, nested panels |
| `text-fg` | Primary text |
| `text-fg-muted` | Secondary text |
| `text-fg-disabled` | Disabled/meta text |
| `bg-primary` | Brand action background |
| `text-on-primary` | Text on brand action surface |
| `bg-primary-container` | Tinted brand surface |
| `bg-accent` | Secondary accent |
| `border-color-border` | Dividers, card borders |
| `border-color-border-subtle` | Hairline borders |
| `bg-destructive` | Danger action background |
| `text-on-destructive` | Text on danger surface |
| `bg-destructive-container` | Tinted danger surface |
| `bg-success` | Success tone |
| `bg-warning` | Warning tone |

Each alias expands to a `'<light> dark:<dark>'` pair. Every `className` that sets a color MUST include the `dark:` counterpart. The alias system handles this automatically when you use the keys above.

To add or change a semantic token: edit `DESIGN.md` and run `dart run bin/dispatcher.dart design:sync`.

## Preview-Required Rule

No component ships without a preview widget.

- The preview file (`<name>.preview.dart`) must render every variant x state combination so the catalog shows the full range.
- After adding or modifying a preview, regenerate the catalog: `dart run bin/dispatcher.dart previews:refresh`
- Verify dark/light parity by navigating to `/preview` in debug mode: `./bin/fsa dusk:navigate --route=/preview`
- Take light and dark screenshots and run the `component-visual-reviewer` agent (`.claude/agents/component-visual-reviewer.md`) before marking a component ship-ready.

## Material Import Discipline

Component files that share a name with a Material widget (`Card`, `Switch`, `Badge`, `Tooltip`, `Checkbox`, etc.) must import Flutter as:

```dart
import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;  // only if icons are needed
```

Never `import 'package:flutter/material.dart'` without `show`. Build exclusively on Wind W-widgets inside component bodies.

## Release Boundary

Preview files (`*.preview.dart`) and the generated `_previews.g.dart` are dev-only. They are excluded from release builds through the `magic_devtools` dev-package boundary and `kDebugMode`/`kReleaseMode` const-fold. Never import a preview file from production code.
