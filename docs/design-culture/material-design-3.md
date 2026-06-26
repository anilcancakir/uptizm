# Material Design 3 / Material You (Flutter/Wind reference)

Read this before building a screen in Google's design language. It is a decision tool first,
spec second. This doc maps M3 concepts onto Flutter's `ThemeData`/`ColorScheme` and the Wind
semantic tokens defined in [DESIGN.md](../DESIGN.md).

## When to choose this language

Choose Material when:

- Building for Android / Wear OS, or cross-platform apps that should feel at home on Google
  devices.
- The domain is data-dense or tool-heavy (dashboards, forms, settings): Material's explicit
  density and component set fit.
- You need robust light/dark plus accessible contrast without per-component tuning; the role
  system gives it for free.
- The brand tolerates a colorful, rounded, springy aesthetic.

Avoid it when:

- You want a restrained, content-first Apple feel; see [apple-hig.md](apple-hig.md).

## Core idea

Material 3 is token-driven: components reference semantic ROLES, never raw hex. A single seed
color generates tonal palettes via the HCT color space; roles map specific tones to slots and
remap for dark mode automatically.

In Flutter this is `ColorScheme.fromSeed(seedColor: ...)` plus `ThemeData.colorScheme`. In this
codebase, `design:sync` generates a `WindThemeData` from `DESIGN.md`; the violet `primary`
seed drives `toThemeData()` for Material interop. See `lib/config/` for the generated theme.

## Mapping M3 roles to the 17 Wind semantic tokens

The 17 semantic alias keys in DESIGN.md align to M3 roles. Use the Wind token in `className`;
Flutter's `ColorScheme` resolves the equivalent role for Material widget sub-trees.

| M3 role | Wind token | Usage |
|---|---|---|
| `surface` | `bg-surface` | Page canvas |
| `surface-container` | `bg-surface-container` | Cards, sheets |
| `surface-container-high` | `bg-surface-container-high` | Input backgrounds, nested panels |
| `on-surface` | `text-fg` | Primary body text |
| `on-surface-variant` | `text-fg-muted` | Secondary/helper text |
| (disabled) | `text-fg-disabled` | Disabled labels |
| `primary` | `bg-primary` | Primary action fills |
| `on-primary` | `text-on-primary` | Text on primary fills |
| `primary-container` | `bg-primary-container` | Tonal button fills, badge backgrounds |
| (secondary accent) | `bg-accent` | Secondary emphasis fills |
| `outline` | `border-color-border` | Interactive borders, input outlines |
| `outline-variant` | `border-color-border-subtle` | Decorative dividers |
| `error` | `bg-destructive` | Error/danger fills |
| `on-error` | `text-on-destructive` | Text on error fills |
| `error-container` | `bg-destructive-container` | Error container backgrounds |
| (success) | `bg-success` | Success state fills |
| (warning) | `bg-warning` | Warning state fills |

Always pair a role with its `on-*` token (for example `bg-primary text-on-primary`). Mixing
families breaks the guaranteed contrast.

### Using tokens in Wind className

```dart
// Primary action button
Button(
  className: 'bg-primary text-on-primary rounded-md px-4 py-3',
  child: Text('Save'),
)

// Card surface
WCard(
  className: 'bg-surface-container rounded-lg p-4 border border-color-border',
  child: ...,
)

// Secondary text
WText('Helper text', className: 'text-fg-muted text-sm')
```

Dark mode pairs are bundled: `bg-surface-container` resolves to
`bg-[#F9FAFB] dark:bg-[#111827]` automatically via wind aliases.

## Surface depth system

Express elevation with tonal surface-container steps, not drop shadows.

```
surface (#FFFFFF)             <- page canvas
  surface-container (#F9FAFB) <- cards, sheets
    surface-container-high (#F3F4F6) <- input backgrounds, nested panels
```

Dark mode steps invert correctly: deeper grays for lower-elevation surfaces. Reserve shadows
(`shadow-sm`, `shadow-md`) for genuinely floating elements (popovers, modals).

## State layers

Hover, focus, and pressed states overlay the `on-*` color at low opacity:

| State | Opacity |
|---|---|
| Hover | 8% |
| Focus | 10% |
| Pressed | 10% |
| Dragged | 16% |

In Wind/magic_starter components this is expressed as `hover:bg-surface-container` or
`hover:opacity-90` rather than precise opacity math. Only one state layer at a time.

## Navigation by breakpoint

Material specifies navigation component by viewport width:

- Under 600dp: bottom navigation bar (3-5 destinations)
- 600-839dp: navigation rail
- 840dp and above: navigation rail or navigation drawer

In `AppLayout` (`magic_starter`) this maps to:

- Mobile (below `md` breakpoint, 768px): bottom nav bar + hamburger drawer
- Desktop (`md:` and above): sidebar navigation rail

Use `md:hidden` to show/hide between mobile and desktop layouts. See
[wind-responsive.md](wind-responsive.md) for the breakpoint map and layout patterns.

