---
name: Uptizm
description: >
  Uptime, incident, and status-page monitoring built on the magic
  framework and magic_starter. Single-brand green, Wind semantic tokens, Geist
  typography, and a dedicated monitoring status vocabulary.
colors:
  surface:
    light: "#F9FAFB"
    dark: "#07090C"
  surface-container:
    light: "#FFFFFF"
    dark: "#121518"
  surface-container-high:
    light: "#F1F3F6"
    dark: "#202529"
  fg:
    light: "#040608"
    dark: "#F1F3F6"
  fg-muted:
    light: "#555D65"
    dark: "#AAB1B7"
  fg-disabled:
    light: "#D1D5DA"
    dark: "#3A4147"
  primary:
    light: "#008560"
    dark: "#00C292"
  on-primary:
    light: "#FFFFFF"
    dark: "#07090C"
  primary-container:
    light: "#E0F9EE"
    dark: "#003223"
  accent:
    light: "#007A54"
    dark: "#98E8C9"
  border:
    light: "#DEE2E5"
    dark: "#2A2E33"
  border-subtle:
    light: "#ECEFF1"
    dark: "#1C2023"
  destructive:
    light: "#DF202E"
    dark: "#FF645F"
  on-destructive:
    light: "#FFFFFF"
    dark: "#07090C"
  destructive-container:
    light: "#FFE3DF"
    dark: "#4C1010"
  success:
    light: "#30A556"
    dark: "#45C06A"
  warning:
    light: "#E69825"
    dark: "#F5AE39"
  # ----------------------------------------------------------------------------
  # Monitoring status families (human reference only). design:sync ignores
  # these; they are mirrored into className tokens by the hand-authored
  # lib/config/uptizm_status_tokens.dart supplement, merged into the
  # WindThemeData alias map. up/down/degraded/paused/info/ai, each: solid
  # (dot / strong text), soft (badge background), soft-foreground (badge text).
  # `down` deliberately equals `destructive` so outages and danger read alike.
  # ----------------------------------------------------------------------------
  up:
    light: "#30A556"
    dark: "#45C06A"
  up-soft:
    light: "#DCF9E1"
    dark: "#0C2E16"
  up-soft-foreground:
    light: "#197037"
    dark: "#8CE6A0"
  down:
    light: "#DF202E"
    dark: "#FF645F"
  down-soft:
    light: "#FFE3DF"
    dark: "#4C1010"
  down-soft-foreground:
    light: "#B71824"
    dark: "#FFAEA6"
  degraded:
    light: "#E69825"
    dark: "#F5AE39"
  degraded-soft:
    light: "#FFECCC"
    dark: "#412400"
  degraded-soft-foreground:
    light: "#834100"
    dark: "#FAC871"
  paused:
    light: "#79828A"
    dark: "#999FA6"
  paused-soft:
    light: "#F1F3F6"
    dark: "#23272B"
  paused-soft-foreground:
    light: "#555D65"
    dark: "#AAB1B7"
  info:
    light: "#207FE8"
    dark: "#53A0FF"
  info-soft:
    light: "#DBEFFF"
    dark: "#00265D"
  info-soft-foreground:
    light: "#005DD1"
    dark: "#B0D4FF"
  ai:
    light: "#6E59E2"
    dark: "#9E8AFA"
  ai-soft:
    light: "#ECE8FF"
    dark: "#2B195A"
  ai-soft-foreground:
    light: "#5F40D5"
    dark: "#D6D0FF"
  ai-wash:
    light: "#F2F1FD"
    dark: "#191133"
typography:
  display:
    fontFamily: Geist
    fontSize: 36px
    fontWeight: "700"
    lineHeight: 44px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Geist
    fontSize: 28px
    fontWeight: "700"
    lineHeight: 36px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Geist
    fontSize: 22px
    fontWeight: "600"
    lineHeight: 30px
  title-lg:
    fontFamily: Geist
    fontSize: 18px
    fontWeight: "600"
    lineHeight: 26px
  body-lg:
    fontFamily: Geist
    fontSize: 16px
    fontWeight: "400"
    lineHeight: 26px
  body-md:
    fontFamily: Geist
    fontSize: 14px
    fontWeight: "400"
    lineHeight: 22px
  label-md:
    fontFamily: Geist
    fontSize: 14px
    fontWeight: "600"
    lineHeight: 20px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Geist
    fontSize: 12px
    fontWeight: "500"
    lineHeight: 16px
  metric:
    fontFamily: Geist Mono
    fontSize: 14px
    fontWeight: "500"
    lineHeight: 20px
