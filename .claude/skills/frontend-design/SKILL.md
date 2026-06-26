---
name: frontend-design
description: "Flutter/Wind/Magic-native UI design skill: design systems, visual hierarchy, bold aesthetics, and semantic token usage for this project. Covers component authoring, DESIGN.md-driven theming, and dark/light parity. Use for any UI, component, or screen work in magic_example."
when_to_use: "TRIGGER when: UI, pages, components, screens, design. DO NOT TRIGGER when: backend, auth logic, or non-visual."
---

# Frontend Design (Flutter/Wind/Magic)

Production-grade UI design for Flutter apps built on the Wind utility system. This is a project-specific fork of the generic frontend-design skill, pinned to MOBILE/Flutter mode. All guidance is Wind className and WindRecipe based; web/CSS-only directives have been reconciled or removed.

---

## MODE

This skill is **MOBILE/Flutter only.** There is no web/CSS mode for this project.

Platform: Flutter (mobile-first, responsive via Wind breakpoint prefixes).
Styling: Wind className strings only. No raw `Colors.*`, no hardcoded hex in component code.
Theme: `DESIGN.md` is the single source of truth for all tokens. See the `colors`, `typography`, `rounded`, and `spacing` sections.
Components: use the project component library (`magic_starter` generic component set). Never build inline one-offs when a library component covers the case.

---

## DESIGN PROCESS (BEFORE CODING)

Before writing any widget code, commit to a bold aesthetic direction by answering four questions:

1. **Purpose**: What problem does this screen or component solve? Who uses it?
2. **Tone**: Choose a clear direction and commit fully (brutally minimal, refined precision, warm/approachable, editorial, etc.).
3. **Constraints**: Touch targets, safe areas, responsive breakpoints (`sm`/`md`/`lg` via Wind), accessibility (4.5:1 WCAG AA).
4. **Differentiation**: What is the one thing a user will remember about this surface?

Then implement working code that is production-grade, visually distinctive, and cohesive.

### Design-First Workflow

1. Design the actual piece of functionality first, not the navigation shell.
2. Work in grayscale first; add color after hierarchy is clear.
3. Establish token bindings (spacing, type, color via semantic aliases) before detailed styling.
4. Iterate in cycles; details come last.

For the full workflow loop (including screenshot + verify), see the `design-first-workflow` skill.

---

## DESIGN SYSTEMS

### Spacing Scale

Defer to `DESIGN.md`'s `spacing` section and the Wind utility scale. The project 4px logical scale:

| Token | Size | Wind class | Use case |
|-------|------|-----------|----------|
| xs | 4px | `p-1` / `gap-1` | Micro gaps, icon padding |
| sm | 8px | `p-2` / `gap-2` | Within components |
| md | 16px | `p-4` / `gap-4` | Standard screen padding |
| lg | 24px | `p-6` / `gap-6` | Between sections |
| xl | 40px | `p-10` / `gap-10` | Major separation |
| 2xl | 64px | `p-16` / `gap-16` | Hero areas |
| gutter | 16px | `px-4` | Horizontal content margin (narrow screens) |
| section | 32px | `py-8` | Stacked section separation |

Do not use arbitrary pixel values (`p-[13px]`); stay on the 4px scale. For semantic spacing tokens, use the alias keys from `magic_example/DESIGN.md`.

### Type Scale

Typography is **Inter** (the authoritative font from `DESIGN.md`). Use the `Typography` component or Wind text utilities matching the DESIGN.md scale. All sizes are logical pixels.

| DESIGN.md token | Wind approx | Role |
|----------------|-------------|------|
| `label-sm` | `text-xs` | Captions, meta, timestamps |
| `body-md` | `text-sm` | Default body text |
| `body-lg` | `text-base` | Emphasized body |
| `title-lg` | `text-lg` | Card/section titles |
| `headline-md` | `text-xl` | Subheadings |
| `headline-lg` | `text-2xl` | Screen titles |
| `display` | `text-3xl` | Hero/display text |