## Typography

The type scale below maps M3 roles to DESIGN.md typography variants. Use the `Typography`
component variant rather than specifying `text-*` sizes directly.

| M3 role | Typography variant | Suggested className |
|---|---|---|
| Display Large | display | `font-bold` |
| Headline Large | headline-lg | `font-bold` |
| Headline Medium | headline-md | `font-semibold` |
| Title Large | title-lg | `font-semibold` |
| Body Large | body-lg | `font-normal` |
| Body Medium | body-md | `font-normal` |
| Label Medium | label-md | `font-semibold` |
| Label Small | label-sm | `font-medium` |

Use weight 500-600 for interactive labels and tab titles, not Body weight. The DESIGN.md font
(Inter) is authoritative; do not override it per-component.

## Shape scale

| M3 name | Corner radius | Wind token | Use |
|---|---|---|---|
| Extra-small | 4px | `rounded-sm` | Chips, badges |
| Small | 8px | `rounded` | Inputs |
| Medium | 12px | `rounded-md` | Buttons |
| Large | 16px | `rounded-lg` | Cards, dialogs |
| Extra-large | 24px | `rounded-xl` | Bottom sheets |
| Full | 9999px | `rounded-full` | Pills, avatars |

Use `rounded-lg` (16px) for cards and dialogs, `rounded-full` for pill badges, `rounded-md`
for buttons. Do not mix shape families without concentric nesting intent.

## Component rules

- One filled button per action group (the primary action). Secondary actions use tonal, outlined,
  or text variants. Never two filled buttons side by side.
- Cards: `surface-container` background, `rounded-lg`, no shadow unless floating. Cards are
  not navigation targets by themselves; wrap in a `GestureDetector` for interactivity.
- FAB or prominent action button: persists during scroll for the single most important action.
  Not for destructive or rare actions.

## Motion

M3 uses spring-physics on Android/Compose. In Flutter, the closest equivalent is a
`CurvedAnimation` with `Curves.easeOutCubic` (entering) or `Curves.easeInCubic` (exiting). See
[motion-interaction.md](motion-interaction.md) for durations, reduced-motion patterns, and the
Wind `motion-safe:` prefix.

M3 easing reference (use as curve approximations in Flutter `AnimationController`):

- Entering: `emphasized-decelerate` ~ `cubic-bezier(0.05, 0.7, 0.1, 1)` -> `Curves.easeOutCubic`
- Exiting: `emphasized-accelerate` ~ `cubic-bezier(0.3, 0, 0.8, 0.15)` -> `Curves.easeInCubic`
- Standard: `cubic-bezier(0.2, 0, 0, 1)` -> `Curves.easeInOutCubic`

## Accessibility

- Touch targets: minimum 48x48dp (Material spec); WCAG floor is 24px. The Wind `min-h-11`
  class (44px) meets both. Keep custom controls at `min-h-12` (48px) when targeting Material.
- The role system guarantees >=3:1 on `on-*` pairings for large text. Body text still needs
  4.5:1; `design:lint` enforces this on all `on-X`/`X` pairs. See
  [accessibility-wcag.md](accessibility-wcag.md).
- Components must ship a visible focus ring. In Flutter use `FocusableActionDetector` or rely
  on the `WButton`/`WInput` focus-ring className (`focus-visible:ring-2 focus-visible:ring-primary`).
- Provide `Semantics` for non-text content. Never use color alone.

## How to apply in this codebase

1. Use semantic tokens exclusively: never raw hex, never `Colors.*` constants in className.
2. Express depth by stepping `bg-surface` -> `bg-surface-container` -> `bg-surface-container-high`,
   not by adding shadows.
3. Map the type scale to `Typography` component variants; weight 500-600 for interactive labels.
4. Keep radii from the shape scale; `rounded-lg` for cards, `rounded-md` for buttons,
   `rounded-full` for pills.
5. For Material widget sub-trees (if used), the generated `ThemeData` from `design:sync` wires
   the `ColorScheme` automatically. Do not override `ThemeData.colorScheme` by hand.

## See also

- [DESIGN.md](../DESIGN.md): the 17 semantic token definitions, hex values, and component tokens
- [wind-responsive.md](wind-responsive.md): breakpoints and navigation layout patterns
- [accessibility-wcag.md](accessibility-wcag.md): contrast requirements and design:lint enforcement
- [refactoring-ui.md](refactoring-ui.md): craft: hierarchy, spacing, type, color, and depth polish
- [motion-interaction.md](motion-interaction.md): easing, duration, and reduced-motion in Flutter

## Sources

- m3.material.io: color/roles, styles/typography, styles/shape, styles/motion/easing-and-duration,
  components, foundations/accessible-design.
- Flutter documentation: ColorScheme.fromSeed, ThemeData, AnimationController, CurvedAnimation.
- material-foundation/material-color-utilities (HCT, tonal palette generation).
