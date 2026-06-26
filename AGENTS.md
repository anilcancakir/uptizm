# Agent Guidance

Behavioral contract for AI agents (Claude, Cursor, Copilot, etc.) working in this project.

## Before Any UI Work

1. Read `DESIGN.md` (loaded automatically via `@DESIGN.md` in `CLAUDE.md`).
2. Read the relevant `docs/design-culture/` file for the surface you are building (loaded automatically via `@docs/design-culture/`).
3. Check `docs/component-registry.md` to see whether a component already exists for the UI need.
4. Load `.claude/skills/frontend-design/SKILL.md` for all component and screen work.

Do not write a single widget until token bindings are decided. A screen built on the right semantic tokens will adapt to any brand change automatically; a screen with hardcoded hex will not.

## Visual Self-Heal Loop

For every new component or screen, run this loop after the first implementation:

```
CREATE -> SCREENSHOT -> ANALYZE -> FIX -> VERIFY
```

**Maximum 3 rounds.** Stop if no improvement is observed across a full round.

### Steps

**CREATE**: implement the component or screen using semantic tokens and library components.

**SCREENSHOT**: capture light and dark screenshots.

```sh
# Navigate to the component in the preview catalog
./bin/fsa dusk:navigate --route=/preview

# Light mode screenshot
./bin/fsa dusk:screenshot -o .ac/evidence/<name>-light.png

# Switch to dark mode in the preview catalog, then:
./bin/fsa dusk:screenshot -o .ac/evidence/<name>-dark.png
```

**ANALYZE**: invoke the `component-visual-reviewer` subagent (`.claude/agents/component-visual-reviewer.md`) with both screenshots and the component name. The reviewer scores token compliance, dark/light parity, spacing, typography, and corner radii.

**FIX**: address every BLOCKING item from the reviewer. ADVISORY items are addressed if they can be fixed without scope creep.

**VERIFY**: re-screenshot and re-run the reviewer. If all BLOCKING items are resolved, the component is ship-ready.

If the reviewer returns the same BLOCKING items after 3 rounds with no progress, stop and surface the issue to the user.

## Component Registry

Before scaffolding, always check `docs/component-registry.md`. It maps every available component to its variants, token bindings, and anti-patterns. If the registry has a component that covers the need, use it.

For new components:

```sh
dart run bin/dispatcher.dart make:component <Name> [--variants=intent,size] [--slots]
```

This scaffolds the 4-file atomic folder under `magic_starter/lib/src/ui/components/<name>/` and chains `previews:refresh`.

## Skills to Load

| Situation | Skill |
|-----------|-------|
| Any UI, component, or screen work | `.claude/skills/frontend-design/SKILL.md` |
| Scaffolding a new component | `.claude/skills/make-component/SKILL.md` |
| Full screen from design to controller-wired | `.claude/skills/design-first-workflow/SKILL.md` |

## Anti-Patterns

The following are hard blockers. The `component-visual-reviewer` will flag every one of them.

| Anti-pattern | Correct approach |
|-------------|-----------------|
| `Color(0xFF...)` or `Colors.*` in component code | Use a semantic alias key from `DESIGN.md` |
| Hardcoded pixel values (`SizedBox(height: 13)`) | Wind spacing utilities on the 4px scale |
| A color token without its `dark:` counterpart | Every alias expands to a light+dark pair; no exceptions |
| Building a one-off widget when a library component exists | Check `docs/component-registry.md` first |
| Multiple preview classes in one `.preview.dart` file | One preview class per file |
| `Icons.*` inline in component body | Extract as `static const IconData _icon = Icons.x;` |
| CSS-only Wind utilities (`box-shadow`, `filter`, `transform`, `group-*`) | These are unsupported; use Flutter animation APIs instead |
| Hand-editing `lib/config/wind_theme.g.dart` | Run `dart run bin/dispatcher.dart design:sync` instead |
| Skipping the preview before shipping a component | Every component needs a preview; run `previews:refresh` |

## Regeneration Commands

| Need | Command |
|------|---------|
| Regenerate wind theme from DESIGN.md | `dart run bin/dispatcher.dart design:sync` |
| Regenerate preview catalog | `dart run bin/dispatcher.dart previews:refresh` |
| Lint DESIGN.md | `dart run bin/dispatcher.dart design:lint` |
| Scaffold component | `dart run bin/dispatcher.dart make:component <Name>` |