Line-height and letter-spacing from DESIGN.md apply; they are already encoded in the `Typography` component recipe.

**Font selection for this project**: Inter is the authoritative app font per `DESIGN.md`. The generic fork's "never Inter/Roboto/system-ui" rule does NOT apply here. DESIGN.md typography is always authoritative over general font guidance.

### Shadow and Elevation

Wind does not support CSS `box-shadow` or `filter` utilities (part of the ~72 unsupported CSS families). Express depth through:

- Background tonal shifts: `bg-surface` -> `bg-surface-container` -> `bg-surface-container-high`
- Subtle border lines: `border border-color-border`
- Use the `WindRecipe` `shadow-sm`/`shadow-md` tokens only if the consumer WindThemeData has them aliased; otherwise rely on tonal backgrounds.

For elevation semantics, see [docs/design-culture/material-design-3.md](../../docs/design-culture/material-design-3.md).

### Transforms and Filters

Wind does not support CSS `transform`, `rotate`, `scale`, `translate`, `filter`, `backdrop-filter`, `group-*`, or `peer-*` utilities. For motion and transitions, use Flutter's animation system directly (`AnimatedContainer`, `AnimatedOpacity`, `TweenAnimationBuilder`), not Wind className strings.

---

## VISUAL HIERARCHY

Every element sits at one of three levels:

- **Primary**: `text-fg` + heavy weight (`font-bold`) headlines, key actions (one per section)
- **Secondary**: `text-fg-muted` supporting text, dates, descriptions
- **Tertiary**: `text-fg-disabled` metadata, timestamps, copyright

### Key Principles

- Size is not everything: use weight and color before increasing font size.
- **Emphasize by de-emphasizing**: soften competing elements instead of loudening the target.
- Labels are a last resort: combine with values ("12 left in stock" beats "Stock: 12").
- Icons are visually heavy: give them `text-fg-muted` or `text-fg-disabled` to balance with text.

### Button Hierarchy

| Level | Wind recipe | Rule |
|-------|-------------|------|
| Primary | `Button(intent: ButtonIntent.primary)` | One per section maximum |
| Secondary | `Button(intent: ButtonIntent.secondary)` | Clear but not competing |
| Ghost | `Button(intent: ButtonIntent.ghost)` | Discoverable, unobtrusive |
| Destructive | `Button(intent: ButtonIntent.destructive)` | Only on destructive actions |

Destructive actions do not have to be big/red/bold on all screens. On regular content pages where delete is secondary, use ghost or secondary styling. Reserve full destructive styling for confirmation dialogs.

---

## COLOR SYSTEM

### Use Semantic Tokens, Not Hex

All color decisions go through semantic alias tokens defined in `DESIGN.md`. Never put raw hex or `Colors.*` in component code.

| Role | Wind alias | Use |
|------|-----------|-----|
| `bg-surface` | Page background | |
| `bg-surface-container` | Card, panel background | |
| `bg-surface-container-high` | Input background, nested panels | |
| `text-fg` | Primary text | |
| `text-fg-muted` | Secondary text | |
| `text-fg-disabled` | Disabled/meta text | |
| `bg-primary` / `text-on-primary` | Brand action / on-brand text | |
| `bg-primary-container` | Tinted brand surface | |
| `bg-accent` | Secondary accent | |
| `border-color-border` | Dividers, card borders | |
| `border-color-border-subtle` | Hairline borders | |
| `bg-destructive` / `text-on-destructive` | Danger action / on-danger text | |
| `bg-success` / `bg-warning` | Status tones | |

For arbitrary-hex aliases generated by `design:sync`, Wind expands them as arbitrary-value utilities: `bg-[#7C3AED] dark:bg-[#8B5CF6]`.

### Dark/Light Parity

