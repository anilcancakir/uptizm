# Color System

Deep-dive into HSL color management, shade generation, and accessibility.

---

## HSL Components

- **Hue** (0-360°): Position on color wheel (0°=red, 120°=green, 240°=blue)
- **Saturation** (0-100%): How colorful (0%=grey, 100%=vibrant)
- **Lightness** (0-100%): How close to black/white (50%=pure color)

**HSL vs HSB:** Don't confuse them. In HSB, 100% brightness at 100% saturation is NOT white. Use HSL for web.

---

## Complete Palette Requirements

| Category | Shades | Purpose |
|----------|--------|---------|
| Greys | 8-10 | Text, backgrounds, panels, borders |
| Primary | 5-10 | Actions, active states, links |
| Accents | 5-10 each | Success, warning, error, feature highlights |

---

## Shade Definition Process

1. **Choose base color (500)**: Works as button background
2. **Find the edges**:
   - Darkest (900): Works for text on light background
   - Lightest (100): Works as tinted background
3. **Fill the gaps**:
   - First pass: 700, 300 (middle of gaps)
   - Second pass: 800, 600, 400, 200
4. **Result**: 9 shades (100-900), balanced, no more exhaustive decisions

**For greys:** Same process. Darkest for darkest text, lightest for subtle off-white.

---

## Saturation at Lightness Extremes

As lightness approaches 0% or 100%, saturation appears weaker.

**Solution:** Increase saturation as lightness moves away from 50%.

---

## Perceived Brightness by Hue

Different hues have different perceived brightness:

- **Bright hues**: Yellow (60°), Cyan (180°), Magenta (300°)
- **Dark hues**: Red (0°), Green (120°), Blue (240°)

**Adjusting brightness via hue:**

- To lighten: Rotate toward 60°, 180°, or 300°
- To darken: Rotate toward 0°, 120°, or 240°
- Don't rotate more than 20-30° (looks like a different color)

**Example:** Yellow palette → rotate toward orange as you decrease lightness. Darker shades feel warm and rich instead of dull brown.

---

## Grey Temperature

True grey has 0% saturation. Real "grey" palettes are often saturated.

- **Cool greys**: Saturate with blue
- **Warm greys**: Saturate with yellow/orange
- Increase saturation for lighter/darker shades too, or they look washed out compared to middle greys
- True black looks unnatural — start with really dark grey

---

## Accessibility

**WCAG contrast ratios:**

| Text Type | Minimum Ratio |
|-----------|--------------|
| Normal text (<18px) | 4.5:1 |
| Large text (18px+ bold, 24px+) | 3:1 |

**When white-on-color fails contrast:**

1. **Flip the contrast**: Dark colored text on light colored background (less in-your-face)
2. **Rotate the hue**: Rotate toward brighter perceived hue (cyan, magenta, yellow) to increase contrast while staying colorful

---

## Color Is Not Communication

Color blind users can't distinguish some colors.

- Metric cards with red/green: Add up/down arrow icons
- Graph lines: Use light/dark contrast instead of color-only
- **Rule**: Color supports what design already says — never be the only signal
