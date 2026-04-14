# Visual Hierarchy

Deep-dive into creating effective visual hierarchy in UI design.

---

## Text Color Hierarchy (3 Levels)

- **Primary**: Dark color for headlines, key content
- **Secondary**: Grey for supporting content, dates
- **Tertiary**: Light grey for metadata, copyright

**Font weight hierarchy:**

- **Normal** (400-500): Most text
- **Heavy** (600-700): Text to emphasize
- Never use weights under 400 for UI work

---

## Size Isn't Everything

Relying on font size alone leads to oversized primary and undersized secondary content.

**Better approaches:**

- Make primary element bolder (not just larger)
- Use softer color for supporting text (not tiny font size)
- Combine weight + color + size for clear hierarchy

---

## Grey Text on Colored Backgrounds

Grey on white reduces contrast, but grey on colored backgrounds looks washed out.

**Solution:** Hand-pick a color based on the background:

1. Choose same hue as background
2. Adjust saturation and lightness until readable
3. Avoid reducing opacity of white text (looks dull, shows background through)

---

## Emphasize by De-emphasizing

When the main element doesn't stand out, don't add more emphasis — de-emphasize competing elements.

**Example:** Active nav item not standing out?

- Don't make it bolder/brighter
- Make inactive items softer/lighter

**For larger sections:** Remove competing background colors, let content sit directly on page background.

---

## Labels Are a Last Resort

The label: value format makes hierarchy difficult — every piece gets equal emphasis.

**Strategies:**

1. **Skip the label**: Format often speaks for itself (email, phone, price)
2. **Combine labels and values**: "In stock: 12" → "12 left in stock"
3. **Labels are secondary**: De-emphasize with smaller size, lighter color, lighter weight
4. **When to emphasize labels**: Information-dense pages where users scan for labels (tech specs)

---

## Document vs Visual Hierarchy

`<h1>` doesn't have to be the biggest element. Section titles often act as labels — the content should be the focus, not the title.

---

## Balance Weight and Contrast

**Icons**: Visually "heavy" — give them softer colors to balance with text.

**Thin borders**: 1px can be too subtle. Increase to 2px instead of darkening (which looks harsh).

---

## Semantic vs Visual Button Hierarchy

Don't design buttons purely on semantics. Every action has a place in the hierarchy:

- **Primary**: Solid, high-contrast (one per section)
- **Secondary**: Outline or low-contrast fill
- **Tertiary**: Styled like links

**Destructive actions:** Big/red on confirmation dialogs. Tertiary on regular pages where delete is secondary.
