---
name: make-component
description: "Scaffold, preview, and verify a new magic_starter component using the design->scaffold->preview->verify loop. Covers make:component, previews:refresh, /preview catalog navigation, dusk screenshot capture, and the component-visual-reviewer sign-off."
when_to_use: "TRIGGER when: creating a new component, adding a variant to an existing component, or verifying a component's visual output."
---

# Make Component

The full lifecycle for authoring a component in this project: from design intent to a reviewed, catalog-visible, token-compliant widget.

---

## OVERVIEW

Every component follows the atomic 4-file folder shape:

```
magic_starter/lib/src/ui/components/<name>/
  <name>.dart           # class <Name> extends StatelessWidget, @immutable
  <name>.recipe.dart    # WindRecipe or WindSlotRecipe
  <name>.preview.dart   # ONE preview widget rendering all variants
  index.dart            # exports <name>.dart + <name>.recipe.dart; NOT the preview
```

The lifecycle is: **DESIGN -> SCAFFOLD -> PREVIEW -> VERIFY**.

---

## STEP 1: DESIGN

Before scaffolding, decide:

- **Component name** (PascalCase, no prefix): `Button`, `Card`, `Badge`, etc.
- **Variant axes** (intent, size, tone, shape, etc.) and their values.
- **Slots** (if the component composes named child regions: `trigger`, `panel`, `header`, `footer`).
- **Token bindings**: which semantic alias keys (`bg-primary`, `text-fg`, `border-color-border`, etc.) map to which visual role.

Consult `magic_example/DESIGN.md` for the authoritative token set. Consult `docs/component-registry.md` to avoid duplicating an existing component.

---

## STEP 2: SCAFFOLD

Generate the 4-file folder with:

```sh
dart run bin/dispatcher.dart make:component <Name> [--variants=intent,size] [--slots]
```

This command:
1. Creates `magic_starter/lib/src/ui/components/<name>/` with the 4 files.
2. Populates the recipe with the requested variant axes.
3. Automatically chains `previews:refresh` so the new preview is registered.

After scaffolding, edit the generated files:

- `<name>.recipe.dart`: fill in token classNames for each variant value. Use semantic aliases only (`bg-primary`, `text-on-primary`, `bg-surface-container`, etc.).
- `<name>.dart`: build the widget body using Wind W-widgets (`WDiv`, `WText`, `WButton`, etc.). Import via `package:magic/magic.dart` (re-exports the full wind barrel).
- `<name>.preview.dart`: render a variant matrix (every combination of variant axes + dark/light). Keep ONE preview class per file.
- `index.dart`: verify the exports include the component class and any variant enums, but NOT the preview class.

If you add the component to the barrel, export it from `magic_starter/lib/magic_starter.dart`:

```sh
# After editing, regenerate the preview registry:
dart run bin/dispatcher.dart previews:refresh
```

---

## STEP 3: PREVIEW

Open the preview catalog to inspect the component visually:

```sh
# In one terminal: start the app
./bin/fsa start --device=chrome

# In another terminal: navigate to the preview catalog
./bin/fsa dusk:navigate --route=/preview
```

The `/preview` catalog lists all registered previews. Each `*.preview.dart` produces one entry. The catalog provides a global dark/light toggle to check both modes.

If the component is not listed, run `previews:refresh` again and hot-reload:

```sh
dart run bin/dispatcher.dart previews:refresh
./bin/fsa reload
```

---

## STEP 4: SCREENSHOT

Capture screenshots for visual review:

```sh
# Light mode screenshot
./bin/fsa dusk:screenshot -o /tmp/<name>-light.jpg

# Toggle to dark (tap the theme toggle in the catalog header), then:
./bin/fsa dusk:screenshot -o /tmp/<name>-dark.jpg
```

Both files must be non-empty and visually distinct (dark != light).

---

## STEP 5: VERIFY (component-visual-reviewer)

Invoke the `component-visual-reviewer` subagent to score the screenshot pair against `DESIGN.md` tokens:

```
Agent({subagent_type: "ac:component-visual-reviewer"}) with:
  - screenshot_light: /tmp/<name>-light.jpg
  - screenshot_dark: /tmp/<name>-dark.jpg
  - design_md: magic_example/DESIGN.md
  - component: <Name>
```

The reviewer returns a numbered delta list. Any token violation (wrong color, missing dark variant, hardcoded hex visible) is blocking. Layout and spacing deltas are advisory.

Fix each blocking delta, re-run `previews:refresh`, hot-reload, re-screenshot, and re-verify. Maximum 3 rounds; stop if no improvement across a full round.

---

## RECIPE AUTHORING RULES

- Emission order is `base ++ variant(definition order) ++ compound(array order) ++ caller`. Never change this.
- Override at the same token granularity: use `px-4` to override `px-*`, not `p-*` overriding `px-*`.
- `defaultVariants` sets the fallback when the caller does not specify an axis.
- Pass enum values as `.name` strings: `ButtonIntent.primary.name` -> `'primary'`.
- Compound variants fire when multiple axes match simultaneously.
- The `className` override parameter allows the caller to bypass the recipe entirely (escape hatch; keep it).

Example WindRecipe shape:

```dart
final buttonRecipe = WindRecipe(
  base: 'inline-flex items-center justify-center font-semibold rounded-md transition-colors',
  variants: {
    'intent': {
      'primary': 'bg-primary text-on-primary',
      'secondary': 'bg-surface-container text-fg border border-color-border',
      'ghost': 'bg-transparent text-fg-muted',
      'destructive': 'bg-destructive text-on-destructive',
    },
    'size': {
      'sm': 'text-xs px-3 py-1.5',
      'md': 'text-sm px-4 py-2',
      'lg': 'text-base px-6 py-3',
    },
  },
  defaultVariants: {'intent': 'primary', 'size': 'md'},
);
```

---

## MIGRATING AN EXISTING WIDGET

If the component replaces an existing `MagicStarter*` widget:

1. Grep the codebase for all callers and existing className-asserting tests.
2. Write a baseline helper (`legacyXClassName(...)`) asserting the recipe output is byte-identical to the old pinned strings BEFORE changing the widget body (TDD red phase).
3. After the recipe is green, move the widget body to call the recipe.
4. Keep the old widget name as a thin re-export alias so callers and tests remain untouched:

```dart
// magic_starter_button.dart (old location)
export 'components/button/button.dart' show Button, ButtonIntent;
```

Never weaken existing assertions to make them pass.

---

## COMMANDS REFERENCE

| Command | What it does |
|---------|-------------|
| `dart run bin/dispatcher.dart make:component <Name> [--variants=a,b] [--slots]` | Scaffold 4-file atomic folder + chain previews:refresh |
| `dart run bin/dispatcher.dart previews:refresh` | Regenerate `_previews.g.dart` from all `*.preview.dart` files |
| `dart run bin/dispatcher.dart design:sync` | Regenerate Wind theme from `DESIGN.md` |
| `dart run bin/dispatcher.dart design:lint` | Validate `DESIGN.md` against design rules |
| `./bin/fsa dusk:navigate --route=/preview` | Open the preview catalog in the running app |
| `./bin/fsa dusk:screenshot -o <file>` | Capture a screenshot of the running app |

---

## REFERENCES

- `magic_example/DESIGN.md` token source
- `docs/component-registry.md` component inventory and anti-patterns
- `.claude/skills/frontend-design/SKILL.md` token/color/type guidance
- `.claude/skills/design-first-workflow/SKILL.md` end-to-end loop for composing screens
- `.claude/agents/component-visual-reviewer.md` visual review subagent
