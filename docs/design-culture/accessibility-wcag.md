# Accessibility / WCAG (Flutter/Wind reference)

Read this for every screen. Accessibility is a constraint that applies regardless of design
language, and it is legally required in most markets. Target WCAG 2.2 Level AA.

## What to target

WCAG 2.2 AA is the baseline (EN 301 549 / EU Accessibility Act, ADA Title II). WCAG 3.0 is a
draft; do not design to APCA for compliance yet.

## Contrast (hard numbers)

- Body text: **4.5:1** against its background.
- Large text (>=24px, or >=18.66px bold): **3:1**.
- UI components and meaningful graphics (borders, icons, input outlines, focus rings): **3:1**
  against adjacent color.
- Check BOTH light and dark independently; a token that passes in light can fail in dark.

### design:lint enforcement

`dart run bin/dispatcher.dart design:lint` enforces the 4.5:1 ratio on every `on-X`/`X` role
pair defined in DESIGN.md. It implements the WCAG relative-luminance formula (sRGB channel
linearization, 4.5:1 ratio check) against both the light and dark hex values. A lint failure
means the token pair does not pass for normal body text.

The passing pairs in the current DESIGN.md:

- `text-on-primary` (#FFFFFF) on `bg-primary` (#7C3AED light): 5.7:1 (pass)
- `text-on-destructive` (#FFFFFF) on `bg-destructive` (#DC2626 light): 4.8:1 (pass)

If you update any color in DESIGN.md, re-run `design:lint` to verify the pair still passes in
both themes.

## Touch targets

- WCAG 2.5.8 (AA): interactive targets at least **24x24 logical px**, or enough spacing so a
  24px circle on each does not overlap a neighbor.
- Apple HIG and Material 3 ask for 44px and 48px respectively; those are the practical targets.
- In Wind className: `min-h-11` (44px) clears both Apple and the WCAG floor. Button `md`/`lg`
  already meet this. Keep custom controls at `min-h-11`.

## Focus visibility

- WCAG 2.4.11 (AA): a focused element must not be fully hidden by sticky headers or overlays.
  In Flutter: use `Scrollable.ensureVisible` or `ScrollController` to scroll focused elements
  into view when a sticky header is present.
- Focus ring: at least 2px perimeter with 3:1 contrast against the unfocused state.
- In Wind className: `focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2`
  provides a compliant focus ring. Apply it to every custom interactive widget.
- Never use a Flutter `FocusNode` that suppresses the default focus indicator without a
  visible replacement.

## Flutter Semantics

Flutter does not use HTML; screen readers (TalkBack, VoiceOver) read the Semantics tree. Every
rule below maps the HTML/ARIA concept to Flutter.

### Every interactive widget needs a Semantics label

```dart
// Icon-only button
Semantics(
  label: 'Close dialog',
  button: true,
  child: WButton(onPressed: onClose, child: Icon(Icons.close)),
)

// Image with meaning
Semantics(
  label: 'Profile photo for Jane Smith',
  image: true,
  child: CircleAvatar(backgroundImage: ...),
)

// Decorative image: excludeSemantics: true
ExcludeSemantics(child: decorativeIcon)
```

### Heading hierarchy

Use `Semantics(header: true)` on screen-level headings. Maintain one primary heading per screen.
Do not use heading markup for visual sizing; use `Typography` variants for visual size and
`Semantics(header: true)` for semantic role.

```dart
Semantics(
  header: true,
  child: Typography(variant: TypographyVariant.headlineLg, text: 'Profile settings'),
)
```

### Form fields

Every input must have a visible, persistent label. The `FormField` component handles label
association; do not rely on placeholder text as the label (it disappears on focus).

Error messages must be text, not color alone. Use the `FormField` `errorText` parameter; it
renders red text AND associates it with the field via `Semantics(liveRegion: true)`.

```dart
FormField(
  label: 'Email address',
  errorText: controller.errors['email'],
  child: Input(form: form, name: 'email'),
)
```

### Live regions for dynamic updates

Toast notifications and async status updates must announce themselves to screen readers:

```dart
Semantics(
  liveRegion: true,
  child: Toast(message: 'Profile saved successfully.'),
)
```

For urgent alerts use `Semantics(liveRegion: true, namesRoute: false)` with a high-priority
announcement. Do not use live regions for every state change; only for content the user would
otherwise miss.

### Navigation landmarks

Wrap the main content area in `Semantics(explicitChildNodes: true)` to preserve tree structure.
The `AppLayout` shell handles landmark-level semantics; do not add redundant wrappers.

## Color independence

Never convey information by color alone (WCAG 1.4.1). Pair every color signal with text, an
icon, or a pattern:

- An error input field gets `bg-destructive-container` border AND an error message text AND an
  error icon.
- A success badge has `bg-success` background AND a checkmark icon AND a label.
- Active nav items use `bg-primary-container` AND bold weight AND an active indicator line.

## Reduced motion

Respect the OS "Reduce Motion" setting:

```dart
// In a widget build method
final reduceMotion = MediaQuery.of(context).disableAnimations;

// In Wind className: motion-safe: prefix gates the animation
WButton(className: 'transition-colors motion-safe:active:scale-95 ...')
```

Under reduced motion: disable parallax, large translates/scales, looping autoplay, staggered
reveals, and spinner animations. Keep or substitute: opacity fades, color transitions. Do not
delete motion that conveys state; substitute a static equivalent.

No content flashes more than 3 times per second. Auto-playing motion over 5 seconds needs a
pause control.

## Dragging and gestures

Every drag interaction (sortable lists, sliders, swipe-to-dismiss) needs a single-pointer
non-drag alternative (WCAG 2.5.7 AA). For swipe-to-dismiss: also show a button or
long-press-menu option to trigger the same action.

## Design-time checklist

Before marking any screen done:

1. Text contrast >=4.5:1 (3:1 large), verified in both light and dark via `design:lint`.
2. UI/border/focus-ring contrast >=3:1.
3. No color-only signals: every state has a text or icon companion.
4. Touch targets >=44px (min-h-11) for all interactive elements.
5. Visible focus ring (`focus-visible:ring-2 focus-visible:ring-primary`) on every interactive
   widget; never obscured by sticky chrome.
6. One primary screen heading with `Semantics(header: true)`.
7. Every input has a visible label via `FormField`; errors are text + associated.
8. Decorative images have `ExcludeSemantics`; informative images have a `Semantics(label: ...)`.
9. All interactive elements keyboard/switch-accessible; no widget swallows focus without release.
10. Non-essential motion gated behind `motion-safe:` or `!MediaQuery.of(context).disableAnimations`.

## How to apply in this codebase

- Tokens pass WCAG: `text-fg` on `bg-surface`, `text-on-primary` on `bg-primary`,
  `text-on-destructive` on `bg-destructive` are designed to pass. Re-check any custom pair.
- `FormField` component handles label association and error text automatically.
- `WButton`, `WInput`, `WAnchor` ship with a focus-ring className. Keep it; never strip it.
- Run `dart run bin/dispatcher.dart design:lint` after every DESIGN.md color change.
- Use `ExcludeSemantics` for decorative icons; `Semantics(label: ...)` for meaningful ones.

## See also

- [DESIGN.md](../DESIGN.md): color values; run design:lint to verify pairs
- [refactoring-ui.md](refactoring-ui.md): color independence, hierarchy, empty states
- [motion-interaction.md](motion-interaction.md): reduced-motion patterns
- [wind-responsive.md](wind-responsive.md): touch targets, SafeArea, hit-target sizing

## Sources

- W3C WAI: WCAG 2.2 Recommendation (1.4.1, 1.4.3, 1.4.11, 2.1.1, 2.4.3, 2.4.7, 2.4.11, 2.5.7,
  2.5.8, 3.3.1-3.3.3).
- Flutter documentation: Semantics, ExcludeSemantics, MediaQuery.disableAnimations,
  FocusableActionDetector.
- EN 301 549 / EU Accessibility Act; ADA Title II.
