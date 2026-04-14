# Design Principles

Decision rules for visual design in Flutter mobile interfaces.

## Contents

- [Design Process](#design-process)
- [Visual Hierarchy](#visual-hierarchy)
- [Color System](#color-system)
- [Typography](#typography)
- [Spacing and Layout](#spacing-and-layout)
- [Depth and Shadows](#depth-and-shadows)
- [Mobile Patterns](#mobile-patterns)
- [Anti-Patterns](#anti-patterns)

## Design Process

Answer four questions before writing any widget code: (1) **Purpose**: what problem, who uses it. (2) **Tone**: pick one, commit fully. (3) **Constraints**: framework, performance, accessibility. (4) **Differentiation**: the ONE memorable thing.

| Tone | Character |
|:-----|:----------|
| Brutally minimal | Max whitespace, single accent, stark contrast |
| Luxury/refined | Generous spacing, muted palette, thin typography |
| Playful/toy-like | Rounded corners, bright accents, bouncy motion |
| Editorial/magazine | Strong type hierarchy, grid discipline, dramatic imagery |
| Soft/pastel | Low saturation, gentle gradients, warm neutrals |
| Industrial/utilitarian | Monospace type, dense layout, functional aesthetic |
| Organic/natural | Earth tones, rounded shapes, textured surfaces |
| Retro-futuristic | Neon accents, dark surfaces, geometric patterns |

**Workflow**: Design core functionality first, not the shell. Grayscale until hierarchy is clear. Systems before details. Iterate in cycles.

## Visual Hierarchy

**Three-level text color system**: Primary (weight 600-700, darkest neutral, headlines/key values, one per section). Secondary (weight 400-500, mid grey, supporting text). Tertiary (weight 400, light grey, metadata/timestamps).

- **Emphasize by de-emphasizing**: soften competing elements instead of making the target louder
- Use weight and color before increasing font size
- Icons are visually heavy; give them softer colors to balance with text
- Never use font weights under 400. Grey on colored backgrounds looks washed out; hand-pick a hue-matched color

| Button level | Style | Rule |
|:-------------|:------|:-----|
| Primary | Solid, high-contrast fill | One per section maximum |
| Secondary | Outline or low-contrast fill | Visible but not competing |
| Tertiary | Styled like a link | Discoverable but unobtrusive |

Destructive actions: big/red on confirmation dialogs only. Tertiary on regular screens.

**Labels are a last resort**: skip when format is obvious (email, phone). Combine: "12 left in stock" not "Stock: 12". When needed, de-emphasize (smaller, lighter, thinner).

## Color System

> Wind className color tokens live in `wind-ui/references/design-tokens.md`. This covers palette creation theory only.

**HSL thinking**: Use HSL for palette decisions. Hex is for storage, not design.

**Shade definition**: (1) Pick base 500 that works as button bg. (2) Find edges: 900 for text on light, 100 for tinted bg. (3) Fill gaps: 700/300 first, then 800/600/400/200.

**Saturation**: Increase as lightness moves from 50% (extremes look washed out). Lighten by rotating hue toward 60/180/300. Darken by rotating toward 0/120/240. Never rotate more than 20-30 degrees.

**Grey temperature**: Cool = saturate with blue. Warm = saturate with yellow/orange. True black looks unnatural; use very dark grey.

**WCAG contrast**: Normal body text 4.5:1 minimum. Large text (bold 18sp+ or regular 24sp+) 3:1 minimum. Never use color as sole meaning channel; pair with icons or text.

## Typography

- **Pairing**: Bold display font + refined body font. Filter: ignore typefaces with fewer than 5 weights
- **Line-height**: Small text taller (1.5-2.0), large headlines shorter (1.0-1.2), wider content taller
- **Letter-spacing**: Tighten for headlines, increase for ALL-CAPS, trust the designer for body
- **Alignment**: Default left. Center only for headlines and blocks under 3 lines. Right-align numbers in columns. Baseline-align mixed sizes on one line

## Spacing and Layout

> Spacing scale className mappings live in `wind-ui/references/design-tokens.md`.

- Start with too much whitespace, then remove until satisfied
- Do not fill the screen; if content needs 300dp, use 300dp
- More space **between** groups than **within** groups (labels, headings, list clusters)
- Large elements shrink faster than small elements across breakpoints

## Depth and Shadows

- Light from above: raised = lighter top edge + shadow below, inset = lighter bottom + shadow above
- Drag: increase shadow. Press: decrease shadow. Without shadows: lighter = raised, darker = inset
- Premium: combine large soft shadow (direct light) + tight dark shadow (ambient occlusion)
- **iOS**: subtle shadows, blur/frosted glass, minimal elevation
- **Android/Material**: elevation system, layered surfaces, defined shadow levels

## Mobile Patterns

**Touch targets**: Minimum 44pt (iOS) / 48dp (Android). Comfortable: 48pt / 56dp. Add invisible padding for small icons. Respect safe areas; never place interactive elements in notch/home indicator zones.

| Nav pattern | Use case | Notes |
|:------------|:---------|:------|
| Bottom navigation | Primary destinations | 3-5 items, icons + labels |
| Top tab bar | Content categories | Scrollable if many |
| Navigation drawer | Many destinations, settings | Grouped sections |
| Bottom sheet | Contextual actions | Variable item count |

**Forms**: Full-width inputs with horizontal padding. Label above or floating. Helper text below, error = colored border + message. Primary action as full-width bottom button or FAB.

**Loading**: Skeleton screens over spinners, always. Shimmer for placeholders. Pull to refresh for lists.

**Empty states**: First impressions. Illustration + clear title + description + CTA. Hide irrelevant UI (empty filters, tabs).

**Feedback**: Snackbar for transient messages (optional undo). Dialog when decision required. Bottom sheet for multiple options.

## Anti-Patterns

| Anti-pattern | Fix |
|:-------------|:----|
| Generic system fonts everywhere | Choose distinctive, characterful typefaces |
| Random gradient on white background | Commit to cohesive, context-specific palette |
| Predictable symmetric layouts | Use asymmetry, overlap, unexpected composition |
| Grey text on colored backgrounds | Hand-pick color based on background hue |
| Filling the screen when content needs less | Constrain width, let content breathe |
| Ambiguous spacing between groups | More space between groups than within |
| Fixed size ratios across breakpoints | Large elements shrink faster than small ones |
| Touch targets under 44pt/48dp | Add invisible padding to meet minimums |
| Ignoring safe area insets | Respect notch and home indicator zones |
| Color as sole meaning channel | Add icons, text, or patterns alongside |
| Same design language for every screen | Vary tone and aesthetic per context |
| Converging on common font/color defaults | Deliberately vary themes and palettes |