Every Wind className that carries a color token MUST include its `dark:` counterpart. This is enforced by the alias system: each alias key expands to a light+dark pair (e.g. `bg-surface` -> `bg-white dark:bg-[#030712]`). Never set a background or text color without a `dark:` override.

Use the `/preview` catalog and the `component-visual-reviewer` subagent to verify dark/light parity before shipping.

### Accessibility

| Text type | Minimum contrast | Checked by |
|-----------|-----------------|-----------|
| Normal text (<18px) | 4.5:1 | `design:lint` |
| Large text (18px+ bold or 24px+) | 3:1 | `design:lint` |

Never rely on color alone for meaning. Add icons, text, or patterns alongside color cues.

---

## TYPOGRAPHY

Inter is the project font (see `DESIGN.md` typography section). Use the `Typography` component for all text rendering rather than raw `WText` with ad-hoc sizes.

### Line-Height and Spacing

- Small text: taller line-height (1.5-2.0 equivalent).
- Large headlines: shorter line-height (1.0-1.2 equivalent).
- These are already encoded in the DESIGN.md `typography` entries; use them as-is.

### Alignment

- Default: left-aligned.
- Center: only for headlines and short blocks (under 2-3 lines).
- Right-align numbers in column comparison contexts.

---

## LAYOUT AND SPACING

### Mobile-First

Design for narrow screens first. Use Wind breakpoint prefixes to expand at `sm` (640px) and `md` (768px):

```
// narrow: stacked columns
// md and wider: row layout
WDiv(className: 'flex flex-col md:flex-row gap-4')
```

### Touch Targets

| Minimum | Comfortable |
|---------|------------|
| 44x44 pt (iOS) | 48x48 dp (Android) |

Add invisible padding if an icon or label is smaller than the minimum target. Use `min-h-11 min-w-11` as a floor.

### Safe Areas

Respect device notches and home indicators via Flutter's `SafeArea` widget. Never place interactive elements in unsafe areas.

### Navigation Patterns

| Pattern | Wind/Magic component | Use case |
|---------|---------------------|---------|
| Bottom navigation | `Navbar` | 3-5 primary destinations |
| Tab bar | `Tabs` | Content categories |
| Navigation drawer | `AppLayout` sidebar | Many destinations |
| Bottom sheet | `BottomSheet` | Contextual actions |

### Spacing Discipline

More space between groups than within groups:
- Form labels sit closer to their input than to the preceding element.
- Section headings have more space above than below.
- List items within a group are tighter than the group gap.

---

## DEPTH AND MOTION

### Tonal Depth (Wind-compatible)

Wind does not support `box-shadow` or `filter`. Express depth through tonal backgrounds:

- Raised: use a lighter background (`bg-surface-container`) against the page (`bg-surface`).
- Inset: use a darker/deeper background (`bg-surface-container-high`) for inputs and nested panels.

### Motion

Flutter animation system handles motion; Wind className strings do not carry transitions/transforms. Focus on high-impact moments:

- Page transitions: use `MagicRoute` transition settings, not custom animations per-view.
- State changes (loading/error): use the `Skeleton` component for loading states; avoid inline spinners.
- Reduced motion: respect `MediaQuery.of(context).disableAnimations` in all custom animations.

For detailed easing/duration guidance, see [docs/design-culture/motion-interaction.md](../../docs/design-culture/motion-interaction.md).

---

## SPATIAL COMPOSITION

- Asymmetry and unexpected layouts can add character; do not default to symmetric grids.
- Generous negative space reads as premium; controlled density reads as rich/capable.
- Avoid filling the whole screen when the content only needs part of it.

---

## MOBILE-SPECIFIC PATTERNS

### Loading States

Use the `Skeleton` component (preferred over spinners). Shape variants: `block`, `text`, `circle`.

### Empty States

Use the `EmptyState` component:
- Illustration or icon to grab attention.
- Clear title and helpful description.
- A call-to-action `Button`.
- Hide irrelevant UI (filters, tabs) when they have no effect yet.

### Forms

