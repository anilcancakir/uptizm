# Apple Human Interface Guidelines (Flutter/Wind reference)

Read this before building a screen that should feel native on Apple platforms. It is a decision tool:
pick the language, then apply the rules. The guidance below is adapted from HIG principles to
Flutter/Wind/Magic idioms.

## When to choose this language

Choose Apple HIG when:

- The audience is Apple-ecosystem-native and expects iOS/iPadOS patterns (bottom tab bar, swipe-back, edge gestures).
- The product is content-first: chrome should recede so content leads.
- Calm, restrained minimalism fits the brand better than loud expression.

Avoid it when:

- The product must reach Android parity equally (use material-design-3 instead).
- The domain is data-dense or tool-heavy; Material's explicit density fits better.
- The brand needs expressive, decorative visuals.

## Core principles

- **Clarity**: legible text at every size, precise icons, subtle adornment.
- **Deference**: the UI helps users interact with content without competing with it.
- **Depth**: layers communicate hierarchy via tonal elevation, not heavy drop shadows.

## Layout and spacing rules

- Every interactive element needs a minimum 44x44 logical-px hit target. On Flutter this means
  `InkWell`, `GestureDetector`, or `WButton` with `min-h-11 min-w-11` in the className. Pad
  invisibly rather than shrink the control.
- Use generous whitespace. Wind spacing follows the 4px logical scale; prefer `p-4`/`p-6`/`gap-4`
  and resist `p-2` on content areas.
- On narrow (mobile) widths use `p-4` (16px) screen-edge margins; on tablet/desktop widths use `p-5`
  (20px) or the layout shell's `md:px-5`. Do not reuse one margin across all breakpoints.
- Reflow vertically at narrow widths rather than truncate.
- Derive nested corner radii concentrically: inner radius = parent radius minus padding. In Wind
  terms: a card with `rounded-lg` (16px) around content with 12px padding gets `rounded-md` (12px)
  on a nested control, not an arbitrary value.

In `AppLayout`, the `sm:` and `md:` breakpoint prefixes (`sm:flex-row`, `md:hidden`) handle the
compact-to-regular reflow. See [wind-responsive.md](wind-responsive.md) for the full breakpoint map.

## Typography

- Let type carry hierarchy: bolder, left-aligned section headings. Keep one font family; the
  DESIGN.md typography font is authoritative (Inter by default in magic_example).
- Use the Typography component variants rather than arbitrary sizes:
  `headline-lg` for page titles, `title-lg` for section heads, `body-lg`/`body-md` for content,
  `label-md`/`label-sm` for interactive labels and captions.
- Body weight 400, headings and interactive labels weight 600-700. Never below 400 for small text.
- Left-align body text; never center multi-line prose.

## Color and material

- Use semantic tokens only. Never raw hex inside a component.
  - Page canvas: `bg-surface`
  - Cards and sheets: `bg-surface-container`
  - Primary action: `bg-primary text-on-primary`
  - Destructive: `bg-destructive text-on-destructive`
  - Body text: `text-fg`
  - Secondary text: `text-fg-muted`
  - Hairlines: `border-color-border`

  The 17 semantic alias keys are defined in `DESIGN.md` and applied as wind aliases. Dark-mode
  pairs are bundled in each token: `bg-surface` resolves to `bg-[#FFFFFF] dark:bg-[#030712]`
  automatically.

- Tint, do not repaint: apply `bg-primary` to primary actions only. Keep chrome neutral
  (`bg-surface-container`, `text-fg`).
- In dark mode, elevation goes lighter (surface-container is lighter than surface). Never use
  pure black; the `surface` dark token is `#030712`, an elevated dark gray.
- Reserve translucency for the navigation layer only. In Flutter, approximate a glass nav bar
  with `BackdropFilter` + a semi-transparent surface. Never glass-on-glass, never glass in the
  content layer.

## Motion (Apple-specific feel)

See [motion-interaction.md](motion-interaction.md) for Flutter easing/duration mechanics.

Apple-specific feel:

- Transitions originate from the element that triggered them (a sheet slides up from the
  triggering button's area, not from a random edge).
- Use spring-based or ease-out curves; keep micro-interactions under 300ms.
- Animate state transitions that communicate hierarchy, not frequent interactions.
- Wrap non-essential animations with `motion-safe:` in Wind className, or guard with
  `MediaQuery.of(context).disableAnimations` in Flutter widget code.

## Accessibility

- Contrast: 4.5:1 for normal text, 3:1 for large text and UI components, in BOTH light and dark.
  `design:lint` enforces 4.5:1 on every `on-X`/`X` role pair. See
  [accessibility-wcag.md](accessibility-wcag.md).
- Support text scaling: never hardcode font sizes for primary content. The Typography component
  uses logical px; Flutter's `textScaleFactor` respects the OS setting automatically.
- Respect Reduce Motion: substitute a crossfade or opacity fade, do not delete animations.
  Use `MediaQuery.of(context).disableAnimations` or the `motion-safe:` Wind prefix.
- Never use color as the only signal. Pair every color-only state with an icon or text label.
- Provide `Semantics` labels on icon-only controls:
  ```dart
  Semantics(label: 'Close', child: WButton(onPressed: ..., child: Icon(Icons.close)))
  ```

## What makes it feel authentically Apple

Reproduce: restraint (one primary surface per view), generous whitespace, crisp single-family
type, semantic adaptive color, subtle depth via tonal elevation. Avoid the tells of a cheap
imitation: raw hex, fixed text sizes, multiple typefaces, red used for non-destructive actions,
dense cramped layouts.

## How to apply in this codebase

1. Map the type scale to `Typography` component variants; Inter is the default app font.
2. Use semantic tokens exclusively in wind `className`: `bg-surface text-fg`,
   `bg-primary text-on-primary`, `border-color-border`.
3. Dark mode is automatic: each token carries its `dark:` pair. Verify both themes pass contrast.
4. Honor 44px targets: Button `md`/`lg` already clear it; keep custom controls at `min-h-11`.
5. Keep radii concentric: `rounded-lg` (16px) outer card -> `rounded-md` (12px) nested controls.
6. Reserve `bg-destructive` for genuinely destructive actions, matching Apple's reserved-red rule.

## See also

- [DESIGN.md](../DESIGN.md): the 17 semantic token definitions and color values for this app
- [wind-responsive.md](wind-responsive.md): breakpoints, PageContainer, safe-area, sidebar vs bottom nav
- [accessibility-wcag.md](accessibility-wcag.md): contrast requirements and design:lint enforcement
- [motion-interaction.md](motion-interaction.md): easing, duration, and reduced-motion patterns in Flutter
- [material-design-3.md](material-design-3.md): M3 alternative when Material feel is preferred

## Sources

- Apple HIG: Design Principles, Layout, Typography, Color, Motion, Accessibility
  (developer.apple.com/design/human-interface-guidelines).
- Flutter documentation: Semantics, MediaQuery.disableAnimations, TextScaleFactor.
