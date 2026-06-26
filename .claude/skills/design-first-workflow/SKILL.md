---
name: design-first-workflow
description: "End-to-end design-first loop for building screens and composing components: design token binding -> scaffold -> preview dark/light -> compose -> wire to controller -> dusk verify. Encodes the CREATE->SCREENSHOT->ANALYZE->FIX->VERIFY self-heal cycle with hard limits."
when_to_use: "TRIGGER when: building a new screen, composing existing components into a view, wiring a view to a controller, or running a full design-first verification pass."
---

# Design-First Workflow

The end-to-end loop for building a screen or view in this project: from DESIGN.md token decisions to a dusk-verified, dark/light-parity-confirmed, controller-wired result.

---

## THE LOOP

```
DESIGN -> SCAFFOLD -> PREVIEW -> COMPOSE -> WIRE -> VERIFY
```

For iterative fixes after the first preview:

```
SCREENSHOT -> ANALYZE -> FIX -> VERIFY
maximum 3 rounds; stop if no improvement across a full round
```

---

## PHASE 1: DESIGN

Read `magic_example/DESIGN.md` before touching any code.

Decide:
1. Which semantic tokens govern this screen (background, text, interactive elements).
2. Which components from `docs/component-registry.md` cover the UI needs.
3. The visual hierarchy: what is primary, secondary, tertiary on this screen.
4. Responsive behavior: narrow (single column) vs `md` and wider (sidebar + content, or row layout).

Do NOT start coding until the token bindings are clear. A screen that uses the right tokens will automatically update when the brand changes; a screen with hardcoded hex will not.

---

## PHASE 2: SCAFFOLD (new screens)

For a new standalone screen, create the view file:

```sh
dart run bin/dispatcher.dart make:component <ScreenName>Preview [--slots]
```

Or author the view file directly if it follows an existing auth/profile/settings pattern. Views live under `magic_starter/lib/src/ui/views/<feature>/`. Components live under `magic_starter/lib/src/ui/components/<name>/`.

For a new component within the screen, follow the `make-component` skill.

---

## PHASE 3: PREVIEW (dark + light)

Every screen must be previewed in both light and dark before wiring to a controller.

Start the app and navigate to the preview catalog:

```sh
./bin/fsa start --device=chrome
./bin/fsa dusk:navigate --route=/preview
```

Capture both modes:

```sh
# Light mode
./bin/fsa dusk:screenshot -o /tmp/<screen>-light.jpg

# Toggle to dark mode via the catalog header toggle, then:
./bin/fsa dusk:screenshot -o /tmp/<screen>-dark.jpg
```

Dark and light screenshots MUST be visually distinct. If they look identical, a semantic token alias is missing its `dark:` counterpart.

---

## PHASE 4: COMPOSE

Compose the screen from library components. Rules:

- Use `magic_starter` components from `docs/component-registry.md`. Do not write one-off inline widgets when a library component fits.
- Token discipline: `bg-surface`, `text-fg`, `border-color-border`, etc. Never `Colors.grey.shade200`.
- Layout: `WDiv` with `flex-col` or `flex-row`; Wind breakpoint prefixes for responsive behavior.
- Interactive elements: `Button`, `Input`, `Checkbox`, `Switch` from the component library; not raw `WButton`/`WInput` unless the component library explicitly wraps them.

---

## PHASE 5: WIRE TO CONTROLLER

Wire the screen to its `MagicStatefulView<Controller>`:

1. The view is a `MagicStatefulView<XController>` or `MagicView<XController>`.
2. The controller extends `MagicController` + `MagicStateMixin`.
3. All data flows through the controller; the view reads `controller.state` and calls controller methods.
4. API calls use `Http.post/get/put/delete`; error handling via `handleApiError`.
5. Gate-checked UI elements use `Auth.can('ability')` (advisory; backend enforces).

Preserve the view-registry key (`'auth.login'`, `'profile.settings'`, etc.) unchanged.