- Full-width `Input` with horizontal `gutter` padding.
- `FormField` handles label + hint + error state (never roll inline).
- Primary action: full-width `Button(intent: ButtonIntent.primary)` at the bottom.
- Error state: `border-color-border` turns `border-color-destructive`; use `FormField`'s error slot.

---

## ANTI-PATTERNS

| Anti-pattern | Fix |
|-------------|-----|
| Raw `Color(0xFF...)` or `Colors.*` in component code | Use Wind semantic token alias |
| Hardcoded pixel values (`SizedBox(height: 13)`) | Use Wind spacing utilities on the 4px scale |
| Font family chosen outside DESIGN.md | DESIGN.md typography is authoritative; Inter is the font |
| Purple gradients on white without dark counterpart | Every color token needs its `dark:` pair |
| Filling the whole screen when content needs less | Add `max-w-*` or `mx-auto`; let content breathe |
| Ambiguous spacing between groups | More space between groups than within |
| Touch targets under 44pt/48dp | Add `min-h-11 min-w-11` or invisible padding |
| Ignoring safe areas | Wrap top-level screens in `SafeArea` |
| Color as sole communication channel | Add icon, text, or pattern alongside color |
| Multiple previews in one file | One preview class per `*.preview.dart` file |
| CSS-only utilities (`box-shadow`, `filter`, `transform`, `group-*`) | These are unsupported by Wind; use Flutter APIs instead |
| `Icons.*` inline in component bodies | Extract as `static const IconData _icon = Icons.x;` |
| Skipping dark/light parity | Every semantic token alias is a light+dark pair; no exceptions |
| Building one-off widgets when a library component exists | Always check `magic_starter` component library first |

---

## COMPONENT AUTHORING

When building a new component, follow the atomic folder convention:

```
magic_starter/lib/src/ui/components/<name>/
  <name>.dart           # class <Name> extends StatelessWidget
  <name>.recipe.dart    # WindRecipe / WindSlotRecipe
  <name>.preview.dart   # single preview widget (ONE per file)
  index.dart            # barrel: export <name>.dart + <name>.recipe.dart (NOT preview)
```

Generate the scaffold with:

```sh
dart run bin/dispatcher.dart make:component <Name> [--variants=intent,size] [--slots]
```

This chains `previews:refresh` automatically.

Every new component needs:
1. A `WindRecipe` or `WindSlotRecipe` in the recipe file.
2. Semantic token classNames only (from `DESIGN.md` alias set).
3. A preview widget rendering every variant x state combination.
4. Tests asserting the rendered `WDiv.className` for each variant.

For the full component lifecycle (design -> scaffold -> preview -> verify), load the `make-component` skill.

---

## OUTPUT GUIDANCE

When generating UI code, structure output as:

1. **Design direction** 1-2 sentences on aesthetic approach and key token decisions.
2. **Code** complete, working Flutter/Wind implementation.
3. **Responsive notes** breakpoint behavior if applicable (narrow -> `md` -> wider).

Lead with code, not explanation.

---

## REFERENCES

| Topic | File |
|-------|------|
| DESIGN.md (token source) | `magic_example/DESIGN.md` |
| Visual hierarchy | `docs/design-culture/refactoring-ui.md` |
| Color system + contrast | `docs/design-culture/accessibility-wcag.md` |
| Mobile patterns + safe areas | `docs/design-culture/wind-responsive.md` |
| Material 3 role semantics | `docs/design-culture/material-design-3.md` |
| Motion + easing | `docs/design-culture/motion-interaction.md` |
| Apple HIG / iOS patterns | `docs/design-culture/apple-hig.md` |
| Component authoring workflow | `.claude/skills/make-component/SKILL.md` |
| Design-first end-to-end loop | `.claude/skills/design-first-workflow/SKILL.md` |
| Visual reviewer subagent | `.claude/agents/component-visual-reviewer.md` |
| Component registry | `docs/component-registry.md` |
