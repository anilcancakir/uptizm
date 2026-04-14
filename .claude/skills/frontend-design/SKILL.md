---
name: frontend-design
description: "Production-grade UI for web and mobile — design systems, visual hierarchy, distinctive aesthetics. Use for frontend implementation and design decisions."
when_to_use: "TRIGGER when: UI, pages, components, or design. DO NOT TRIGGER when: backend or non-visual."
---

# Frontend Design

Production-grade UI design skill combining design system thinking with bold aesthetic execution. Covers web (HTML/CSS/JS, React, Vue, etc.) and mobile (Flutter, React Native, SwiftUI, etc.).

---

## MODE DETECTION (FIRST STEP)

Analyze the user's request to determine platform:

| Request Pattern | Mode | Focus |
|----------------|------|-------|
| HTML, CSS, JS, React, Vue, Next.js, landing page, dashboard, web app | `WEB` | Browser viewport, responsive breakpoints, CSS animations |
| Flutter, React Native, SwiftUI, Kotlin, mobile app, iOS, Android | `MOBILE` | Touch targets, safe areas, platform depth, native patterns |
| Unspecified | `WEB` | Default to web unless context suggests mobile |

---

## DESIGN PROCESS (BEFORE CODING)

Before writing any code, commit to a **BOLD aesthetic direction** by answering four questions:

1. **Purpose**: What problem does this interface solve? Who uses it?
2. **Tone**: Pick a clear direction — brutally minimal, maximalist chaos, retro-futuristic, organic/natural, luxury/refined, playful/toy-like, editorial/magazine, brutalist/raw, art deco/geometric, soft/pastel, industrial/utilitarian. Commit fully.
3. **Constraints**: Technical requirements (framework, performance, accessibility)
4. **Differentiation**: What is the ONE thing someone will remember about this interface?

CRITICAL: Choose a clear conceptual direction and execute with precision. Bold maximalism and refined minimalism both work — the key is intentionality, not intensity.

Then implement working code that is:

- Production-grade and functional
- Visually striking and memorable
- Cohesive with a clear aesthetic point-of-view
- Meticulously refined in every detail

### Design-First Workflow

1. Design the actual piece of functionality first — not the shell, navigation, or chrome
2. Work in grayscale first — add color after hierarchy is clear
3. Establish systems (spacing, type, color) before detailed design
4. Details come later — work in cycles

---

## DESIGN SYSTEMS

### Spacing Scale

Constrain to a fixed set. Values should never be closer than ~25% apart.

**Web (px):**

| Token | Size | Use Case |
|-------|------|----------|
| 1 | 4 | Micro gaps |
| 2 | 8 | Tight, within components |
| 3 | 12 | Related elements |
| 4 | 16 | Section padding |
| 6 | 24 | Between sections |
| 8 | 32 | Major separation |
| 12 | 48 | Large gaps |
| 16 | 64 | Hero spacing |
| 24 | 96 | Maximum separation |

**Mobile (dp/pt):**

| Token | Size | Use Case |
|-------|------|----------|
| xs | 4 | Micro gaps, icon padding |
| sm | 8 | Within components |
| md | 12 | Related elements |
| base | 16 | Standard screen padding |
| lg | 24 | Between sections |
| xl | 32 | Major separation |
| 2xl | 48 | Tablets, hero areas |

### Type Scale

**Web (px):**

| Size | Role |
|------|------|
| 12 | Labels, meta, fine print |
| 14 | Body text, default |
| 16 | Emphasis, large body |
| 18 | Subheadings |
| 20-24 | Card/section titles |
| 30-36 | Page headings |
| 48-72 | Hero/display text |

**Mobile (sp/pt):**

| Size | Role |
|------|------|
| 11-12 | Captions, labels |
| 14 | Body text, default |
| 16 | Emphasized body |
| 18-20 | Subheadings |
| 24 | Screen titles |
| 28-34 | Large titles (iOS style) |

### Shadow Scale

| Level | Use Case | Web CSS | Mobile Elevation |
|-------|----------|---------|-----------------|
| 1 | Buttons, subtle lift | `0 1px 2px rgba(0,0,0,0.05)` | 1-2dp |
| 2 | Cards, inputs | `0 2px 4px rgba(0,0,0,0.1)` | 4dp |
| 3 | Dropdowns, popovers | `0 4px 8px rgba(0,0,0,0.1)` | 8dp |
| 4 | Sticky headers, drawers | `0 8px 16px rgba(0,0,0,0.1)` | 16dp |
| 5 | Modals, dialogs | `0 16px 32px rgba(0,0,0,0.15)` | 24dp |

**Premium shadows:** Combine two shadows — a large soft one (direct light) and a tight dark one (ambient occlusion).

```css
box-shadow:
  0 10px 25px rgba(0, 0, 0, 0.15),  /* direct light */
  0 2px 4px rgba(0, 0, 0, 0.12);     /* ambient */
```

