---
name: Magic Example
description: >
  Reference app for the magic framework, magic_starter, and the design-first
  component system. Single-brand violet, Wind semantic tokens, M3-role palette.
colors:
  surface:
    light: "#FFFFFF"
    dark: "#030712"
  surface-container:
    light: "#F9FAFB"
    dark: "#111827"
  surface-container-high:
    light: "#F3F4F6"
    dark: "#1F2937"
  fg:
    light: "#111827"
    dark: "#F9FAFB"
  fg-muted:
    light: "#6B7280"
    dark: "#9CA3AF"
  fg-disabled:
    light: "#D1D5DB"
    dark: "#4B5563"
  primary:
    light: "#7C3AED"
    dark: "#8B5CF6"
  on-primary:
    light: "#FFFFFF"
    dark: "#FFFFFF"
  primary-container:
    light: "#EDE9FE"
    dark: "#4C1D95"
  accent:
    light: "#4F46E5"
    dark: "#6366F1"
  border:
    light: "#E5E7EB"
    dark: "#374151"
  border-subtle:
    light: "#F3F4F6"
    dark: "#1F2937"
  destructive:
    light: "#DC2626"
    dark: "#EF4444"
  on-destructive:
    light: "#FFFFFF"
    dark: "#FFFFFF"
  destructive-container:
    light: "#FEE2E2"
    dark: "#7F1D1D"
  success:
    light: "#15803D"
    dark: "#16A34A"
  warning:
    light: "#D97706"
    dark: "#B45309"
typography:
  display:
    fontFamily: Inter
    fontSize: 36px
    fontWeight: "700"
    lineHeight: 44px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 28px
    fontWeight: "700"
    lineHeight: 36px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Inter
    fontSize: 22px
    fontWeight: "600"
    lineHeight: 30px
  title-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: "600"
    lineHeight: 26px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: "400"
    lineHeight: 26px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: "400"
    lineHeight: 22px
  label-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: "600"
    lineHeight: 20px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: "500"
    lineHeight: 16px
rounded:
  sm: 4px
  DEFAULT: 8px
  md: 12px
  lg: 16px
  xl: 24px
  full: 9999px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 40px
  2xl: 64px
  gutter: 16px
  section: 32px
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.md}"
    padding: "{spacing.md}"
  button-destructive:
    backgroundColor: "{colors.destructive}"
    textColor: "{colors.on-destructive}"
    rounded: "{rounded.md}"
    padding: "{spacing.md}"
  card-surface:
    backgroundColor: "{colors.surface-container}"
    rounded: "{rounded.lg}"
    padding: "{spacing.lg}"
  card-elevated:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: "{spacing.lg}"
  input-field:
    backgroundColor: "{colors.surface-container-high}"
    rounded: "{rounded.DEFAULT}"
    padding: "{spacing.md}"
  badge-primary:
    backgroundColor: "{colors.primary-container}"
    rounded: "{rounded.full}"
    padding: "{spacing.xs}"
---

## Overview

Magic Example is the reference consumer app for the magic framework plus
magic_starter starter kit. Its design system is built around a single violet
brand with Material 3 role semantics, Wind utility tokens, and a mobile-first
responsive layout.

The brand personality is precise, professional, and approachable. The violet
primary anchors interactive surfaces (buttons, active tabs, focus rings) while
gray neutrals keep the reading experience calm. The accent indigo provides a
distinct secondary signal without introducing a second brand color.

For the responsive direction and accessible usage patterns, see
[docs/design-culture/](docs/design-culture/).

## Colors

The palette uses the M3 role model mapped onto 17 Wind semantic alias keys. A
single consumer-supplied `primary` MaterialColor drives shade resolution across
the component system; nothing else is hardcoded.

Light mode background hierarchy: `surface` (white page) -> `surface-container`
(cards) -> `surface-container-high` (input backgrounds). Dark mode inverts
toward near-black gray steps.

Primary violet (#7C3AED light, #8B5CF6 dark) provides a 5.7:1 contrast ratio
against white, passing WCAG AA for normal text. Destructive red (#DC2626) and
on-destructive white also pass at 4.8:1.

See [docs/design-culture/accessibility-wcag.md](docs/design-culture/accessibility-wcag.md)
for contrast requirements and how `design:lint` enforces them.

## Typography

Inter is the app font: geometric, legible on small mobile screens, and neutral
enough not to compete with the violet brand. All sizes are in logical pixels
aligned to a 4px grid.

For type hierarchy guidance see
[docs/design-culture/refactoring-ui.md](docs/design-culture/refactoring-ui.md).

## Layout

The app uses a mobile-first 1-column layout that expands to a sidebar + content
column at the `md` breakpoint (768px). Spacing follows the 4px logical scale
defined in the `spacing` section above; `gutter` (16px) is the horizontal
content margin on narrow screens and `section` (32px) separates stacked
sections.

For responsive layout patterns and breakpoint usage, see
[docs/design-culture/wind-responsive.md](docs/design-culture/wind-responsive.md).

## Elevation & Depth

Surface hierarchy is expressed through tonal background shifts, not drop
shadows. `surface-container` sits one level above `surface`; `surface-container-high`
is used for input backgrounds and nested panels.

Subtle border lines (`border-color-border`) separate sections instead of
shadows, keeping the UI light and reducing visual noise.

## Shapes

Corner radii follow the 4px logical scale:

- Inputs and small controls: `DEFAULT` (8px) for a modern, structured look.
- Cards and dialogs: `lg` (16px) to feel contained and distinct.
- Badges and chips: `full` (9999px) for a pill shape.
- Buttons: `md` (12px), balancing substance and friendliness.

## Components

Components are described in detail in
[docs/design-culture/material-design-3.md](docs/design-culture/material-design-3.md).

Variant matrices for every component are available via `flutter run` ->
`/preview` (debug builds only). Run `dart run bin/dispatcher.dart design:lint`
to validate token usage; run `dart run bin/dispatcher.dart design:sync` to
regenerate the Wind theme from this file.
