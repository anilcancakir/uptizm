# Magic Example

Reference app for the `magic` framework and `magic_starter` starter kit. Single-brand violet, Wind semantic tokens, M3-role palette. Consumes and demonstrates the full design-first component system.

## Stack

- Flutter >=3.27.0, Dart >=3.6.0
- `fluttersdk_magic` (framework: IoC, ORM, auth, routing via `go_router`)
- `magic_starter` (starter kit: auth, profile, teams, notifications, 13 opt-in features)
- `fluttersdk_wind` (utility-first styling: Wind className strings only)
- `magic_devtools` (dev-only preview catalog and dusk integration)

## Design-First Rules

These rules apply to every UI file. There are no exceptions.

**1. Wind className and semantic tokens only.**
No `Color(0xFF...)`, no `Colors.*`, no hardcoded pixel values in component or view code. All color decisions go through semantic alias keys from `DESIGN.md` (e.g. `bg-surface`, `text-fg`, `border-color-border`). Every color alias expands to a light+dark pair; always include the `dark:` counterpart.

**2. Build from the component library.**
Check `docs/component-registry.md` before writing any widget. If a component covers the need, use it. Only scaffold a new component when the registry has no match.

**3. DESIGN.md is the theme source.**
`magic_example/DESIGN.md` is the single source of truth for colors, typography, spacing, and rounded values. The generated theme lives at `lib/config/wind_theme.g.dart` (do not hand-edit it). Regenerate after any DESIGN.md change with `dart run bin/dispatcher.dart design:sync`.

**4. The `/preview` catalog is the visual feedback loop.**
Every new component requires a preview widget before it ships. Navigate to `/preview` in debug mode to see all registered previews in dark and light. Use dusk screenshots to verify token compliance before merging.

## Commands

All commands run via the dispatcher. Do not reference `magic_example:artisan` (it does not exist).

```sh
# Scaffold a new component (chains previews:refresh automatically)
dart run bin/dispatcher.dart make:component <Name> [--variants=intent,size] [--slots]

# Regenerate the wind theme from DESIGN.md
dart run bin/dispatcher.dart design:sync

# Regenerate lib/preview/_previews.g.dart from *.preview.dart files
dart run bin/dispatcher.dart previews:refresh

# Lint DESIGN.md token rules (13 checks)
dart run bin/dispatcher.dart design:lint

# Navigate to /preview in the running app
./bin/fsa dusk:navigate --route=/preview

# Screenshot the current screen (light or dark)
./bin/fsa dusk:screenshot -o .ac/evidence/<name>-light.png

# Analyze and format
flutter analyze
dart format .
flutter test
```

## Agent Infra

- Skills: `.claude/skills/frontend-design/`, `.claude/skills/make-component/`, `.claude/skills/design-first-workflow/`
- Agents: `.claude/agents/component-visual-reviewer.md`
- Design culture docs: `docs/design-culture/` (apple-hig, material-design-3, refactoring-ui, accessibility-wcag, motion-interaction, wind-responsive)
- Component registry: `docs/component-registry.md`

@DESIGN.md
@docs/design-culture/