---

## VISUAL HIERARCHY

Every element sits at one of three levels:

- **Primary**: Dark color, bold weight — headlines, key actions (one per section)
- **Secondary**: Grey — supporting text, dates, descriptions
- **Tertiary**: Light grey — metadata, copyright, timestamps

### Key Principles

- Size isn't everything — use weight and color before increasing size
- **Emphasize by de-emphasizing**: Make competing elements softer instead of making the target louder
- Labels are a last resort — combine with values ("12 left in stock" > "Stock: 12")
- Semantics are secondary to hierarchy (h1 doesn't have to be the biggest element)
- Icons are visually "heavy" — give them softer colors to balance with text

### Button Hierarchy

| Level | Style | Rule |
|-------|-------|------|
| **Primary** | Solid, high-contrast background | One per section maximum |
| **Secondary** | Outline or low-contrast fill | Clear but not competing |
| **Tertiary** | Styled like links | Discoverable but unobtrusive |

Destructive actions: Not always big/red/bold. On confirmation dialogs, yes. On regular pages where delete is secondary, use tertiary styling.

---

## COLOR SYSTEM

### Use HSL

HSL (Hue, Saturation, Lightness) is intuitive for palette creation. Avoid hex for design decisions.

### Build Complete Palettes

| Category | Shades | Purpose |
|----------|--------|---------|
| Greys | 8-10 | Text, backgrounds, panels, borders |
| Primary | 5-10 | Actions, active states, links |
| Accents | 5-10 each | Success, warning, error, feature highlights |

### Shade Definition Process

1. **Pick base (500)**: Works as button background
2. **Pick edges**: 100 (tinted background), 900 (text on light bg)
3. **Fill gaps**: 700, 300 → 800, 600, 400, 200

### Saturation & Hue Adjustments

- Increase saturation as lightness moves away from 50% (extremes look washed out otherwise)
- To lighten: Rotate hue toward 60°, 180°, or 300° (perceived bright hues)
- To darken: Rotate hue toward 0°, 120°, or 240° (perceived dark hues)
- Don't rotate more than 20-30° (looks like different color)

### Grey Temperature

- **Cool greys**: Saturate with blue
- **Warm greys**: Saturate with yellow/orange
- True black looks unnatural — start with really dark grey

### Accessibility Requirements

| Text Type | Minimum Contrast Ratio |
|-----------|----------------------|
| Normal text (<18px) | 4.5:1 |
| Large text (18px+ bold or 24px+) | 3:1 |

Never rely on color alone for meaning. Add icons, text, or patterns.

---

## TYPOGRAPHY

### Font Selection

Choose distinctive, characterful fonts. Pair a bold display font with a refined body font.

**Filtering quality fonts**: Ignore typefaces with less than 5 weights (10+ styles including italics). This eliminates 85% of poor choices.

**NEVER use**: Arial, Inter, Roboto, system-ui, Space Grotesk. These produce forgettable, generic output.

### Line-Height Rules

- Small text → taller line-height (1.5-2.0)
- Large headlines → shorter line-height (1.0-1.2)
- Wider content → increase line-height (easier to track to next line)

### Line Length

Optimal: 45-75 characters per line (20-35em width). Wider = harder to read.

### Letter-Spacing

- Tighten for headlines (fonts optimized for body have wider spacing)
- Increase for ALL-CAPS text (improves readability)
- Trust the typeface designer for body text

### Alignment

- Default: Left-aligned
- Center: Only for headlines and short blocks (under 2-3 lines)
- Right-align numbers for column comparison
- Baseline-align mixed font sizes on a single line (not center)

---

## LAYOUT & SPACING

### Start with Too Much White Space

Start with generous spacing, then remove until satisfied. "A little too much" in isolation = "just enough" in context.

### Don't Fill the Whole Screen

1400px available doesn't mean use 1400px. If content needs 600px, use 600px.

### Grids Are Overrated

12-column grids cause more harm than good:

- Sidebars at 25% get too wide on large screens, too narrow on small
- Use fixed-width sidebar + flexible content area
- Use `max-width` on components, only shrink when necessary

### Avoid Ambiguous Spacing

More space **between** groups than **within** groups. Applies to:

- Form labels and inputs
- Section headings in articles
- List items and groups
- Horizontal component layouts

### Responsive Sizing

Large elements shrink faster than small elements across breakpoints. Don't use fixed ratios.

---

## DEPTH & MOTION

### Emulating Light

Light comes from above:

- **Raised elements** (buttons, cards): Lighter top edge + shadow below
- **Inset elements** (inputs, wells): Lighter bottom edge + shadow above

### Interactive Shadows

- Drag: Increase shadow (element pops above others)
- Press: Decrease shadow (pressed into surface)

### Flat Design with Depth

Depth without shadows: lighter than background = raised, darker = inset. Solid shadows (no blur) maintain flat aesthetic.

### Platform-Aware Depth (Mobile)

| Platform | Approach |
|----------|----------|
| **iOS** | Subtle shadows, blur/frosted glass, less elevation |
| **Android** | Material elevation system, layered surfaces |

### Motion

Focus on high-impact moments:

- One well-orchestrated page load with staggered reveals (`animation-delay`) > scattered micro-interactions
- Scroll-triggered animations and hover states that surprise
- Prioritize CSS-only solutions for web
- Use Motion library for React when available
- Match complexity to aesthetic: maximalist → elaborate effects, minimalist → subtle precision

---

## SPATIAL COMPOSITION

Create unexpected layouts:

- Asymmetry and overlap
- Diagonal flow and grid-breaking elements
- Generous negative space OR controlled density
- Cross-background elements for depth without shadows

---

## VISUAL DETAILS

Create atmosphere and depth rather than defaulting to solid colors:

- Gradient meshes, noise textures, geometric patterns
- Layered transparencies, dramatic shadows
- Decorative borders, custom cursors, grain overlays
- Accent borders on cards, nav items, alerts, headlines
- Background variety: subtle gradients (hues within 30°), repeating patterns, geometric shapes

---

## MOBILE-SPECIFIC PATTERNS

### Touch Targets

| Platform | Minimum | Comfortable |
|----------|---------|-------------|
| iOS | 44×44 pt | 48×48 pt |
| Android | 48×48 dp | 56×56 dp |

Add invisible padding if icon is smaller than minimum target.

### Safe Areas

Respect device notches and home indicators. Never place interactive elements in unsafe areas.

### Navigation Patterns

| Pattern | Use Case |
|---------|----------|
| Bottom navigation | 3-5 primary destinations, icons + labels |
| Tab bar (top) | Content categories |
| Navigation drawer | Many destinations |
| Bottom sheet | Contextual actions |

### Mobile Forms

- Full-width inputs with horizontal padding
- Label above or floating
- Helper text below, error state with red border + message
- Primary button: Full width at bottom or FAB

### Loading States

- Skeleton screens (preferred over spinners)
- Shimmer effect for content placeholders
- Pull to refresh for list content

### Empty States

Design empty states as first impressions:

- Illustration or icon to grab attention
- Clear title and helpful description
- Call-to-action button
- Hide irrelevant UI (tabs, filters that don't work yet)

---

## FINISHING TOUCHES

### Supercharge Defaults

- Bulleted lists → contextual icons (checkmarks, arrows, padlocks)
- Blockquotes → oversized quote marks with accent color
- Links → custom thick/colorful underlines
- Form controls → brand-colored selected states

### Fewer Borders

Too many borders = busy design. Alternatives:

- Box shadow: `0 0 0 1px rgba(0,0,0,0.05)`
- Different background colors for adjacent sections
- Extra spacing between elements

### Working with Images

- Text on images: Semi-transparent overlay, lower contrast, colorize, or text shadow
- Don't scale icons beyond intended size (16-24px) — enclose in shape instead
- User content: Fixed containers + `object-fit: cover` + subtle inner shadow

---

## ANTI-PATTERNS

| Anti-Pattern | Fix |
|-------------|-----|
| Generic fonts (Inter, Roboto, Arial, system-ui) | Choose distinctive, characterful typefaces |
| Purple gradients on white backgrounds | Commit to a cohesive, context-specific palette |
| Predictable layouts and component patterns | Use asymmetry, overlap, unexpected composition |
| Grey text on colored backgrounds | Hand-pick colors based on background hue |
| Filling the whole screen when content needs less | Use max-width, let content breathe |
| Ambiguous spacing between groups | More space between groups than within |
| Relative sizing across breakpoints | Large elements shrink faster than small ones |
| Touch targets under 44pt/48dp | Add invisible padding to meet minimums |
| Ignoring safe areas (notch, home indicator) | Respect platform safe area insets |
| Color as sole communication channel | Add icons, text, or patterns alongside color |
| Cookie-cutter design across generations | Every design must be unique and context-specific |
| Converging on common font/color choices | Vary themes, fonts, and aesthetics deliberately |

---

## Output Guidance

When generating frontend code, structure output as:

1. **Design direction** — 1-2 sentences on aesthetic approach and key decisions
2. **Code** — complete, working implementation (HTML/CSS/JS, React, Flutter, etc.)
3. **Responsive notes** — breakpoint behavior if applicable

Lead with code, not explanation. Show the design, don't describe it.

---

## REFERENCE FILES

For detailed guidance on specific topics, read `references/` when needed:

| Topic | File | When to Read |
|-------|------|--------------|
| Visual hierarchy deep-dive | [hierarchy.md](references/hierarchy.md) | Balancing element importance, label strategies |
| Color system details | [color-system.md](references/color-system.md) | HSL shade generation, accessibility, grey temperature |
| Mobile components | [mobile-components.md](references/mobile-components.md) | Navigation, lists, forms, feedback, loading patterns |