rounded:
  sm: 4px
  DEFAULT: 8px
  md: 12px
  lg: 16px
  xl: 20px
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

Uptizm is a monitoring product: uptime checks, incident timelines, and public
status pages, built on the magic framework plus the magic_starter starter kit. Its design system is built around a single green brand with Material 3
role semantics, Wind utility tokens, a dedicated monitoring status vocabulary,
and a mobile-first responsive layout.

The brand personality is calm, precise, and operational. The green primary
(emerald-leaning, hue 168) anchors interactive surfaces (buttons, active tabs,
focus rings) while cool-tinted neutrals keep the dense monitoring tables
readable. A single brand color carries the whole interface; there is no second
brand color or accent-preset system.

Monitoring state lives in a separate six-family status vocabulary
(up/down/degraded/paused/info/ai), each with a solid tone for dots and strong
text, a soft tone for badge backgrounds, and a soft-foreground for badge text.
The brand green (hue 168) is deliberately distinct from the operational `up`
green (hue 150) so the two never blur, and `down` deliberately equals
`destructive` so outages and danger actions read identically.

For the responsive direction and accessible usage patterns, see
[docs/design-culture/](docs/design-culture/).

## Colors

The palette uses the M3 role model mapped onto 17 Wind semantic alias keys. A
single consumer-supplied `primary` MaterialColor drives shade resolution across
the component system; nothing else is hardcoded.

Light mode background hierarchy: `surface` (page canvas) -> `surface-container`
(cards, white) -> `surface-container-high` (input backgrounds, nested panels).
Dark mode inverts toward near-black cool-gray steps.

Primary green (#008560 light, #00C292 dark) carries every interactive surface.
On the light canvas it sits on white text (`on-primary`); on the dark canvas it
brightens and pairs with a near-black foreground for contrast. Destructive red
(#DF202E) equals the `down` status so outages and danger actions share a single
red.

The six monitoring status families are mirrored into the `colors:` block above
for human reference, but `design:sync` only emits the 17 standard roles. The
status families reach the runtime as className tokens
(`bg-up`, `text-up`, `bg-up-soft`, `text-up-soft-foreground`, and the same for
down/degraded/paused/info/ai) through the hand-authored
`lib/config/uptizm_status_tokens.dart` supplement, which is merged into the
`WindThemeData` alias map in `lib/main.dart`.

See [docs/design-culture/accessibility-wcag.md](docs/design-culture/accessibility-wcag.md)
for contrast requirements and how `design:lint` enforces them.

## Typography

Geist is the app font: a precise, low-personality grotesque that stays legible
in dense monitoring tables and does not compete with the green brand. Geist
Mono carries every metric, latency, percentage, and timestamp column (use the
`tabular-nums` utility on those). Both are self-hosted variable woff2 files
shipped in `assets/fonts/`, not the Google Fonts build (which strips the
OpenType feature set). All sizes are in logical pixels aligned to a 4px grid.

For type hierarchy guidance see
[docs/design-culture/refactoring-ui.md](docs/design-culture/refactoring-ui.md).

## Layout

The app uses a mobile-first 1-column layout that swaps its navigation shell
(bottom tab bar) for a sidebar + content column at the `lg` breakpoint (1024px),
matching `AppLayout`'s `wScreenIs(context, 'lg')` gate; below `lg` (phones and
portrait tablets) the mobile shell is kept. Content grids still relax earlier
(the KPI row goes 2-up then 4-up at `lg`). Spacing follows the 4px logical scale
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
shadows, keeping the UI light and reducing visual noise in dense tables.

## Shapes

Corner radii follow the 4px logical scale (matching the design source: sm
0.25rem, md 0.5rem, lg 0.75rem, xl 1.25rem in CSS, expressed here in logical
px):

- Inputs and small controls: `DEFAULT` (8px) for a modern, structured look.
- Cards and dialogs: `lg` (16px) to feel contained and distinct.
- Badges and status chips: `full` (9999px) for a pill shape.
- Buttons: `md` (12px), balancing substance and friendliness.

## Components

Components are described in detail in
[docs/design-culture/material-design-3.md](docs/design-culture/material-design-3.md).

Variant matrices for every component are available via `flutter run` ->
`/preview` (debug builds only). Run `dart run bin/dispatcher.dart design:lint`
to validate token usage; run `dart run bin/dispatcher.dart design:sync` to
regenerate the Wind theme from this file.