---

## PHASE 6: VERIFY (self-heal loop)

Run the full self-heal cycle after wiring:

### CREATE

Navigate to the screen in the running app:

```sh
./bin/fsa dusk:navigate --route=/<route-path>
```

Or use the `/preview` catalog for isolated component testing.

### SCREENSHOT

Capture screenshots for both modes:

```sh
./bin/fsa dusk:screenshot -o /tmp/<screen>-light.jpg
# toggle dark, then:
./bin/fsa dusk:screenshot -o /tmp/<screen>-dark.jpg
```

### ANALYZE

Invoke the `component-visual-reviewer` subagent:

```
Agent({subagent_type: "ac:component-visual-reviewer"}) with:
  - screenshot_light: /tmp/<screen>-light.jpg
  - screenshot_dark: /tmp/<screen>-dark.jpg
  - design_md: magic_example/DESIGN.md
  - component: <ScreenName>
```

The reviewer returns:
- A numbered delta list (layout, spacing, token compliance, dark/light parity, typography).
- **Blocking** items: token violations (wrong color, missing dark variant, raw hex visible in screenshot). These must be fixed before proceeding.
- **Advisory** items: spacing, layout, typography notes. Fix if straightforward; log the rest.

### FIX

Address each blocking delta:
- Token violation: replace the non-token value with the correct semantic alias.
- Missing dark counterpart: add `dark:` class to the alias (or check that `design:sync` generated the alias correctly).
- Wrong component: swap to the correct library component.

After fixing, regenerate if needed:

```sh
dart run bin/dispatcher.dart previews:refresh
./bin/fsa reload
```

### VERIFY

Re-screenshot and re-analyze. Repeat the cycle.

**Stop conditions:**
- All blocking items are resolved.
- No improvement across a full round (three rounds maximum; escalate if still failing after round 3).

---

## RULES

- Read `DESIGN.md` before any token decision.
- Read `docs/component-registry.md` before building a component.
- Preview dark + light before wiring to a controller.
- Maximum 3 self-heal rounds per screen or component.
- Stop on no-improvement: if the delta list does not shrink after a round, escalate rather than looping.
- Token violations are always blocking; layout/spacing notes are advisory.
- `make:component` + `previews:refresh` are the only ways to add components to the catalog. Do not manually edit `_previews.g.dart`.
- Do not wire a screen to a real backend during preview; use mock controllers or `Http.fake()` stubs.

---

## COMMANDS REFERENCE

| Command | Phase | What it does |
|---------|-------|-------------|
| `dart run bin/dispatcher.dart make:component <Name>` | SCAFFOLD | Scaffold atomic folder + chain previews:refresh |
| `dart run bin/dispatcher.dart previews:refresh` | SCAFFOLD/FIX | Regenerate preview registry |
| `dart run bin/dispatcher.dart design:sync` | DESIGN | Regenerate Wind theme from DESIGN.md |
| `dart run bin/dispatcher.dart design:lint` | DESIGN | Validate DESIGN.md |
| `./bin/fsa start --device=chrome` | PREVIEW | Boot app for dusk interaction |
| `./bin/fsa dusk:navigate --route=/preview` | PREVIEW | Open preview catalog |
| `./bin/fsa dusk:screenshot -o <file>` | SCREENSHOT | Capture current app state |
| `./bin/fsa reload` | FIX | Hot reload after changes |

---

## REFERENCES

- `magic_example/DESIGN.md` token source
- `docs/component-registry.md` component inventory
- `.claude/skills/frontend-design/SKILL.md` token/color/type/spacing guidance
- `.claude/skills/make-component/SKILL.md` component scaffold detail
- `.claude/agents/component-visual-reviewer.md` visual review subagent
- `docs/design-culture/refactoring-ui.md` hierarchy and spacing principles
- `docs/design-culture/wind-responsive.md` breakpoints and layout patterns
